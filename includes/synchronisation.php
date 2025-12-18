<?php
/**
 * Module : Synchronisation (v8.1 - Cron 10min + Sync manuelle incrémentale)
 * 
 * MODIFICATION v8.1 :
 * - Cron toutes les 10 minutes (au lieu de 5)
 * - Bouton de synchronisation manuelle lance une sync incrémentale
 * - Utilise sinceCreatedAt et sinceUpdatedAt de la dernière sync complète
 * - Suppression du mode "synchronisation complète" manuel
 * 
 * MODIFICATION v8.0 :
 * - Nouvelle table pour stocker l'état de synchronisation persistant
 * - Système de reprise automatique à partir de la page d'arrêt
 * - Distinction entre synchronisation initiale (manuelle) et incrémentale (cron)
 * - Cron quotidien avec sinceCreatedAt et sinceUpdatedAt
 * - Bouton "Reprendre" si une sync est interrompue
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// HOOK : VÉRIFIER LES TABLES AU CHARGEMENT DE L'ADMIN
// ============================================================
add_action('admin_init', function() {
    $last_check = get_transient('api_table_columns_checked');
    
    if (!$last_check) {
        api_create_sync_table();
        api_create_sync_state_table();
        set_transient('api_table_columns_checked', true, HOUR_IN_SECONDS);
    }
}, 1);

// ============================================================
// CRÉATION DE LA TABLE PERSONNALISÉE (PRODUITS)
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
    
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if ($table_exists) {
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND COLUMN_NAME = 'is_deleted'
        ");
        
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN is_deleted tinyint(1) DEFAULT 0 AFTER wc_product_id");
            error_log('API Imbretex - Colonne is_deleted ajoutée automatiquement');
        }
        
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND COLUMN_NAME = 'deleted_at'
        ");
        
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN deleted_at datetime DEFAULT NULL AFTER is_deleted");
            error_log('API Imbretex - Colonne deleted_at ajoutée automatiquement');
        }
        
        $index_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND INDEX_NAME = 'is_deleted'
        ");
        
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY is_deleted (is_deleted)");
            error_log('API Imbretex - Index is_deleted ajouté automatiquement');
        }
    }
}

// ============================================================
// CRÉATION DE LA TABLE D'ÉTAT DE SYNCHRONISATION
// ============================================================
function api_create_sync_state_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        sync_type varchar(50) NOT NULL DEFAULT 'full',
        status varchar(50) NOT NULL DEFAULT 'pending',
        total_products int(11) DEFAULT 0,
        total_pages int(11) DEFAULT 0,
        current_page int(11) DEFAULT 0,
        products_imported int(11) DEFAULT 0,
        products_new int(11) DEFAULT 0,
        products_updated int(11) DEFAULT 0,
        products_skipped int(11) DEFAULT 0,
        last_sync_completed datetime DEFAULT NULL,
        last_product_created_at datetime DEFAULT NULL,
        last_product_updated_at datetime DEFAULT NULL,
        started_at datetime DEFAULT NULL,
        stopped_at datetime DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        is_initial_sync tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY sync_type (sync_type)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// ============================================================
// FONCTIONS DE GESTION DE L'ÉTAT DE SYNCHRONISATION (TABLE)
// ============================================================

/**
 * Récupérer l'état de synchronisation actuel ou le dernier
 */
function api_get_persistent_sync_state() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    // Récupérer la dernière entrée
    $state = $wpdb->get_row("SELECT * FROM $table_name ORDER BY id DESC LIMIT 1", ARRAY_A);
    
    return $state;
}

/**
 * Récupérer un état de synchronisation en cours (running ou paused)
 */
function api_get_active_sync_state() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $state = $wpdb->get_row(
        "SELECT * FROM $table_name WHERE status IN ('running', 'paused', 'error') ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );
    
    return $state;
}

/**
 * Récupérer la dernière synchronisation complète réussie
 */
function api_get_last_completed_sync() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $state = $wpdb->get_row(
        "SELECT * FROM $table_name WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1",
        ARRAY_A
    );
    
    return $state;
}

/**
 * Vérifier si une synchronisation initiale a été complétée
 */
function api_has_completed_initial_sync() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $count = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE is_initial_sync = 1 AND status = 'completed'"
    );
    
    return $count > 0;
}

/**
 * Créer une nouvelle entrée d'état de synchronisation
 */
function api_create_sync_state($data = []) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $defaults = [
        'sync_type' => 'full',
        'status' => 'pending',
        'total_products' => 0,
        'total_pages' => 0,
        'current_page' => 0,
        'products_imported' => 0,
        'products_new' => 0,
        'products_updated' => 0,
        'products_skipped' => 0,
        'started_at' => current_time('mysql'),
        'is_initial_sync' => !api_has_completed_initial_sync() ? 1 : 0
    ];
    
    $data = wp_parse_args($data, $defaults);
    
    $wpdb->insert($table_name, $data);
    
    return $wpdb->insert_id;
}

/**
 * Mettre à jour l'état de synchronisation
 */
function api_update_sync_state($id, $data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $data['updated_at'] = current_time('mysql');
    
    return $wpdb->update($table_name, $data, ['id' => $id]);
}

/**
 * Marquer une synchronisation comme interrompue (pause/annulation)
 */
function api_pause_sync_state($id, $reason = 'user_cancelled') {
    return api_update_sync_state($id, [
        'status' => 'paused',
        'stopped_at' => current_time('mysql'),
        'error_message' => $reason
    ]);
}

/**
 * Marquer une synchronisation comme en erreur
 */
function api_error_sync_state($id, $error_message) {
    return api_update_sync_state($id, [
        'status' => 'error',
        'stopped_at' => current_time('mysql'),
        'error_message' => $error_message
    ]);
}

/**
 * Marquer une synchronisation comme terminée
 */
function api_complete_sync_state($id, $last_created_at = null, $last_updated_at = null) {
    $data = [
        'status' => 'completed',
        'completed_at' => current_time('mysql'),
        'last_sync_completed' => current_time('mysql')
    ];
    
    if ($last_created_at) {
        $data['last_product_created_at'] = $last_created_at;
    }
    if ($last_updated_at) {
        $data['last_product_updated_at'] = $last_updated_at;
    }
    
    return api_update_sync_state($id, $data);
}

/**
 * Réinitialiser complètement l'état de synchronisation
 */
function api_reset_all_sync_states() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    return $wpdb->query("TRUNCATE TABLE $table_name");
}

