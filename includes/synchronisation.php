<?php
/**
 * Module : Synchronisation (v7.3 - Marquage produits supprimés)
 * 
 * MODIFICATION v7.3 :
 * - Ajout automatique des colonnes is_deleted et deleted_at
 * - Au lieu de SUPPRIMER les produits de la base
 * - Les MARQUER comme supprimés (is_deleted = 1, deleted_at = NOW())
 * - Si un produit revient dans l'API, il sera automatiquement réactivé (is_deleted = 0)
 * - 5 cartes statistiques (sans "Supprimés Ignorés")
 * 
 * Ce module contient TOUT le code :
 * - Vérification et création automatique des colonnes manquantes
 * - Table wp_imbretex_products
 * - Page Synchronisation avec 5 statistiques
 * - Synchronisation en ARRIÈRE-PLAN (background processing)
 * - Possibilité de changer de page pendant la synchronisation
 * - Progress bar et logs en temps réel via polling AJAX
 * - Pagination automatique pour récupérer TOUS les produits
 * - Comparaison intelligente avec l'endpoint /api/products/deleted
 * - Marquage automatique des produits supprimés
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// HOOK : VÉRIFIER LA TABLE AU CHARGEMENT DE L'ADMIN
// ============================================================
// Cette fonction s'exécute à chaque chargement de l'admin
// pour s'assurer que la table et les colonnes existent
// Utilise un transient pour ne vérifier qu'une fois par heure
add_action('admin_init', function() {
    $last_check = get_transient('api_table_columns_checked');
    
    if (!$last_check) {
        api_create_sync_table();
        // Vérifier seulement 1 fois par heure
        set_transient('api_table_columns_checked', true, HOUR_IN_SECONDS);
    }
}, 1);

// ============================================================
// CRÉATION DE LA TABLE PERSONNALISÉE
// ============================================================
function api_create_sync_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        sku varchar(100) NOT NULL,
        reference varchar(100) NOT NULL,
        name varchar(255) NOT NULL,
        brand varchar(100) DEFAULT NULL,
        category varchar(255) DEFAULT NULL,
        variants_count int(11) DEFAULT 1,
        price decimal(10,2) DEFAULT NULL,
        stock int(11) DEFAULT 0,
        image_url varchar(500) DEFAULT NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        synced_at datetime DEFAULT CURRENT_TIMESTAMP,
        product_data longtext NOT NULL,
        status varchar(20) DEFAULT 'new',
        imported tinyint(1) DEFAULT 0,
        wc_product_id bigint(20) DEFAULT NULL,
        is_deleted tinyint(1) DEFAULT 0,
        deleted_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY sku (sku),
        KEY status (status),
        KEY imported (imported),
        KEY synced_at (synced_at),
        KEY brand (brand),
        KEY category (category),
        KEY is_deleted (is_deleted)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // ============================================================
    // VÉRIFIER ET AJOUTER LES COLONNES MANQUANTES (is_deleted, deleted_at)
    // ============================================================
    // Cette section s'exécute après la création de la table
    // pour s'assurer que les colonnes existent même sur une table ancienne
    
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if ($table_exists) {
        // Vérifier si la colonne is_deleted existe
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND COLUMN_NAME = 'is_deleted'
        ");
        
        if (!$column_exists) {
            // Ajouter la colonne is_deleted
            $wpdb->query("
                ALTER TABLE $table_name 
                ADD COLUMN is_deleted tinyint(1) DEFAULT 0 AFTER wc_product_id
            ");
            error_log('API Imbretex - Colonne is_deleted ajoutée automatiquement');
        }
        
        // Vérifier si la colonne deleted_at existe
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND COLUMN_NAME = 'deleted_at'
        ");
        
        if (!$column_exists) {
            // Ajouter la colonne deleted_at
            $wpdb->query("
                ALTER TABLE $table_name 
                ADD COLUMN deleted_at datetime DEFAULT NULL AFTER is_deleted
            ");
            error_log('API Imbretex - Colonne deleted_at ajoutée automatiquement');
        }
        
        // Vérifier si l'index existe
        $index_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND INDEX_NAME = 'is_deleted'
        ");
        
        if (!$index_exists) {
            // Ajouter l'index
            $wpdb->query("
                ALTER TABLE $table_name 
                ADD KEY is_deleted (is_deleted)
            ");
            error_log('API Imbretex - Index is_deleted ajouté automatiquement');
        }
    }
}

// ============================================================
// FONCTIONS DE GESTION DE LA TABLE
// ============================================================

// Insérer ou mettre à jour un produit
function api_db_upsert_product($product_data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $variant = $product_data['variants'][0] ?? null;
    if (!$variant) return false;
    
    $is_variable = count($product_data['variants']) > 1;
    
    if ($is_variable) {
        $main_reference = $product_data['reference'] ?? $variant['variantReference'];
    } else {
        $main_reference = $variant['variantReference'] ?? $product_data['reference'];
    }
    
    // Extraire la catégorie
    $category = 'Autres';
    if (!empty($variant['categories']) && is_array($variant['categories'])) {
        $first_cat = $variant['categories'][0];
        if (isset($first_cat['categories']['fr'])) {
            $category = $first_cat['categories']['fr'];
        } elseif (isset($first_cat['families']['fr'])) {
            $category = $first_cat['families']['fr'];
        }
    }
    
    // Récupérer prix et stock
    $price_stock = api_get_product_price_stock($main_reference);
    $price = isset($price_stock['price']) ? floatval($price_stock['price']) : 0;
    $stock = 0;
    if (isset($price_stock['stock'])) {
        $stock += intval($price_stock['stock']);
    }
    if (isset($price_stock['stock_supplier'])) {
        $stock += intval($price_stock['stock_supplier']);
    }
    
    // Récupérer l'image
    $image_url = '';
    if (!empty($variant['images']) && is_array($variant['images'])) {
        $first_image = $variant['images'][0];
        if (is_string($first_image)) {
            $image_url = $first_image;
        } elseif (is_array($first_image) && isset($first_image['url'])) {
            $image_url = $first_image['url'];
        }
    }
    
    // Vérifier si le produit existe déjà
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE sku = %s",
        $main_reference
    ));
    
    $data = [
        'sku' => $main_reference,
        'reference' => $main_reference,
        'name' => $variant['title']['fr'] ?? $main_reference,
        'brand' => $product_data['brands']['name'] ?? '',
        'category' => $category,
        'variants_count' => count($product_data['variants']),
        'price' => $price,
        'stock' => $stock,
        'image_url' => $image_url,
        'created_at' => $product_data['createdAt'],
        'updated_at' => $product_data['updatedAt'],
        'synced_at' => current_time('mysql'),
        'product_data' => json_encode($product_data),
        'status' => $existing ? 'updated' : 'new',
        'is_deleted' => 0,
        'deleted_at' => null
    ];
    
    if ($existing) {
        $wpdb->update(
            $table_name,
            $data,
            ['sku' => $main_reference],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
            ['%s']
        );
        return $existing->id;
    } else {
        $wpdb->insert(
            $table_name,
            $data,
            ['%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
        return $wpdb->insert_id;
    }
}

// Compter les produits avec filtres
function api_db_count_products($filters = []) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $where = ['1=1'];
    $params = [];
    
    if (!empty($filters['status'])) {
        $where[] = 'status = %s';
        $params[] = $filters['status'];
    }
    
    if (isset($filters['imported'])) {
        $where[] = 'imported = %d';
        $params[] = $filters['imported'];
    }
    
    if (isset($filters['is_deleted'])) {
        $where[] = 'is_deleted = %d';
        $params[] = $filters['is_deleted'];
    }
    
    $where_clause = implode(' AND ', $where);
    $query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
    
    if (!empty($params)) {
        $query = $wpdb->prepare($query, $params);
    }
    
    return (int) $wpdb->get_var($query);
}

// Vider complètement la table
function api_db_truncate_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    return $wpdb->query("TRUNCATE TABLE $table_name");
}

// Marquer les produits comme supprimés (is_deleted = 1)
function api_db_mark_products_as_deleted($skus_to_mark) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    if (empty($skus_to_mark) || !is_array($skus_to_mark)) {
        return 0;
    }
    
    // Diviser en lots de 500 pour éviter les problèmes SQL
    $chunks = array_chunk($skus_to_mark, 500);
    $total_marked = 0;
    
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
        
        $query = $wpdb->prepare(
            "UPDATE $table_name 
             SET is_deleted = 1, deleted_at = NOW() 
             WHERE sku IN ($placeholders) AND is_deleted = 0",
            $chunk
        );
        
        $marked = $wpdb->query($query);
        if ($marked !== false) {
            $total_marked += $marked;
        }
    }
    
    return $total_marked;
}

// Récupérer un produit par SKU
function api_db_get_product_by_sku($sku) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE sku = %s",
        $sku
    ));
}

// ============================================================
// FONCTIONS API POUR LA SYNCHRONISATION
// ============================================================

// Récupérer une page de produits avec pagination
function api_fetch_products_page($since_created = null, $since_updated = null, $per_page = 50, $page = 1) {
    $api_url = API_BASE_URL . '/api/products/products';
    $per_page = min($per_page, 50);
    
    $params = ['perPage' => $per_page, 'page' => $page];
    if ($since_created) $params['sinceCreated'] = $since_created;
    if ($since_updated) $params['sinceUpdated'] = $since_updated;

    $full_url = $api_url . '?' . http_build_query($params);

    $response = wp_remote_get($full_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . API_TOKEN,
            'Accept' => 'application/json'
        ],
        'timeout' => 60
    ]);

    if (is_wp_error($response)) {
        return ['products' => [], 'total' => 0];
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return ['products' => [], 'total' => 0];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['products']) || empty($data['products'])) {
        return ['products' => [], 'total' => 0];
    }

    $products = [];
    $total_received = count($data['products']);
    
    foreach ($data['products'] as $product_api) {
        $variant = $product_api['variants'][0] ?? null;
        if (!$variant) continue;

        $is_variable = count($product_api['variants']) > 1;
        
        if ($is_variable) {
            $main_reference = $product_api['reference'] ?? $variant['variantReference'];
        } else {
            $main_reference = $variant['variantReference'] ?? $product_api['reference'];
        }

        $products[] = [
            'sku' => $main_reference,
            'reference' => $main_reference,
            'name' => $variant['title']['fr'] ?? $main_reference,
            'brand' => $product_api['brands']['name'] ?? '',
            'created_at' => $product_api['createdAt'],
            'updated_at' => $product_api['updatedAt'],
            'product_data' => $product_api
        ];
    }

    return [
        'products' => $products,
        'total' => $total_received
    ];
}

// Récupérer TOUS les produits supprimés (avec pagination)
function api_fetch_all_deleted_products() {
    $url = API_BASE_URL . '/api/products/deleted';
    $all_deleted = [];
    $page = 1;
    
    do {
        $params = ['perPage' => 50, 'page' => $page];
        $full_url = $url . '?' . http_build_query($params);
        
        $response = wp_remote_get($full_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . API_TOKEN,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            break;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            break;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data)) {
            break;
        }

        // Extraire les références
        foreach ($data as $deleted_product) {
            if (isset($deleted_product['reference'])) {
                $all_deleted[] = $deleted_product['reference'];
            }
            if (isset($deleted_product['supplierReference'])) {
                $all_deleted[] = $deleted_product['supplierReference'];
            }
            if (isset($deleted_product['variants']) && is_array($deleted_product['variants'])) {
                foreach ($deleted_product['variants'] as $variant) {
                    if (isset($variant['variantReference'])) {
                        $all_deleted[] = $variant['variantReference'];
                    }
                }
            }
        }

        if (count($data) < 50) {
            break;
        }
        
        $page++;
        
        if ($page > 1000) {
            break;
        }
        
    } while (true);
    
    return array_unique($all_deleted);
}

// ============================================================
// GESTION DE L'ÉTAT DE LA SYNCHRONISATION
// ============================================================

function api_get_sync_status() {
    return get_transient('api_sync_status');
}

function api_update_sync_status($data) {
    set_transient('api_sync_status', $data, HOUR_IN_SECONDS);
}

function api_reset_sync_status() {
    delete_transient('api_sync_status');
}

function api_add_sync_log($message, $type = 'info') {
    $logs = get_transient('api_sync_logs') ?: [];
    $logs[] = [
        'time' => current_time('H:i:s'),
        'message' => $message,
        'type' => $type
    ];
    
    if (count($logs) > 100) {
        $logs = array_slice($logs, -100);
    }
    
    set_transient('api_sync_logs', $logs, HOUR_IN_SECONDS);
}

function api_get_sync_logs() {
    return get_transient('api_sync_logs') ?: [];
}

// ============================================================
// PROCESSUS DE SYNCHRONISATION EN ARRIÈRE-PLAN
// ============================================================

function api_process_sync_page($page, $since_created = null, $since_updated = null) {
    // Augmenter la limite de temps pour éviter les timeouts
    set_time_limit(300); // 5 minutes
    
    $status = api_get_sync_status();
    
    if (!$status || $status['status'] !== 'running') {
        return false;
    }
    
    $deleted_list = [];
    $db_marked_count = 0;
    
    if ($page === 1) {
        api_add_sync_log('📥 Récupération de la liste des produits supprimés...', 'info');
        $deleted_list = api_fetch_all_deleted_products();
        api_add_sync_log('✓ ' . count($deleted_list) . ' références supprimées récupérées', 'success');
        
        if (!empty($deleted_list)) {
            api_add_sync_log('🏷️ Marquage des produits supprimés dans la base...', 'info');
            $db_marked_count = api_db_mark_products_as_deleted($deleted_list);
            
            if ($db_marked_count > 0) {
                api_add_sync_log("✓ $db_marked_count produits marqués comme supprimés", 'success');
            } else {
                api_add_sync_log('✓ Aucun produit à marquer dans la base', 'info');
            }
        }
        
        $status['deleted_list'] = $deleted_list;
        $status['db_marked_count'] = $db_marked_count;
        api_update_sync_status($status);
    } else {
        $deleted_list = $status['deleted_list'] ?? [];
        $db_marked_count = $status['db_marked_count'] ?? 0;
    }
    
    $result = api_fetch_products_page($since_created, $since_updated, 50, $page);
    $products = $result['products'];
    $total_received = $result['total'];
    
    $new_count = 0;
    $updated_count = 0;
    $saved_count = 0;
    $skipped_count = 0;
    
    foreach ($products as $product) {
        $is_deleted = in_array($product['sku'], $deleted_list) || 
                      in_array($product['reference'], $deleted_list);
        
        if ($is_deleted) {
            $skipped_count++;
            continue;
        }
        
        $id = api_db_upsert_product($product['product_data']);
        if ($id) {
            $saved_count++;
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'imbretex_products';
            $product_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $table_name WHERE id = %d",
                $id
            ));
            
            if ($product_status === 'new') {
                $new_count++;
            } else {
                $updated_count++;
            }
        }
    }
    
    $status['current_page'] = $page;
    $status['total_fetched'] += $saved_count;
    $status['total_new'] += $new_count;
    $status['total_updated'] += $updated_count;
    $status['db_marked_count'] = $db_marked_count;
    $status['last_update'] = current_time('mysql');
    
    $log_msg = "Page $page: $saved_count produits sauvegardés";
    if ($skipped_count > 0) {
        $log_msg .= ", $skipped_count ignorés (supprimés API)";
    }
    api_add_sync_log($log_msg, 'success');
    
    $has_more = $total_received === 50;
    
    if ($has_more) {
        $status['has_more'] = true;
        $status['next_page'] = $page + 1; // Stocker la page suivante
        api_update_sync_status($status);
        
        // NE PLUS utiliser wp_schedule_single_event
        // La page suivante sera appelée par AJAX
    } else {
        $status['status'] = 'completed';
        $status['has_more'] = false;
        $status['completed_at'] = current_time('mysql');
        
        unset($status['deleted_list']);
        api_update_sync_status($status);
        
        update_option('api_sync_db_marked_count', $db_marked_count);
        
        api_add_sync_log('═════════════════════════════════', 'info');
        api_add_sync_log('✓ SYNCHRONISATION TERMINÉE !', 'success');
        api_add_sync_log("✓ Total sauvegardés: {$status['total_fetched']}", 'success');
        api_add_sync_log("✓ Nouveaux: {$status['total_new']}", 'success');
        api_add_sync_log("✓ Mis à jour: {$status['total_updated']}", 'success');
        
        if ($db_marked_count > 0) {
            api_add_sync_log("🏷️ Marqués comme supprimés: $db_marked_count", 'info');
        }
    }
    
    return true;
}

// NOUVELLE ACTION AJAX : Traiter la page suivante
add_action('wp_ajax_api_process_next_sync_page', function() {
    $status = api_get_sync_status();
    
    if (!$status || $status['status'] !== 'running') {
        wp_send_json_error(['message' => 'Aucune synchronisation en cours']);
        return;
    }
    
    $next_page = $status['next_page'] ?? $status['current_page'] + 1;
    $since_created = $status['since_created'] ?? null;
    $since_updated = $status['since_updated'] ?? null;
    
    // Traiter la page suivante
    api_process_sync_page($next_page, $since_created, $since_updated);
    
    // Récupérer le statut mis à jour
    $updated_status = api_get_sync_status();
    
    wp_send_json_success([
        'status' => $updated_status,
        'has_more' => $updated_status['has_more'] ?? false
    ]);
});

// ============================================================
// PAGE : SYNCHRONISATION
// ============================================================
function api_sync_page() {
    // Statistiques - 5 CARTES
    $total = api_db_count_products(['is_deleted' => 0]);
    $new = api_db_count_products(['status' => 'new', 'imported' => 0, 'is_deleted' => 0]);
    $updated = api_db_count_products(['status' => 'updated', 'imported' => 0, 'is_deleted' => 0]);
    $imported = api_db_count_products(['imported' => 1, 'is_deleted' => 0]);
    $not_imported = api_db_count_products(['imported' => 0, 'is_deleted' => 0]);
    $marked_count = api_db_count_products(['is_deleted' => 1]);
    
    $sync_status = api_get_sync_status();
    $is_running = $sync_status && $sync_status['status'] === 'running';
    
    ?>
    <div class="wrap">
        <h1>🔄 Synchronisation API Imbretex</h1>
        
        <?php if ($is_running): ?>
        <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:20px 0;">
            <strong>⚠️ Synchronisation en cours...</strong>
            <p style="margin:5px 0 0 0;">
                Une synchronisation est en cours d'exécution en arrière-plan. Vous pouvez quitter cette page et revenir plus tard.
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Statistiques - GRILLE DE 5 CARTES -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin:20px 0;">
            <!-- Carte 1 : Total Actifs -->
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #2271b1;">
                <h3 style="margin:0 0 10px 0;color:#2271b1;font-size:14px;">📦 Produits Actifs</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($total); ?></p>
                <small>Dans la base (non supprimés)</small>
            </div>
            
            <!-- Carte 2 : Importés -->
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #46b450;">
                <h3 style="margin:0 0 10px 0;color:#46b450;font-size:14px;">✓ Importés en WC</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($imported); ?></p>
                <small>Déjà créés dans WooCommerce</small>
            </div>
            
            <!-- Carte 3 : À Importer -->
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #dc3232;">
                <h3 style="margin:0 0 10px 0;color:#dc3232;font-size:14px;">⏳ À Importer</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($not_imported); ?></p>
                <small><?php echo number_format($new); ?> nouveaux, <?php echo number_format($updated); ?> MAJ</small>
            </div>
            
            <!-- Carte 4 : Marqués Supprimés -->
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #826eb4;">
                <h3 style="margin:0 0 10px 0;color:#826eb4;font-size:14px;">🏷️ Marqués Supprimés</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($marked_count); ?></p>
                <small>Produits marqués is_deleted=1</small>
            </div>
            
            <!-- Carte 5 : Total Base -->
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #50575e;">
                <h3 style="margin:0 0 10px 0;color:#50575e;font-size:14px;">📊 Total Base</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($total + $marked_count); ?></p>
                <small>Actifs + Supprimés</small>
            </div>
        </div>
        
        <!-- Zone de synchronisation -->
        <div style="background:#fff;padding:20px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h2>⚙️ Synchronisation en Arrière-Plan</h2>
            
            <button type="button" id="start-sync" class="button button-primary button-large" style="font-size:16px;padding:10px 30px;" <?php echo $is_running ? 'disabled' : ''; ?>>
                <?php echo $is_running ? '⏳ Synchronisation en cours...' : '🔄 Synchroniser TOUS les Produits'; ?>
            </button>
            
            <?php if ($is_running): ?>
            <button type="button" id="cancel-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">
                ⛔ Annuler la Synchronisation
            </button>
            <?php endif; ?>
            
            <button type="button" id="clear-all" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;" <?php echo $is_running ? 'disabled' : ''; ?>>
                🗑️ Vider la Table
            </button>
            
            <!-- Progress bar -->
            <div id="sync-progress-container" style="<?php echo $is_running ? '' : 'display:none;'; ?>margin-top:20px;">
                <div style="background:#f0f0f0;border-radius:5px;overflow:hidden;height:30px;position:relative;">
                    <div id="sync-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;"></div>
                    <span id="sync-progress-text" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-weight:bold;color:#333;">0%</span>
                </div>
                <p id="sync-status" style="margin-top:10px;font-weight:600;"></p>
            </div>
        </div>
        
        <!-- Logs de synchronisation -->
        <div style="background:#fff;padding:20px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h2>📋 Logs de Synchronisation</h2>
            <div id="sync-logs" style="background:#f9f9f9;padding:15px;border:1px solid #ddd;border-radius:3px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:12px;">
                <p style="color:#999;">En attente de synchronisation...</p>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($){
        var pollingInterval = null;
        var isRunning = <?php echo $is_running ? 'true' : 'false'; ?>;
        
        // Fonction pour mettre à jour l'interface avec l'état de la sync
        function updateUI(status, logs) {
            if (!status) {
                $('#sync-progress-container').hide();
                $('#sync-logs').html('<p style="color:#999;">En attente de synchronisation...</p>');
                return;
            }
            
            $('#sync-progress-container').show();
            
            var progress = Math.min(95, status.current_page * 2);
            if (status.status === 'completed') {
                progress = 100;
            }
            
            $('#sync-progress-bar').css('width', progress + '%');
            $('#sync-progress-text').text(progress + '%');
            
            var statusText = 'Page ' + status.current_page + ' traitée - Total: ' + status.total_fetched + ' produits';
            if (status.status === 'completed') {
                statusText = '✓ Synchronisation terminée ! ' + status.total_fetched + ' produits traités';
                $('#sync-status').css('color', '#46b450');
                
                if (pollingInterval) {
                    clearInterval(pollingInterval);
                    pollingInterval = null;
                }
                
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
            $('#sync-status').text(statusText);
            
            if (logs && logs.length > 0) {
                var logsHtml = '';
                logs.forEach(function(log) {
                    var color = log.type === 'success' ? '#46b450' : (log.type === 'error' ? '#dc3232' : '#333');
                    logsHtml += '<p style="color:' + color + ';margin:5px 0;">[' + log.time + '] ' + log.message + '</p>';
                });
                $('#sync-logs').html(logsHtml);
                $('#sync-logs').scrollTop($('#sync-logs')[0].scrollHeight);
            }
        }
        
        function pollSyncStatus() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_get_sync_status'
                },
                success: function(response) {
                    if (response.success) {
                        updateUI(response.data.status, response.data.logs);
                        
                        if (response.data.status && response.data.status.status === 'completed') {
                            if (pollingInterval) {
                                clearInterval(pollingInterval);
                                pollingInterval = null;
                            }
                        }
                    }
                }
            });
        }
        
        if (isRunning) {
            pollSyncStatus();
            pollingInterval = setInterval(pollSyncStatus, 3000);
        }
        
        // Fonction pour traiter un lot de pages
        function processBatch() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_process_sync_batch'
                },
                timeout: 150000, // 2.5 minutes de timeout
                success: function(response) {
                    if (response.success) {
                        // Mettre à jour l'UI
                        pollSyncStatus();
                        
                        // Si pas terminé et il y a plus de pages, relancer automatiquement
                        if (!response.data.completed && response.data.has_more) {
                            console.log('Traitement lot suivant...');
                            setTimeout(processBatch, 500); // Petite pause de 0.5s entre les lots
                        } else {
                            console.log('Synchronisation terminée !');
                            // Le polling va détecter la fin
                        }
                    } else {
                        console.error('Erreur traitement lot:', response.data.message);
                        api_add_sync_log('❌ Erreur: ' + response.data.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erreur AJAX lot:', error);
                    // Réessayer après 2 secondes en cas d'erreur
                    setTimeout(processBatch, 2000);
                }
            });
        }
        
        // Lancer la synchronisation
        $('#start-sync').on('click', function() {
            var $btn = $(this);
            
            $btn.prop('disabled', true).text('⏳ Démarrage...');
            $('#sync-progress-container').show();
            $('#sync-status').text('Démarrage de la synchronisation...').css('color', '#2271b1');
            
            // Démarrer le polling
            isRunning = true;
            pollingInterval = setInterval(pollSyncStatus, 2000);
            
            // Démarrer la synchronisation
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_start_background_sync'
                },
                success: function(response) {
                    if (response.success) {
                        $btn.text('⏳ Synchronisation en cours...');
                        
                        // Afficher le bouton annuler
                        var cancelBtn = '<button type="button" id="cancel-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">⛔ Annuler la Synchronisation</button>';
                        $btn.after(cancelBtn);
                        
                        // Démarrer le traitement par lots après une petite pause
                        setTimeout(processBatch, 1000);
                    } else {
                        alert('Erreur: ' + (response.data.message || 'Impossible de démarrer la synchronisation'));
                        $btn.prop('disabled', false).text('🔄 Synchroniser TOUS les Produits');
                        if (pollingInterval) {
                            clearInterval(pollingInterval);
                            pollingInterval = null;
                        }
                    }
                },
                error: function() {
                    alert('Erreur réseau');
                    $btn.prop('disabled', false).text('🔄 Synchroniser TOUS les Produits');
                    if (pollingInterval) {
                        clearInterval(pollingInterval);
                        pollingInterval = null;
                    }
                }
            });
        });
        
        $(document).on('click', '#cancel-sync', function() {
            if (!confirm('Êtes-vous sûr de vouloir annuler la synchronisation en cours ?')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_cancel_sync'
                },
                success: function(response) {
                    if (response.success) {
                        if (pollingInterval) {
                            clearInterval(pollingInterval);
                            pollingInterval = null;
                        }
                        location.reload();
                    }
                }
            });
        });
        
        $('#clear-all').on('click', function() {
            if (isRunning) {
                alert('⚠️ Impossible de vider la table pendant une synchronisation en cours.');
                return;
            }
            
            if (!confirm('⚠️ ATTENTION : Êtes-vous sûr de vouloir vider complètement la table ?\n\nCette action est irréversible et supprimera tous les produits synchronisés de la base de données (les produits WooCommerce ne seront pas affectés).')) {
                return;
            }
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('⏳ Suppression...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_clear_table'
                },
                success: function(response) {
                    if (response.success) {
                        alert('✓ Table vidée avec succès !');
                        location.reload();
                    } else {
                        alert('✗ Erreur : ' + (response.data.message || 'Erreur inconnue'));
                        $btn.prop('disabled', false).text('🗑️ Vider la Table');
                    }
                }
            });
        });
    });
    </script>
    <?php
}

// ============================================================
// ACTIONS AJAX
// ============================================================

add_action('wp_ajax_api_start_background_sync', function() {
    // Augmenter les limites mais rester raisonnable
    set_time_limit(120); // 2 minutes max par lot
    
    $current_status = api_get_sync_status();
    if ($current_status && $current_status['status'] === 'running') {
        wp_send_json_error(['message' => 'Une synchronisation est déjà en cours']);
        return;
    }
    
    delete_transient('api_sync_logs');
    
    $status = [
        'status' => 'running',
        'started_at' => current_time('mysql'),
        'current_page' => 0,
        'total_fetched' => 0,
        'total_new' => 0,
        'total_updated' => 0,
        'since_created' => null,
        'since_updated' => null,
        'has_more' => true
    ];
    
    api_update_sync_status($status);
    
    api_add_sync_log('🚀 Démarrage de la synchronisation complète...', 'info');
    api_add_sync_log('✅ Traitement automatique par lots...', 'info');
    api_add_sync_log('─────────────────────────────────', 'info');
    
    // Traiter la première page pour démarrer
    api_process_sync_page(1, null, null);
    
    wp_send_json_success(['message' => 'Synchronisation démarrée']);
});

// NOUVELLE ACTION : Traiter un lot de pages
add_action('wp_ajax_api_process_sync_batch', function() {
    set_time_limit(120); // 2 minutes par lot
    
    $status = api_get_sync_status();
    
    if (!$status || $status['status'] !== 'running') {
        wp_send_json_error(['message' => 'Aucune synchronisation en cours']);
        return;
    }
    
    // Traiter 5 pages par lot
    $pages_per_batch = 5;
    $start_page = ($status['current_page'] ?? 0) + 1;
    
    for ($i = 0; $i < $pages_per_batch; $i++) {
        $page = $start_page + $i;
        
        api_process_sync_page($page, $status['since_created'], $status['since_updated']);
        
        // Récupérer le statut mis à jour
        $status = api_get_sync_status();
        
        // Si terminé ou plus de pages, arrêter
        if (!$status['has_more'] || $status['status'] === 'completed') {
            break;
        }
        
        // Petite pause entre chaque page
        usleep(100000); // 0.1 seconde
    }
    
    // Récupérer le statut final
    $final_status = api_get_sync_status();
    
    wp_send_json_success([
        'status' => $final_status,
        'has_more' => $final_status['has_more'] ?? false,
        'completed' => $final_status['status'] === 'completed'
    ]);
});

add_action('wp_ajax_api_get_sync_status', function() {
    $status = api_get_sync_status();
    $logs = api_get_sync_logs();
    
    wp_send_json_success([
        'status' => $status,
        'logs' => $logs
    ]);
});

add_action('wp_ajax_api_cancel_sync', function() {
    api_reset_sync_status();
    delete_transient('api_sync_logs');
    
    api_add_sync_log('⛔ Synchronisation annulée par l\'utilisateur', 'error');
    
    wp_send_json_success();
});

add_action('wp_ajax_api_clear_table', function() {
    $result = api_db_truncate_table();
    
    if ($result !== false) {
        delete_option('api_sync_db_marked_count');
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => 'Erreur lors de la suppression']);
    }
});