// ============================================================
// FONCTIONS DE GESTION DE LA TABLE PRODUITS
// ============================================================

/* function api_db_upsert_product($product_data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $variant = $product_data['variants'][0] ?? null;
    if (!$variant) return false;
    
    $is_variable = count($product_data['variants']) > 1;
    
    if ($is_variable) {
        error_log("API Imbretex - Produit variable détecté pour référence {$product_data['reference']}");
        error_log("API Imbretex - Référence variable utilisée : {$variant['variantReference']}");
        $main_reference = $variant['variantReference'];
    } else {
        $main_reference = $variant['variantReference'] ?? $product_data['reference'];
    }
    
    $category = 'Autres';
    if (!empty($variant['categories']) && is_array($variant['categories'])) {
        $first_cat = $variant['categories'][0];
        if (isset($first_cat['categories']['fr'])) {
            $category = $first_cat['categories']['fr'];
        } elseif (isset($first_cat['families']['fr'])) {
            $category = $first_cat['families']['fr'];
        }
    }
    
    $price_stock = api_get_product_price_stock($main_reference);
    $price = isset($price_stock['price']) ? floatval($price_stock['price']) : 0;
    error_log("API Imbretex - Prix et stock pour référence $main_reference : Prix = $price");
    $stock = 0;
    if (isset($price_stock['stock'])) {
        $stock += intval($price_stock['stock']);
    }
    if (isset($price_stock['stock_supplier'])) {
        $stock += intval($price_stock['stock_supplier']);
    }
    
    $image_url = '';
    if (!empty($variant['images']) && is_array($variant['images'])) {
        $first_image = $variant['images'][0];
        if (is_string($first_image)) {
            $image_url = $first_image;
        } elseif (is_array($first_image) && isset($first_image['url'])) {
            $image_url = $first_image['url'];
        }
    }
    
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
        return ['id' => $existing->id, 'is_new' => false];
    } else {
        $wpdb->insert(
            $table_name,
            $data,
            ['%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
        return ['id' => $wpdb->insert_id, 'is_new' => true];
    }
} */

function api_db_upsert_product($product_data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $variant = $product_data['variants'][0] ?? null;
    if (!$variant) return false;
    
    $is_variable = count($product_data['variants']) > 1;
    
    if ($is_variable) {
        error_log("API Imbretex - Produit variable détecté pour référence {$product_data['reference']}");
        error_log("API Imbretex - Référence variable utilisée : {$variant['variantReference']}");
        $main_reference = $variant['variantReference'];
    } else {
        $main_reference = $variant['variantReference'] ?? $product_data['reference'];
    }
    
    $category = 'Autres';
    if (!empty($variant['categories']) && is_array($variant['categories'])) {
        $first_cat = $variant['categories'][0];
        if (isset($first_cat['categories']['fr'])) {
            $category = $first_cat['categories']['fr'];
        } elseif (isset($first_cat['families']['fr'])) {
            $category = $first_cat['families']['fr'];
        }
    }
    
    // ✅ NOUVEAU : Récupérer et ajouter le prix pour CHAQUE variante dans le JSON
    if (isset($product_data['variants']) && is_array($product_data['variants'])) {
        foreach ($product_data['variants'] as $index => &$variant_item) {
            $variant_reference = $variant_item['variantReference'] ?? null;
            
            if ($variant_reference) {
                // Récupérer le prix et stock via l'API
                $price_stock = api_get_product_price_stock($variant_reference);
                
                if ($price_stock && isset($price_stock['price'])) {
                    // Ajouter le prix dans l'objet de la variante
                    $variant_item['price'] = floatval($price_stock['price']);
                    
                    error_log("API Imbretex - Prix ajouté pour variante {$variant_reference}: {$variant_item['price']}€");
                } else {
                    // Si pas de prix trouvé, mettre 0
                    $variant_item['price'] = 0;
                    error_log("API Imbretex - Aucun prix trouvé pour variante {$variant_reference}");
                }
                
                // Optionnel : Ajouter aussi le stock si besoin
                if ($price_stock && isset($price_stock['stock'])) {
                    $variant_item['stock'] = intval($price_stock['stock']);
                    
                    if (isset($price_stock['stock_supplier'])) {
                        $variant_item['stock'] += intval($price_stock['stock_supplier']);
                    }
                }
            }
        }
        unset($variant_item);
    }
    
    // Récupérer le prix de la référence principale pour la table (compatibilité)
    $price_stock = api_get_product_price_stock($main_reference);
    $price = isset($price_stock['price']) ? floatval($price_stock['price']) : 0;
    error_log("API Imbretex - Prix principal pour référence $main_reference : Prix = $price");
    
    $stock = 0;
    if (isset($price_stock['stock'])) {
        $stock += intval($price_stock['stock']);
    }
    if (isset($price_stock['stock_supplier'])) {
        $stock += intval($price_stock['stock_supplier']);
    }
    
    $image_url = '';
    if (!empty($variant['images']) && is_array($variant['images'])) {
        $first_image = $variant['images'][0];
        if (is_string($first_image)) {
            $image_url = $first_image;
        } elseif (is_array($first_image) && isset($first_image['url'])) {
            $image_url = $first_image['url'];
        }
    }
    
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
        return ['id' => $existing->id, 'is_new' => false];
    } else {
        $wpdb->insert(
            $table_name,
            $data,
            ['%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
        return ['id' => $wpdb->insert_id, 'is_new' => true];
    }
}

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

function api_db_truncate_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    return $wpdb->query("TRUNCATE TABLE $table_name");
}

function api_db_mark_products_as_deleted($skus_to_mark) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    if (empty($skus_to_mark) || !is_array($skus_to_mark)) {
        return 0;
    }
    
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

function api_fetch_products_page($since_created = null, $since_updated = null, $per_page = 50, $page = 1) {
    $api_url = API_BASE_URL . '/api/products/products';
    $per_page = min($per_page, 10);
    
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
        return [
            'products' => [], 
            'total' => 0, 
            'totalNumberPage' => 0, 
            'productCount' => 0,
            'variantCount' => 0,
            'error' => $response->get_error_message()
        ];
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return [
            'products' => [], 
            'total' => 0, 
            'totalNumberPage' => 0, 
            'productCount' => 0,
            'variantCount' => 0,
            'error' => 'HTTP ' . $code
        ];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!isset($data['products']) || empty($data['products'])) {
        return [
            'products' => [], 
            'total' => 0, 
            'totalNumberPage' => $data['totalNumberPage'] ?? 0, 
            'productCount' => $data['productCount'] ?? 0,
            'variantCount' => $data['variantCount'] ?? 0
        ];
    }

    $products = [];
    $total_received = count($data['products']);
    
    foreach ($data['products'] as $product_api) {
        $variant = $product_api['variants'][0] ?? null;
        if (!$variant) continue;

        $is_variable = count($product_api['variants']) > 1;
        
        if ($is_variable) {
            $main_reference = /* $product_api['reference'] ??  */$variant['variantReference'];
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
        'total' => $total_received,
        'totalNumberPage' => $data['totalNumberPage'] ?? 0,
        'productCount' => $data['productCount'] ?? 0,
        'variantCount' => $data['variantCount'] ?? 0
    ];
}

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
// GESTION DE L'ÉTAT DE LA SYNCHRONISATION (TRANSIENT - pour l'UI)
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

function api_process_sync_page($page, $since_created = null, $since_updated = null, $state_id = null) {
    set_time_limit(300);
    
    $status = api_get_sync_status();
    
    if (!$status || $status['status'] !== 'running') {
        return false;
    }
    
    $deleted_list = [];
    $db_marked_count = 0;
    
    // Première page : récupérer les produits supprimés
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
    
    if (isset($result['error'])) {
        // Enregistrer l'erreur dans la table d'état
        if ($state_id) {
            api_error_sync_state($state_id, $result['error']);
        }
        return [
            'success' => false,
            'error' => $result['error']
        ];
    }
    
    $products = $result['products'];
    $total_received = $result['total'];
    
    $total_pages = $result['totalNumberPage'] ?? 0;
    $total_products = $result['productCount'] ?? 0;
    $has_more = $page < $total_pages;
    
    if ($page === 1) {
        $status['total_pages'] = $total_pages;
        $status['total_products'] = $total_products;
        $status['variant_count'] = $result['variantCount'] ?? 0;
        
        api_add_sync_log("📊 Total API : $total_products produits sur $total_pages pages", 'info');
        
        // Mettre à jour la table d'état avec les totaux
        if ($state_id) {
            api_update_sync_state($state_id, [
                'total_products' => $total_products,
                'total_pages' => $total_pages,
                'status' => 'running'
            ]);
        }
    }
    
    $new_count = 0;
    $updated_count = 0;
    $saved_count = 0;
    $skipped_count = 0;
    $last_created_at = null;
    $last_updated_at = null;
    
    foreach ($products as $product) {
        $is_deleted = in_array($product['sku'], $deleted_list) || 
                      in_array($product['reference'], $deleted_list);
        
        if ($is_deleted) {
            $skipped_count++;
            continue;
        }
        
        $result_upsert = api_db_upsert_product($product['product_data']);
        if ($result_upsert) {
            $saved_count++;
            
            if ($result_upsert['is_new']) {
                $new_count++;
            } else {
                $updated_count++;
            }
            
            // Garder trace des dernières dates
            if (!empty($product['created_at'])) {
                if (!$last_created_at || $product['created_at'] > $last_created_at) {
                    $last_created_at = $product['created_at'];
                }
            }
            if (!empty($product['updated_at'])) {
                if (!$last_updated_at || $product['updated_at'] > $last_updated_at) {
                    $last_updated_at = $product['updated_at'];
                }
            }
        }
    }
    
    $status['current_page'] = $page;
    $status['total_pages'] = $total_pages;
    $status['total_products'] = $total_products;
    $status['total_fetched'] += $saved_count;
    $status['total_new'] += $new_count;
    $status['total_updated'] += $updated_count;
    $status['db_marked_count'] = $db_marked_count;
    $status['last_update'] = current_time('mysql');
    $status['last_created_at'] = $last_created_at ?: ($status['last_created_at'] ?? null);
    $status['last_updated_at'] = $last_updated_at ?: ($status['last_updated_at'] ?? null);
    
    $log_msg = "Page $page/$total_pages: $saved_count produits sauvegardés";
    if ($skipped_count > 0) {
        $log_msg .= ", $skipped_count ignorés (supprimés API)";
    }
    $log_msg .= " (Cumulé: {$status['total_fetched']}/$total_products)";
    api_add_sync_log($log_msg, 'success');
    
    // Mettre à jour la table d'état persistante
    if ($state_id) {
        api_update_sync_state($state_id, [
            'current_page' => $page,
            'products_imported' => $status['total_fetched'],
            'products_new' => $status['total_new'],
            'products_updated' => $status['total_updated'],
            'products_skipped' => $skipped_count
        ]);
    }
    
    if ($has_more) {
        $status['has_more'] = true;
        $status['next_page'] = $page + 1;
        api_update_sync_status($status);
        
        return ['success' => true, 'has_more' => true];
    } else {
        $status['status'] = 'completed';
        $status['has_more'] = false;
        $status['completed_at'] = current_time('mysql');
        
        unset($status['deleted_list']);
        api_update_sync_status($status);
        
        update_option('api_sync_db_marked_count', $db_marked_count);
        
        // Marquer la synchronisation comme terminée dans la table d'état
        if ($state_id) {
            api_complete_sync_state(
                $state_id,
                $status['last_created_at'],
                $status['last_updated_at']
            );
        }
        
        api_add_sync_log('═════════════════════════════════', 'info');
        api_add_sync_log('✓ SYNCHRONISATION TERMINÉE !', 'success');
        api_add_sync_log("✓ Total sauvegardés: {$status['total_fetched']}", 'success');
        api_add_sync_log("✓ Nouveaux: {$status['total_new']}", 'success');
        api_add_sync_log("✓ Mis à jour: {$status['total_updated']}", 'success');
        
        if ($db_marked_count > 0) {
            api_add_sync_log("🏷️ Marqués comme supprimés: $db_marked_count", 'info');
        }
        
        return ['success' => true, 'has_more' => false, 'completed' => true];
    }
}

// ============================================================
// CRON : SYNCHRONISATION INCRÉMENTALE
// ============================================================

// 1. Ajouter un intervalle de 10 minutes
add_filter('cron_schedules', function($schedules) {
    $schedules['every_10_minutes'] = [
        'interval' => 600,
        'display'  => __('Every 10 Minutes')
    ];
    return $schedules;
});

// Enregistrer le cron
add_action('init', function() {
    if (!wp_next_scheduled('api_daily_incremental_sync')) {
        wp_schedule_event(time(), 'every_10_minutes', 'api_daily_incremental_sync');
    }
});

// Action du cron
add_action('api_daily_incremental_sync', 'api_run_incremental_sync');

/**
 * Exécuter une synchronisation incrémentale (CRON OU MANUEL)
 */
function api_run_incremental_sync($is_manual = false) {
    // Vérifier qu'une synchronisation initiale a été complétée
    if (!api_has_completed_initial_sync()) {
        if (!$is_manual) {
            error_log('API Imbretex Cron - Aucune synchronisation initiale complétée. Sync incrémentale ignorée.');
        }
        return false;
    }
    
    // Vérifier qu'aucune synchronisation n'est en cours
    $active_state = api_get_active_sync_state();
    if ($active_state && $active_state['status'] === 'running') {
        if (!$is_manual) {
            error_log('API Imbretex Cron - Une synchronisation est déjà en cours.');
        }
        return false;
    }
    
    // Récupérer la dernière synchronisation complète
    $last_completed = api_get_last_completed_sync();
    if (!$last_completed) {
        if (!$is_manual) {
            error_log('API Imbretex Cron - Impossible de trouver la dernière sync complète.');
        }
        return false;
    }
    
    // Déterminer les paramètres de date
    $since_created = $last_completed['last_product_created_at'] ?? $last_completed['last_sync_completed'];
    $since_updated = $last_completed['last_product_updated_at'] ?? $last_completed['last_sync_completed'];
    
    $log_prefix = $is_manual ? 'Manuel' : 'Cron';
    error_log("API Imbretex $log_prefix - Démarrage sync incrémentale depuis created: $since_created, updated: $since_updated");
    
    // Créer une nouvelle entrée d'état
    $state_id = api_create_sync_state([
        'sync_type' => 'incremental',
        'status' => 'running',
        'is_initial_sync' => 0
    ]);
    
    // Exécuter la synchronisation
    return api_run_background_sync($since_created, $since_updated, $state_id, $is_manual);
}

/**
 * Exécuter une synchronisation en arrière-plan
 */
function api_run_background_sync($since_created = null, $since_updated = null, $state_id = null, $is_manual = false) {
    set_time_limit(0);
    ignore_user_abort(true);
    
    $page = 1;
    $max_pages = 10000;
    $log_prefix = $is_manual ? 'Manuel' : 'Cron';
    
    while ($page <= $max_pages) {
        $result = api_fetch_products_page($since_created, $since_updated, 50, $page);
        
        if (isset($result['error'])) {
            error_log("API Imbretex $log_prefix - Erreur page $page: " . $result['error']);
            if ($state_id) {
                api_error_sync_state($state_id, $result['error']);
            }
            break;
        }
        
        $products = $result['products'];
        $total_pages = $result['totalNumberPage'] ?? 0;
        
        if ($page === 1 && $state_id) {
            api_update_sync_state($state_id, [
                'total_products' => $result['productCount'] ?? 0,
                'total_pages' => $total_pages
            ]);
        }
        
        $new_count = 0;
        $updated_count = 0;
        $saved_count = 0;
        $last_created_at = null;
        $last_updated_at = null;
        
        foreach ($products as $product) {
            $result_upsert = api_db_upsert_product($product['product_data']);
            if ($result_upsert) {
                $saved_count++;
                
                if ($result_upsert['is_new']) {
                    $new_count++;
                } else {
                    $updated_count++;
                }
                
                // Garder trace des dernières dates
                if (!empty($product['created_at'])) {
                    if (!$last_created_at || $product['created_at'] > $last_created_at) {
                        $last_created_at = $product['created_at'];
                    }
                }
                if (!empty($product['updated_at'])) {
                    if (!$last_updated_at || $product['updated_at'] > $last_updated_at) {
                        $last_updated_at = $product['updated_at'];
                    }
                }
            }
        }
        
        if ($state_id) {
            api_update_sync_state($state_id, [
                'current_page' => $page,
                'products_imported' => $saved_count,
                'products_new' => $new_count,
                'products_updated' => $updated_count
            ]);
        }
        
        error_log("API Imbretex $log_prefix - Page $page/$total_pages: $saved_count produits ($new_count nouveaux, $updated_count MAJ)");
        
        if ($page >= $total_pages) {
            // Terminé
            if ($state_id) {
                api_complete_sync_state($state_id, $last_created_at, $last_updated_at);
            }
            error_log("API Imbretex $log_prefix - Synchronisation terminée !");
            break;
        }
        
        $page++;
        usleep(100000); // 100ms de pause entre les pages
    }
    
    return true;
}

// ============================================================
// PAGE : SYNCHRONISATION
// ============================================================
function api_sync_page() {
    $total = api_db_count_products(['is_deleted' => 0]);
    $new = api_db_count_products(['status' => 'new', 'imported' => 0, 'is_deleted' => 0]);
    $updated = api_db_count_products(['status' => 'updated', 'imported' => 0, 'is_deleted' => 0]);
    $imported = api_db_count_products(['imported' => 1, 'is_deleted' => 0]);
    $not_imported = api_db_count_products(['imported' => 0, 'is_deleted' => 0]);
    $marked_count = api_db_count_products(['is_deleted' => 1]);
    
    $sync_status = api_get_sync_status();
    $is_running = $sync_status && $sync_status['status'] === 'running';
    
    // Récupérer l'état persistant pour détecter une sync interrompue
    $persistent_state = api_get_active_sync_state();
    $has_interrupted_sync = $persistent_state && in_array($persistent_state['status'], ['paused', 'error']);
    error_log('API Imbretex - Affichage de la page de synchronisation. Sync en cours : ' . ($is_running ? 'Oui' : 'Non') . '. Sync interrompue : ' . ($has_interrupted_sync ? 'Oui' : 'Non'));
    $has_completed_initial = api_has_completed_initial_sync();
    $last_completed_sync = api_get_last_completed_sync();
    
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
        
        <?php if ($has_interrupted_sync && !$is_running): ?>
        <div style="background:#f8d7da;border-left:4px solid #dc3545;padding:15px;margin:20px 0;">
            <strong>⚠️ Synchronisation interrompue détectée !</strong>
            <p style="margin:5px 0 0 0;">
                Une synchronisation a été interrompue à la page <strong><?php echo $persistent_state['current_page']; ?>/<?php echo $persistent_state['total_pages']; ?></strong> 
                (<?php echo number_format($persistent_state['products_imported']); ?>/<?php echo number_format($persistent_state['total_products']); ?> produits).
                <br>
                <small>
                    Status: <?php echo $persistent_state['status']; ?> 
                    | Arrêtée le: <?php echo $persistent_state['stopped_at'] ?? 'N/A'; ?>
                    <?php if ($persistent_state['error_message']): ?>
                    | Raison: <?php echo esc_html($persistent_state['error_message']); ?>
                    <?php endif; ?>
                </small>
            </p>
        </div>
        <?php endif; ?>
        
        <?php if (!$has_completed_initial && !$is_running): ?>
        <div style="background:#d1ecf1;border-left:4px solid #17a2b8;padding:15px;margin:20px 0;">
            <strong>ℹ️ Première synchronisation requise</strong>
            <p style="margin:5px 0 0 0;">
                Aucune synchronisation initiale n'a été complétée. Veuillez lancer une synchronisation complète.
                <br>
                <small>Le cron automatique (toutes les 10 minutes) ne démarrera qu'après la première synchronisation complète.</small>
            </p>
        </div>
        <?php elseif ($has_completed_initial && $last_completed_sync): ?>
        <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin:20px 0;">
            <strong>✓ Synchronisation initiale complétée</strong>
            <p style="margin:5px 0 0 0;">
                Dernière synchronisation : <strong><?php echo $last_completed_sync['completed_at']; ?></strong>
                <br>
                <?php if ($last_completed_sync['last_product_created_at']): ?>
                Dernière date création : <strong><?php echo $last_completed_sync['last_product_created_at']; ?></strong><br>
                <?php endif; ?>
                <?php if ($last_completed_sync['last_product_updated_at']): ?>
                Dernière date mise à jour : <strong><?php echo $last_completed_sync['last_product_updated_at']; ?></strong><br>
                <?php endif; ?>
                <small>✓ Le cron automatique effectue des synchronisations incrémentales toutes les 10 minutes.</small>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Statistiques - GRILLE DE 5 CARTES -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin:20px 0;">
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #2271b1;">
                <h3 style="margin:0 0 10px 0;color:#2271b1;font-size:14px;">📦 Produits Actifs</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($total); ?></p>
                <small>Dans la base (non supprimés)</small>
            </div>
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #46b450;">
                <h3 style="margin:0 0 10px 0;color:#46b450;font-size:14px;">✓ Importés en WC</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($imported); ?></p>
                <small>Déjà créés dans WooCommerce</small>
            </div>
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #dc3232;">
                <h3 style="margin:0 0 10px 0;color:#dc3232;font-size:14px;">⏳ À Importer</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($not_imported); ?></p>
                <small><?php echo number_format($new); ?> nouveaux, <?php echo number_format($updated); ?> MAJ</small>
            </div>
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #826eb4;">
                <h3 style="margin:0 0 10px 0;color:#826eb4;font-size:14px;">🏷️ Marqués Supprimés</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($marked_count); ?></p>
                <small>Produits marqués is_deleted=1</small>
            </div>
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #50575e;">
                <h3 style="margin:0 0 10px 0;color:#50575e;font-size:14px;">📊 Total Base</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($total + $marked_count); ?></p>
                <small>Actifs + Supprimés</small>
            </div>
        </div>
        
        <!-- Zone de synchronisation -->
        <div style="background:#fff;padding:20px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h2>⚙️ Synchronisation</h2>
            
            <?php if (!$has_completed_initial && !$has_interrupted_sync): ?>
            <!-- Première synchronisation COMPLÈTE -->
            <p style="color:#666;margin-bottom:15px;">
                <strong>Mode:</strong> Synchronisation initiale (COMPLÈTE - sans sinceCreated/sinceUpdated)
            </p>
            <button type="button" id="start-initial-sync" class="button button-primary button-large" style="font-size:16px;padding:10px 30px;" <?php echo $is_running ? 'disabled' : ''; ?>>
                <?php echo $is_running ? '⏳ Synchronisation en cours...' : '🔄 Lancer la Synchronisation Initiale'; ?>
            </button>
            
            <?php else: ?>
            <!-- Après sync initiale : Reprise OU Incrémentale -->
            
            <?php if ($has_interrupted_sync && !$is_running): ?>
            <!-- REPRISE : Sync interrompue détectée -->
            <p style="color:#666;margin-bottom:15px;">
                <strong>Sync interrompue détectée !</strong> Vous pouvez reprendre où vous vous êtes arrêté.
                <br>
                <small>Page actuelle : <?php echo $persistent_state['current_page']; ?>/<?php echo $persistent_state['total_pages']; ?></small>
            </p>
            <button type="button" id="resume-sync" class="button button-primary button-large" style="font-size:16px;padding:10px 30px;background:#28a745;border-color:#28a745;">
                ▶️ Reprendre la Synchronisation (page <?php echo $persistent_state['current_page'] + 1; ?>)
            </button>
            
            <button type="button" id="restart-incremental-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">
                🔄 Recommencer en Incrémental
            </button>
            
            <?php else: ?>
            <!-- INCRÉMENTAL : Nouvelle synchronisation -->
            <p style="color:#666;margin-bottom:15px;">
                <strong>Mode:</strong> Synchronisation incrémentale (avec sinceCreated/sinceUpdated depuis la dernière sync)
                <br>
                <small>Les synchronisations utilisent les dates de la dernière synchronisation complète comme point de départ.</small>
            </p>
            
            <button type="button" id="start-incremental-sync" class="button button-primary button-large" style="font-size:16px;padding:10px 30px;" <?php echo $is_running ? 'disabled' : ''; ?>>
                <?php echo $is_running ? '⏳ Synchronisation en cours...' : '🔄 Lancer Synchronisation Manuelle (Cron)'; ?>
            </button>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($is_running): ?>
            <button type="button" id="cancel-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">
                ⛔ Annuler la Synchronisation
            </button>
            <?php endif; ?>
            
            <button type="button" id="clear-all" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;" <?php echo $is_running ? 'disabled' : ''; ?>>
                🗑️ Vider la Table
            </button>
            
            <button type="button" id="reset-state" class="button button-link" style="font-size:14px;padding:10px 15px;margin-left:10px;color:#dc3232;" <?php echo $is_running ? 'disabled' : ''; ?>>
                ⚠️ Réinitialiser l'état
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
        
        <!-- Informations sur le Cron -->
        <div style="background:#fff;padding:20px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h2>⏰ Synchronisation Automatique (Cron)</h2>
            <?php
            $next_scheduled = wp_next_scheduled('api_daily_incremental_sync');
            ?>
            <table class="widefat" style="max-width:600px;">
                <tr>
                    <td><strong>Status:</strong></td>
                    <td>
                        <?php if ($has_completed_initial): ?>
                        <span style="color:#28a745;">✓ Actif (toutes les 10 minutes)</span>
                        <?php else: ?>
                        <span style="color:#dc3232;">✗ En attente de la synchronisation initiale</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Prochaine exécution:</strong></td>
                    <td><?php echo $next_scheduled ? date('d/m/Y H:i:s', $next_scheduled) : 'Non programmé'; ?></td>
                </tr>
                <tr>
                    <td><strong>Mode:</strong></td>
                    <td>Incrémental (sinceCreatedAt / sinceUpdatedAt)</td>
                </tr>
                <?php if ($last_completed_sync): ?>
                <tr>
                    <td><strong>Dernière sync complète:</strong></td>
                    <td><?php echo $last_completed_sync['completed_at']; ?></td>
                </tr>
                <?php if ($last_completed_sync['last_product_created_at']): ?>
                <tr>
                    <td><strong>sinceCreatedAt:</strong></td>
                    <td><?php echo $last_completed_sync['last_product_created_at']; ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($last_completed_sync['last_product_updated_at']): ?>
                <tr>
                    <td><strong>sinceUpdatedAt:</strong></td>
                    <td><?php echo $last_completed_sync['last_product_updated_at']; ?></td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
            </table>
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
        var persistentStateId = <?php echo $persistent_state ? intval($persistent_state['id']) : 'null'; ?>;
        var resumePage = <?php echo $persistent_state ? intval($persistent_state['current_page']) + 1 : 1; ?>;
        var hasCompletedInitial = <?php echo $has_completed_initial ? 'true' : 'false'; ?>;
        
        // Variables pour le watchdog (détection de blocage)
        var lastStatusUpdate = null;
        var watchdogInterval = null;
        var watchdogTimeout = 30000; // 30 secondes
        var currentProcessingPage = null;
        
        function updateUI(status, logs) {
            if (!status) {
                $('#sync-progress-container').hide();
                $('#sync-logs').html('<p style="color:#999;">En attente de synchronisation...</p>');
                return;
            }
            
            $('#sync-progress-container').show();
            
            var progress = 0;
            if (status.total_pages && status.total_pages > 0) {
                progress = Math.round((status.current_page / status.total_pages) * 100);
            } else {
                progress = Math.min(95, status.current_page * 2);
            }
            
            if (status.status === 'completed') {
                progress = 100;
            }
            
            $('#sync-progress-bar').css('width', progress + '%');
            $('#sync-progress-text').text(progress + '%');
            
            var statusText = 'Page ' + status.current_page;
            if (status.total_pages) {
                statusText += '/' + status.total_pages;
            }
            statusText += ' - ' + status.total_fetched;
            if (status.total_products) {
                statusText += '/' + status.total_products;
            }
            statusText += ' produits';
            
            if (status.status === 'completed') {
                statusText = '✓ Synchronisation terminée ! ' + status.total_fetched + ' produits traités';
                $('#sync-status').css('color', '#46b450');
                
                if (pollingInterval) {
                    clearInterval(pollingInterval);
                    pollingInterval = null;
                }
                if (watchdogInterval) {
                    clearInterval(watchdogInterval);
                    watchdogInterval = null;
                }
                
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
            $('#sync-status').text(statusText);
            
            if (logs && logs.length > 0) {
                var logsHtml = '';
                logs.forEach(function(log) {
                    var color = log.type === 'success' ? '#46b450' : (log.type === 'error' ? '#dc3232' : (log.type === 'warning' ? '#ff9800' : '#333'));
                    logsHtml += '<p style="color:' + color + ';margin:5px 0;">[' + log.time + '] ' + log.message + '</p>';
                });
                $('#sync-logs').html(logsHtml);
                $('#sync-logs').scrollTop($('#sync-logs')[0].scrollHeight);
            }
            
            var statusKey = status.current_page + '-' + status.total_fetched;
            if (statusKey !== lastStatusUpdate) {
                lastStatusUpdate = statusKey;
                console.log('Statut mis à jour : ' + statusKey);
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
                            if (watchdogInterval) {
                                clearInterval(watchdogInterval);
                                watchdogInterval = null;
                            }
                        }
                    }
                }
            });
        }
        
        function checkWatchdog() {
            var now = Date.now();
            var lastCheck = parseInt(localStorage.getItem('api_sync_last_check') || '0');
            var lastStatus = localStorage.getItem('api_sync_last_status') || '';
            var currentStatus = lastStatusUpdate;
            
            console.log('Watchdog check - Last: ' + lastStatus + ', Current: ' + currentStatus);
            
            if (lastStatus === currentStatus && (now - lastCheck) > watchdogTimeout) {
                console.warn('⚠️ BLOCAGE DÉTECTÉ ! Relance de la page en cours...');
                
                var logMsg = '⚠️ Blocage détecté (30s sans progression) - Relance automatique de la page ' + currentProcessingPage;
                var newLog = {
                    time: new Date().toLocaleTimeString(),
                    message: logMsg,
                    type: 'warning'
                };
                
                $('#sync-logs').append('<p style="color:#ff9800;margin:5px 0;">[' + newLog.time + '] ' + newLog.message + '</p>');
                $('#sync-logs').scrollTop($('#sync-logs')[0].scrollHeight);
                
                if (currentProcessingPage) {
                    processSyncPage(currentProcessingPage);
                }
                
                localStorage.setItem('api_sync_last_check', now.toString());
                localStorage.setItem('api_sync_last_status', currentStatus || '');
            } else {
                localStorage.setItem('api_sync_last_check', now.toString());
                localStorage.setItem('api_sync_last_status', currentStatus || '');
            }
        }
        
        if (isRunning) {
            pollSyncStatus();
            pollingInterval = setInterval(pollSyncStatus, 3000);
            watchdogInterval = setInterval(checkWatchdog, 10000);
        }
        
        var syncRetryCount = 0;
        var maxSyncRetries = 5;
        
        function processSyncPage(pageNum) {
            currentProcessingPage = pageNum;
            console.log('Traitement de la page ' + pageNum);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_process_single_page',
                    page: pageNum
                },
                timeout: 120000,
                success: function(response) {
                    if (response.success) {
                        syncRetryCount = 0;
                        
                        pollSyncStatus();
                        
                        if (response.data.has_more && !response.data.completed) {
                            setTimeout(function() {
                                processSyncPage(pageNum + 1);
                            }, 500);
                        } else if (response.data.completed) {
                            console.log('Synchronisation terminée !');
                            if (watchdogInterval) {
                                clearInterval(watchdogInterval);
                                watchdogInterval = null;
                            }
                        }
                    } else {
                        handleSyncError('Erreur serveur: ' + (response.data.message || 'Erreur inconnue'), pageNum);
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = 'Erreur réseau';
                    if (status === 'timeout') {
                        errorMsg = 'Timeout (2min dépassé)';
                    } else if (xhr.status) {
                        errorMsg = 'Erreur HTTP ' + xhr.status;
                    }
                    handleSyncError(errorMsg + ' - ' + error, pageNum);
                }
            });
        }
        
        function handleSyncError(errorMessage, pageNum) {
            syncRetryCount++;
            
            var retryMsg = '❌ ' + errorMessage;
            $('#sync-logs').append('<p style="color:#dc3232;margin:5px 0;">[' + new Date().toLocaleTimeString() + '] ' + retryMsg + '</p>');
            
            if (syncRetryCount <= maxSyncRetries) {
                var retryInfo = '🔄 Tentative ' + syncRetryCount + '/' + maxSyncRetries + ' - Nouvelle tentative dans 3 secondes...';
                $('#sync-logs').append('<p style="color:#ff9800;margin:5px 0;">[' + new Date().toLocaleTimeString() + '] ' + retryInfo + '</p>');
                $('#sync-logs').scrollTop($('#sync-logs')[0].scrollHeight);
                
                $('#sync-status').text('⚠️ Erreur - Tentative ' + syncRetryCount + '/' + maxSyncRetries + '...').css('color', '#ff9800');
                
                setTimeout(function() {
                    processSyncPage(pageNum);
                }, 3000);
            } else {
                var skipMsg = '⚠️ Échec après ' + maxSyncRetries + ' tentatives - Passage à la page suivante';
                $('#sync-logs').append('<p style="color:#ff9800;margin:5px 0;font-weight:bold;">[' + new Date().toLocaleTimeString() + '] ' + skipMsg + '</p>');
                $('#sync-logs').scrollTop($('#sync-logs')[0].scrollHeight);
                
                syncRetryCount = 0;
                setTimeout(function() {
                    processSyncPage(pageNum + 1);
                }, 1000);
            }
        }
        
        function startSync(startPage, syncType) {
            var $btn = syncType === 'initial' ? $('#start-initial-sync') : 
                       syncType === 'incremental' ? $('#start-incremental-sync') : 
                       syncType === 'resume' ? $('#resume-sync') : $('#start-initial-sync');
            
            $btn.prop('disabled', true).text('⏳ Démarrage...');
            $('#sync-progress-container').show();
            $('#sync-status').text('Démarrage de la synchronisation...').css('color', '#2271b1');
            
            isRunning = true;
            pollingInterval = setInterval(pollSyncStatus, 2000);
            
            localStorage.setItem('api_sync_last_check', Date.now().toString());
            localStorage.setItem('api_sync_last_status', '');
            watchdogInterval = setInterval(checkWatchdog, 10000);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_start_background_sync',
                    sync_type: syncType,
                    resume: syncType === 'resume' ? 1 : 0,
                    start_page: startPage
                },
                success: function(response) {
                    if (response.success) {
                        $btn.text('⏳ Synchronisation en cours...');
                        
                        var cancelBtn = '<button type="button" id="cancel-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">⛔ Annuler la Synchronisation</button>';
                        $btn.after(cancelBtn);
                        
                        setTimeout(function() {
                            processSyncPage(startPage);
                        }, 1000);
                    } else {
                        alert('Erreur: ' + (response.data.message || 'Impossible de démarrer la synchronisation'));
                        $btn.prop('disabled', false).text('🔄 Synchroniser');
                        if (pollingInterval) {
                            clearInterval(pollingInterval);
                            pollingInterval = null;
                        }
                        if (watchdogInterval) {
                            clearInterval(watchdogInterval);
                            watchdogInterval = null;
                        }
                    }
                },
                error: function() {
                    alert('Erreur réseau');
                    $btn.prop('disabled', false).text('🔄 Synchroniser');
                    if (pollingInterval) {
                        clearInterval(pollingInterval);
                        pollingInterval = null;
                    }
                    if (watchdogInterval) {
                        clearInterval(watchdogInterval);
                        watchdogInterval = null;
                    }
                }
            });
        }
        
        // Bouton: Synchronisation initiale (COMPLÈTE)
        $('#start-initial-sync').on('click', function() {
            startSync(1, 'initial');
        });
        
        // Bouton: Synchronisation incrémentale (MANUELLE)
        $('#start-incremental-sync').on('click', function() {
            startSync(1, 'incremental');
        });
        
        // Bouton: Reprendre synchronisation (à la page d'arrêt)
        $('#resume-sync').on('click', function() {
            startSync(resumePage, 'resume');
        });
        
        // Bouton: Recommencer en incrémental (depuis page 1)
        $('#restart-incremental-sync').on('click', function() {
            if (!confirm('Êtes-vous sûr de vouloir recommencer depuis le début en mode incrémental ?\n\nCela va ignorer la progression actuelle et recommencer à la page 1 avec les dates de la dernière synchronisation.')) {
                return;
            }
            startSync(1, 'incremental');
        });
        
        // Bouton: Annuler synchronisation
        $(document).on('click', '#cancel-sync', function() {
            if (!confirm('Êtes-vous sûr de vouloir annuler la synchronisation en cours ?\n\nVous pourrez la reprendre plus tard depuis la page où elle s\'est arrêtée.')) {
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
                        if (watchdogInterval) {
                            clearInterval(watchdogInterval);
                            watchdogInterval = null;
                        }
                        localStorage.removeItem('api_sync_last_check');
                        localStorage.removeItem('api_sync_last_status');
                        location.reload();
                    }
                }
            });
        });
        
        // Bouton: Vider la table
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
        
        // Bouton: Réinitialiser l'état
        $('#reset-state').on('click', function() {
            if (isRunning) {
                alert('⚠️ Impossible de réinitialiser pendant une synchronisation en cours.');
                return;
            }
            
            if (!confirm('⚠️ ATTENTION : Êtes-vous sûr de vouloir réinitialiser l\'état de synchronisation ?\n\nCela effacera l\'historique des synchronisations et le cron devra recommencer depuis une synchronisation initiale complète.')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_reset_sync_state'
                },
                success: function(response) {
                    if (response.success) {
                        alert('✓ État réinitialisé !');
                        location.reload();
                    } else {
                        alert('✗ Erreur : ' + (response.data.message || 'Erreur inconnue'));
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
    set_time_limit(120);
    
    $current_status = api_get_sync_status();
    if ($current_status && $current_status['status'] === 'running') {
        wp_send_json_error(['message' => 'Une synchronisation est déjà en cours']);
        return;
    }
    
    $sync_type = isset($_POST['sync_type']) ? sanitize_text_field($_POST['sync_type']) : 'initial';
    $is_resume = isset($_POST['resume']) && $_POST['resume'] == 1;
    $start_page = isset($_POST['start_page']) ? intval($_POST['start_page']) : 1;
    
    delete_transient('api_sync_logs');
    
    // Déterminer les dates pour la sync incrémentale
    $since_created = null;
    $since_updated = null;
    
    if ($sync_type === 'incremental') {
        $last_completed = api_get_last_completed_sync();
        error_log('Last completed sync: ' . print_r($last_completed, true));
        if ($last_completed) {
            $since_created = $last_completed['last_product_created_at'] ?? $last_completed['last_sync_completed'];
            $since_updated = $last_completed['last_product_updated_at'] ?? $last_completed['last_sync_completed'];
        }
    }
    
    // Créer ou mettre à jour l'état persistant
    $state_id = null;
    if ($is_resume) {
        $active_state = api_get_active_sync_state();
        if ($active_state) {
            $state_id = $active_state['id'];
            api_update_sync_state($state_id, [
                'status' => 'running',
                'stopped_at' => null,
                'error_message' => null
            ]);
            
            // Récupérer les dates de cette synchronisation
            $since_created = $active_state['last_product_created_at'] ?? $since_created;
            $since_updated = $active_state['last_product_updated_at'] ?? $since_updated;
        }
    }
    
    if (!$state_id) {
        $state_id = api_create_sync_state([
            'sync_type' => $sync_type,
            'status' => 'running',
            'is_initial_sync' => $sync_type === 'initial' ? 1 : 0
        ]);
    }
    
    // Récupérer les données de l'état pour la reprise
    $persistent_state = null;
    if ($is_resume && $state_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'imbretex_sync_state';
        $persistent_state = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $state_id
        ), ARRAY_A);
    }
    
    $status = [
        'status' => 'running',
        'started_at' => current_time('mysql'),
        'current_page' => $is_resume && $persistent_state ? $persistent_state['current_page'] : 0,
        'total_pages' => $is_resume && $persistent_state ? $persistent_state['total_pages'] : 0,
        'total_products' => $is_resume && $persistent_state ? $persistent_state['total_products'] : 0,
        'total_fetched' => $is_resume && $persistent_state ? $persistent_state['products_imported'] : 0,
        'total_new' => $is_resume && $persistent_state ? $persistent_state['products_new'] : 0,
        'total_updated' => $is_resume && $persistent_state ? $persistent_state['products_updated'] : 0,
        'since_created' => $since_created,
        'since_updated' => $since_updated,
        'has_more' => true,
        'state_id' => $state_id
    ];
    
    api_update_sync_status($status);
    
    if ($is_resume) {
        api_add_sync_log('▶️ Reprise de la synchronisation depuis la page ' . $start_page . '...', 'info');
    } elseif ($sync_type === 'incremental') {
        api_add_sync_log('🔄 Démarrage de la synchronisation incrémentale manuelle...', 'info');
        if ($since_created) {
            api_add_sync_log("📅 sinceCreated: $since_created", 'info');
        }
        if ($since_updated) {
            api_add_sync_log("📅 sinceUpdated: $since_updated", 'info');
        }
    } else {
        api_add_sync_log('🚀 Démarrage de la synchronisation complète initiale...', 'info');
    }
    
    api_add_sync_log('✅ Traitement automatique page par page avec watchdog...', 'info');
    api_add_sync_log('─────────────────────────────────', 'info');
    
    wp_send_json_success(['message' => 'Synchronisation démarrée', 'state_id' => $state_id]);
});

add_action('wp_ajax_api_process_single_page', function() {
    set_time_limit(120);
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    
    $status = api_get_sync_status();
    
    if (!$status || $status['status'] !== 'running') {
        wp_send_json_error(['message' => 'Aucune synchronisation en cours']);
        return;
    }
    
    $since_created = $status['since_created'] ?? null;
    $since_updated = $status['since_updated'] ?? null;
    $state_id = $status['state_id'] ?? null;
    
    $result = api_process_sync_page($page, $since_created, $since_updated, $state_id);
    
    if (!$result || !$result['success']) {
        wp_send_json_error([
            'message' => $result['error'] ?? 'Erreur inconnue'
        ]);
        return;
    }
    
    $updated_status = api_get_sync_status();
    
    wp_send_json_success([
        'status' => $updated_status,
        'has_more' => $result['has_more'] ?? false,
        'completed' => $result['completed'] ?? false
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
    $status = api_get_sync_status();
    $state_id = $status['state_id'] ?? null;
    
    if ($state_id) {
        api_pause_sync_state($state_id, 'user_cancelled');
    }
    
    api_reset_sync_status();
    delete_transient('api_sync_logs');
    
    api_add_sync_log('⛔ Synchronisation annulée par l\'utilisateur', 'error');
    api_add_sync_log('ℹ️ Vous pouvez reprendre la synchronisation plus tard', 'info');
    
    wp_send_json_success();
});

add_action('wp_ajax_api_clear_table', function() {
    $result = api_db_truncate_table();
    
    if ($result !== false) {
        delete_option('api_sync_db_marked_count');
        api_reset_all_sync_states();
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => 'Erreur lors de la suppression']);
    }
});

add_action('wp_ajax_api_reset_sync_state', function() {
    $result = api_reset_all_sync_states();
    api_reset_sync_status();
    delete_transient('api_sync_logs');
    
    if ($result !== false) {
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => 'Erreur lors de la réinitialisation']);
    }
});

// ============================================================
// DÉSACTIVATION DU CRON
// ============================================================
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('api_daily_incremental_sync');
});