<?php
/**
 * Module : Synchronisation (v8.4.1 - Batch Prix + Cron Suppression + Error Handling)
 * 
 * MODIFICATION v8.4.1 :
 * - Système de retry automatique pour les erreurs API (3 tentatives)
 * - Backoff exponentiel (1s, 2s, 4s pour réseau / 2s, 4s, 8s pour serveur)
 * - Logs améliorés avec détection Cloudflare (502, 503, 504)
 * - Monitoring des échecs API avec compteur et alertes
 * - Continuation de la sync même si API prix échoue
 * 
 * MODIFICATION v8.4 :
 * - Optimisation BATCH pour récupération des prix (10-20x plus rapide)
 * - Cron automatique pour gérer les produits supprimés (toutes les heures)
 * - Fonction api_get_products_price_stock_batch() pour récupérer plusieurs prix en 1 requête
 * 
 * MODIFICATION v8.3 :
 * - Ajout hook pour détecter la publication (publish)
 * - Restauration depuis corbeille → draft (au lieu de publish)
 * - Changement de 'active' à 'publish' partout
 * 
 * MODIFICATION v8.2 :
 * - Ajout du champ wc_status pour tracker l'état WooCommerce
 * - Hooks pour détecter suppression/corbeille/restauration
 * - Statistiques par statut WooCommerce
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// HOOK : VÉRIFIER LES TABLES AU CHARGEMENT DE L'ADMIN
// ============================================================
define('API_IMBRETEX_DB_VERSION', '8.4.1');

add_action('admin_init', function() {
    $current_version = get_option('api_imbretex_db_version', '0');
    
    if (version_compare($current_version, API_IMBRETEX_DB_VERSION, '<')) {
        api_create_sync_table();
        api_create_sync_state_table();
        update_option('api_imbretex_db_version', API_IMBRETEX_DB_VERSION);
        error_log('API Imbretex - Base de données mise à jour vers la version ' . API_IMBRETEX_DB_VERSION);
    }
    
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
        wc_status varchar(20) DEFAULT NULL,
        wc_status_updated_at datetime DEFAULT NULL,
        is_deleted tinyint(1) DEFAULT 0,
        deleted_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY sku (sku),
        KEY status (status),
        KEY imported (imported),
        KEY synced_at (synced_at),
        KEY brand (brand),
        KEY category (category),
        KEY is_deleted (is_deleted),
        KEY wc_status (wc_status)
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
        
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND COLUMN_NAME = 'wc_status'
        ");
        
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN wc_status varchar(20) DEFAULT NULL AFTER wc_product_id");
            error_log('API Imbretex - Colonne wc_status ajoutée automatiquement');
        }
        
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND COLUMN_NAME = 'wc_status_updated_at'
        ");
        
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN wc_status_updated_at datetime DEFAULT NULL AFTER wc_status");
            error_log('API Imbretex - Colonne wc_status_updated_at ajoutée automatiquement');
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
        
        $index_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '$table_name' 
            AND INDEX_NAME = 'wc_status'
        ");
        
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY wc_status (wc_status)");
            error_log('API Imbretex - Index wc_status ajouté automatiquement');
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
// FONCTIONS DE PRIX OPTIMISÉES (BATCH) - VERSION CORRIGÉE v8.4.1
// ============================================================

/**
 * 🔥 VERSION CORRIGÉE : Récupération batch des prix/stocks
 * Accepte un array de références et retourne tous les prix en une seule requête
 * Avec système de retry en cas d'erreur temporaire
 */
function api_get_products_price_stock_batch($references, $retry_count = 0, $max_retries = 3) {
    if (empty($references) || !is_array($references)) {
        return null;
    }
    
    $references = array_unique(array_filter($references));
    
    if (empty($references)) {
        return null;
    }
    
    $api_url = API_BASE_URL . '/api/products/price-stock';
    
    // Format: /api/products/price-stock?products=REF1,REF2,REF3
    $references_string = implode(',', array_map('urlencode', $references));
    $full_url = $api_url . '?products=' . $references_string;
    
    $response = wp_remote_get($full_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . API_TOKEN,
            'Accept' => 'application/json'
        ],
        'timeout' => 30
    ]);
    
    // Gestion des erreurs réseau
    if (is_wp_error($response)) {
        $error_msg = $response->get_error_message();
        error_log("API Imbretex - ✗ Erreur batch price-stock (tentative " . ($retry_count + 1) . "/$max_retries): $error_msg");
        
        // Retry pour erreurs réseau
        if ($retry_count < $max_retries) {
            $wait_time = pow(2, $retry_count); // Backoff exponentiel : 1s, 2s, 4s
            error_log("API Imbretex - ⏳ Nouvelle tentative dans {$wait_time}s...");
            sleep($wait_time);
            return api_get_products_price_stock_batch($references, $retry_count + 1, $max_retries);
        }
        
        error_log("API Imbretex - ⚠️ ABANDON après $max_retries tentatives - Prix non récupérés");
        api_track_batch_failure();
        return null;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    
    // Gestion des erreurs HTTP
    if ($http_code !== 200) {
        $error_body = wp_remote_retrieve_body($response);
        
        // Détecter le type d'erreur
        $error_type = "HTTP $http_code";
        if (strpos($error_body, 'Bad gateway') !== false || strpos($error_body, '502') !== false) {
            $error_type = "502 Bad Gateway (serveur API indisponible)";
        } elseif (strpos($error_body, 'Service Unavailable') !== false || strpos($error_body, '503') !== false) {
            $error_type = "503 Service Unavailable";
        } elseif (strpos($error_body, '504') !== false) {
            $error_type = "504 Gateway Timeout";
        }
        
        error_log("API Imbretex - ✗ Erreur $error_type - Tentative " . ($retry_count + 1) . "/$max_retries");
        
        // Retry pour erreurs 502, 503, 504 (erreurs temporaires du serveur)
        if (in_array($http_code, [502, 503, 504]) && $retry_count < $max_retries) {
            $wait_time = pow(2, $retry_count) * 2; // Backoff plus long : 2s, 4s, 8s
            error_log("API Imbretex - ⏳ Nouvelle tentative dans {$wait_time}s...");
            sleep($wait_time);
            return api_get_products_price_stock_batch($references, $retry_count + 1, $max_retries);
        }
        
        error_log("API Imbretex - ⚠️ ABANDON après $max_retries tentatives - Prix non récupérés");
        api_track_batch_failure();
        return null;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('API Imbretex - ✗ JSON invalide: ' . json_last_error_msg());
        api_track_batch_failure();
        return null;
    }
    
    if (!isset($data['products'])) {
        error_log('API Imbretex - ✗ Réponse sans clé "products"');
        api_track_batch_failure();
        return null;
    }
    
    $count = count($data['products']);
    $refs_count = count($references);
    error_log("API Imbretex - ✓ Batch réussi : $count/$refs_count prix récupérés");
    
    api_track_batch_success();
    return $data['products'];
}

/**
 * Récupération du prix/stock d'un seul produit
 */
function api_get_product_price_stock($reference) {
    if (empty($reference)) {
        return null;
    }
    
    $batch_result = api_get_products_price_stock_batch([$reference]);
    
    if ($batch_result && isset($batch_result[$reference])) {
        return $batch_result[$reference];
    }
    
    return null;
}

// ============================================================
// FONCTIONS DE MONITORING DES ÉCHECS API (NOUVEAU v8.4.1)
// ============================================================

/**
 * Tracker les échecs API pour monitoring
 */
function api_track_batch_failure() {
    $failures = get_option('api_batch_failures', 0);
    $failures++;
    update_option('api_batch_failures', $failures);
    update_option('api_last_batch_failure', current_time('mysql'));
    
    // Alerte si trop d'échecs
    if ($failures >= 10) {
        error_log("API Imbretex - ⚠️⚠️⚠️ ALERTE : $failures échecs API consécutifs ! Vérifier l'état du serveur API.");
    }
}

/**
 * Réinitialiser le compteur après succès
 */
function api_track_batch_success() {
    $current_failures = get_option('api_batch_failures', 0);
    if ($current_failures > 0) {
        error_log("API Imbretex - ✓ Connexion API rétablie (après $current_failures échecs)");
        update_option('api_batch_failures', 0);
    }
}

// ============================================================
// HOOKS WOOCOMMERCE : TRACKING STATUT PRODUITS
// ============================================================

function api_update_wc_status($product_id, $wc_status = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $product = wc_get_product($product_id);
    
    if (!$product) {
        error_log("API Imbretex - Produit WC {$product_id} introuvable");
        return false;
    }
    
    $sku = $product->get_sku();
    
    if (!$sku) {
        error_log("API Imbretex - Produit {$product_id} n'a pas de SKU");
        return false;
    }
    
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE sku = %s OR wc_product_id = %d LIMIT 1",
        $sku,
        $product_id
    ), ARRAY_A);
    
    if (!$existing) {
        error_log("API Imbretex - Produit {$sku} (ID: {$product_id}) non trouvé dans la table imbretex_products");
        return false;
    }
    
    $update_data = [
        'wc_status' => $wc_status,
        'wc_status_updated_at' => current_time('mysql')
    ];
    
    if ($wc_status === 'deleted') {
        $update_data['wc_product_id'] = null;
        $update_data['imported'] = 0;
    }
    
    $updated = $wpdb->update(
        $table_name,
        $update_data,
        ['id' => $existing['id']],
        ['%s', '%s', '%d', '%d'],
        ['%d']
    );
    
    if ($updated !== false) {
        $status_label = $wc_status ?: 'NULL';
        error_log("API Imbretex - Statut WC mis à jour pour {$sku} : {$status_label}");
    }
    
    if ($product->is_type('variable')) {
        $variations = $product->get_children();
        
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            
            if ($variation) {
                $variation_sku = $variation->get_sku();
                
                if ($variation_sku) {
                    $wpdb->update(
                        $table_name,
                        [
                            'wc_status' => $wc_status,
                            'wc_status_updated_at' => current_time('mysql'),
                            'wc_product_id' => $wc_status === 'deleted' ? null : $variation_id,
                            'imported' => $wc_status === 'deleted' ? 0 : 1
                        ],
                        ['sku' => $variation_sku],
                        ['%s', '%s', '%d', '%d'],
                        ['%s']
                    );
                    
                    error_log("API Imbretex - Variation {$variation_sku} : wc_status = {$wc_status}");
                }
            }
        }
    }
    
    return true;
}

add_action('transition_post_status', 'api_handle_product_publish', 10, 3);

function api_handle_product_publish($new_status, $old_status, $post) {
    if (!$post || !in_array($post->post_type, ['product', 'product_variation'])) {
        return;
    }
    
    if ($new_status === 'publish' && $old_status !== 'publish') {
        api_update_wc_status($post->ID, 'publish');
        error_log("API Imbretex - Produit publié (ID: {$post->ID})");
    }
    
    if ($new_status === 'draft' && $old_status !== 'draft') {
        api_update_wc_status($post->ID, 'draft');
        error_log("API Imbretex - Produit mis en brouillon (ID: {$post->ID})");
    }
}

add_action('wp_trash_post', 'api_handle_product_trashed', 10, 1);

function api_handle_product_trashed($post_id) {
    $post = get_post($post_id);
    
    if (!$post || !in_array($post->post_type, ['product', 'product_variation'])) {
        return;
    }
    
    api_update_wc_status($post_id, 'trash');
    error_log("API Imbretex - Produit mis à la corbeille (ID: {$post_id})");
}

add_action('before_delete_post', 'api_handle_product_deleted', 10, 2);

function api_handle_product_deleted($post_id, $post) {
    if (!$post || !in_array($post->post_type, ['product', 'product_variation'])) {
        return;
    }
    
    api_update_wc_status($post_id, 'deleted');
    error_log("API Imbretex - Produit supprimé définitivement (ID: {$post_id})");
}

add_action('untrashed_post', 'api_handle_product_untrashed', 10, 1);

function api_handle_product_untrashed($post_id) {
    $post = get_post($post_id);
    
    if (!$post || !in_array($post->post_type, ['product', 'product_variation'])) {
        return;
    }
    
    api_update_wc_status($post_id, 'draft');
    error_log("API Imbretex - Produit restauré en brouillon (ID: {$post_id})");
}

add_action('woocommerce_before_delete_product', 'api_handle_wc_product_deleted', 10, 2);

function api_handle_wc_product_deleted($product_id, $product) {
    if (!$product) {
        return;
    }
    
    api_update_wc_status($product_id, 'deleted');
    error_log("API Imbretex - Produit WC supprimé (ID: {$product_id})");
}

// ============================================================
// FONCTIONS DE GESTION DE L'ÉTAT DE SYNCHRONISATION
// ============================================================

function api_get_persistent_sync_state() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $state = $wpdb->get_row("SELECT * FROM $table_name ORDER BY id DESC LIMIT 1", ARRAY_A);
    
    return $state;
}

function api_get_active_sync_state() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $state = $wpdb->get_row(
        "SELECT * FROM $table_name WHERE status IN ('running', 'paused', 'error') ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );
    
    return $state;
}

function api_get_last_completed_sync() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $state = $wpdb->get_row(
        "SELECT * FROM $table_name WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1",
        ARRAY_A
    );
    
    return $state;
}

function api_has_completed_initial_sync() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $count = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE is_initial_sync = 1 AND status = 'completed'"
    );
    
    return $count > 0;
}

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

function api_update_sync_state($id, $data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    $data['updated_at'] = current_time('mysql');
    
    return $wpdb->update($table_name, $data, ['id' => $id]);
}

function api_pause_sync_state($id, $reason = 'user_cancelled') {
    return api_update_sync_state($id, [
        'status' => 'paused',
        'stopped_at' => current_time('mysql'),
        'error_message' => $reason
    ]);
}

function api_error_sync_state($id, $error_message) {
    return api_update_sync_state($id, [
        'status' => 'error',
        'stopped_at' => current_time('mysql'),
        'error_message' => $error_message
    ]);
}

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

function api_reset_all_sync_states() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_sync_state';
    
    return $wpdb->query("TRUNCATE TABLE $table_name");
}

// ============================================================
// FONCTIONS DE GESTION DE LA TABLE PRODUITS
// ============================================================

function api_db_upsert_product($product_data) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $variant = $product_data['variants'][0] ?? null;
    if (!$variant) return false;
    
    $is_variable = count($product_data['variants']) > 1;
    
    if ($is_variable) {
        error_log("API Imbretex - Produit variable détecté pour référence {$product_data['reference']}");
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
    
    // ✅ OPTIMISATION : Collecter TOUTES les références de variantes
    $all_variant_references = [];
    if (isset($product_data['variants']) && is_array($product_data['variants'])) {
        foreach ($product_data['variants'] as $variant_item) {
            $variant_reference = $variant_item['variantReference'] ?? null;
            if ($variant_reference) {
                $all_variant_references[] = $variant_reference;
            }
        }
    }
    
    // ✅ UN SEUL APPEL API BATCH pour toutes les variantes (VERSION CORRIGÉE v8.4.1)
    $prices_batch = [];
    if (!empty($all_variant_references)) {
        $prices_batch = api_get_products_price_stock_batch($all_variant_references);
        
        if ($prices_batch === null) {
            // ⚠️ L'API a échoué après tous les retry
            error_log("API Imbretex - ⚠️ Prix indisponibles pour {$product_data['reference']} - Sauvegarde avec prix=0");
            $prices_batch = []; // Continuer quand même avec des prix à 0
        } elseif (empty($prices_batch)) {
            error_log("API Imbretex - ⚠️ Aucun prix retourné par l'API pour {$product_data['reference']}");
        } else {
            $count = count($prices_batch);
            $total = count($all_variant_references);
            error_log("API Imbretex - ✓ Prix récupérés : $count/$total variantes pour {$product_data['reference']}");
        }
    }
    
    // ✅ Appliquer les prix récupérés à chaque variante
    if (isset($product_data['variants']) && is_array($product_data['variants'])) {
        foreach ($product_data['variants'] as $index => &$variant_item) {
            $variant_reference = $variant_item['variantReference'] ?? null;
            
            if ($variant_reference && isset($prices_batch[$variant_reference])) {
                $price_data = $prices_batch[$variant_reference];
                
                if (isset($price_data['price'])) {
                    $variant_item['price'] = floatval($price_data['price']);
                } else {
                    $variant_item['price'] = 0;
                }
                
                if (isset($price_data['stock'])) {
                    $variant_item['stock'] = intval($price_data['stock']);
                    
                    if (isset($price_data['stock_supplier'])) {
                        $variant_item['stock'] += intval($price_data['stock_supplier']);
                    }
                } else {
                    $variant_item['stock'] = 0;
                }
            } else {
                $variant_item['price'] = 0;
                $variant_item['stock'] = 0;
            }
        }
        unset($variant_item);
    }
    
    $price = 0;
    $stock = 0;
    
    if (isset($prices_batch[$main_reference])) {
        $main_price_data = $prices_batch[$main_reference];
        
        if (isset($main_price_data['price'])) {
            $price = floatval($main_price_data['price']);
        }
        
        if (isset($main_price_data['stock'])) {
            $stock = intval($main_price_data['stock']);
        }
        
        if (isset($main_price_data['stock_supplier'])) {
            $stock += intval($main_price_data['stock_supplier']);
        }
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
    
    if (isset($filters['wc_status'])) {
        if ($filters['wc_status'] === 'null') {
            $where[] = 'wc_status IS NULL';
        } else {
            $where[] = 'wc_status = %s';
            $params[] = $filters['wc_status'];
        }
    }
    
    $where_clause = implode(' AND ', $where);
    $query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
    
    if (!empty($params)) {
        $query = $wpdb->prepare($query, $params);
    }
    
    return (int) $wpdb->get_var($query);
}

function api_db_count_by_wc_status($wc_status = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    if ($wc_status === null) {
        return intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE wc_status IS NULL AND is_deleted = 0"));
    }
    
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE wc_status = %s",
        $wc_status
    )));
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
            $main_reference = $variant['variantReference'];
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
// CRON : SYNCHRONISATION DES PRODUITS SUPPRIMÉS
// ============================================================

/**
 * 🔥 FONCTION CRON : Synchroniser les produits supprimés
 * Récupère tous les produits supprimés de l'API et marque ceux présents dans la BDD
 */
function api_sync_deleted_products() {
    error_log('API Imbretex - Cron : Début synchronisation produits supprimés');
    
    $deleted_list = api_fetch_all_deleted_products();
    
    if (empty($deleted_list)) {
        error_log('API Imbretex - Cron : Aucun produit supprimé trouvé dans l\'API');
        update_option('api_last_deleted_sync', current_time('mysql'));
        return;
    }
    
    error_log('API Imbretex - Cron : ' . count($deleted_list) . ' références supprimées récupérées');
    
    $marked_count = api_db_mark_products_as_deleted($deleted_list);
    
    if ($marked_count > 0) {
        error_log('API Imbretex - Cron : ' . $marked_count . ' produits marqués comme supprimés dans la BDD');
    } else {
        error_log('API Imbretex - Cron : Aucun produit à marquer (déjà à jour)');
    }
    
    update_option('api_last_deleted_sync', current_time('mysql'));
    update_option('api_last_deleted_sync_count', $marked_count);
    
    error_log('API Imbretex - Cron : Synchronisation produits supprimés terminée');
}

// Planifier le cron pour la synchronisation des suppressions (toutes les heures)
add_filter('cron_schedules', function($schedules) {
    $schedules['every_10_minutes'] = [
        'interval' => 600,
        'display'  => __('Every 10 Minutes')
    ];
    
    if (!isset($schedules['hourly'])) {
        $schedules['hourly'] = [
            'interval' => 3600,
            'display'  => __('Every Hour')
        ];
    }
    
    return $schedules;
});

add_action('init', function() {
    // Cron synchronisation incrémentale
    if (!wp_next_scheduled('api_daily_incremental_sync')) {
        wp_schedule_event(time(), 'every_10_minutes', 'api_daily_incremental_sync');
    }
    
    // Cron synchronisation des suppressions
    if (!wp_next_scheduled('api_hourly_deleted_sync')) {
        wp_schedule_event(time(), 'hourly', 'api_hourly_deleted_sync');
    }
});

// Action pour le cron incrémental
add_action('api_daily_incremental_sync', 'api_run_incremental_sync');

// Action pour le cron des suppressions
add_action('api_hourly_deleted_sync', 'api_sync_deleted_products');

// ============================================================
// GESTION DE L'ÉTAT DE LA SYNCHRONISATION (TRANSIENT)
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

function api_run_incremental_sync($is_manual = false) {
    if (!api_has_completed_initial_sync()) {
        if (!$is_manual) {
            error_log('API Imbretex Cron - Aucune synchronisation initiale complétée. Sync incrémentale ignorée.');
        }
        return false;
    }
    
    $active_state = api_get_active_sync_state();
    if ($active_state && $active_state['status'] === 'running') {
        if (!$is_manual) {
            error_log('API Imbretex Cron - Une synchronisation est déjà en cours.');
        }
        return false;
    }
    
    $last_completed = api_get_last_completed_sync();
    if (!$last_completed) {
        if (!$is_manual) {
            error_log('API Imbretex Cron - Impossible de trouver la dernière sync complète.');
        }
        return false;
    }
    
    $since_created = $last_completed['last_product_created_at'] ?? $last_completed['last_sync_completed'];
    $since_updated = $last_completed['last_product_updated_at'] ?? $last_completed['last_sync_completed'];
    
    $log_prefix = $is_manual ? 'Manuel' : 'Cron';
    error_log("API Imbretex $log_prefix - Démarrage sync incrémentale depuis created: $since_created, updated: $since_updated");
    
    $state_id = api_create_sync_state([
        'sync_type' => 'incremental',
        'status' => 'running',
        'is_initial_sync' => 0
    ]);
    
    return api_run_background_sync($since_created, $since_updated, $state_id, $is_manual);
}

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
            if ($state_id) {
                api_complete_sync_state($state_id, $last_created_at, $last_updated_at);
            }
            error_log("API Imbretex $log_prefix - Synchronisation terminée !");
            break;
        }
        
        $page++;
        usleep(100000);
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
    
    $wc_publish = api_db_count_by_wc_status('publish');
    $wc_draft = api_db_count_by_wc_status('draft');
    $wc_trash = api_db_count_by_wc_status('trash');
    $wc_deleted = api_db_count_by_wc_status('deleted');
    $wc_never_imported = api_db_count_by_wc_status(null);
    
    // ✅ NOUVEAU v8.4.1 : État API
    $api_failures = get_option('api_batch_failures', 0);
    $last_failure = get_option('api_last_batch_failure');
    
    $sync_status = api_get_sync_status();
    $is_running = $sync_status && $sync_status['status'] === 'running';
    
    $persistent_state = api_get_active_sync_state();
    $has_interrupted_sync = $persistent_state && in_array($persistent_state['status'], ['paused', 'error']);
    $has_completed_initial = api_has_completed_initial_sync();
    $last_completed_sync = api_get_last_completed_sync();
    
    $last_deleted_sync = get_option('api_last_deleted_sync');
    $last_deleted_count = get_option('api_last_deleted_sync_count', 0);
    $next_deleted_sync = wp_next_scheduled('api_hourly_deleted_sync');
    
    ?>
    <div class="wrap">
        <h1>🔄 Synchronisation API Imbretex <span style="font-size:14px;color:#666;">v8.4.1 - Batch + Cron + Error Handling</span></h1>
        
        <?php if ($is_running): ?>
        <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:20px 0;">
            <strong>⚠️ Synchronisation en cours...</strong>
            <p style="margin:5px 0 0 0;">
                Une synchronisation est en cours d'exécution en arrière-plan.
            </p>
        </div>
        <?php endif; ?>
        
        <?php if ($has_interrupted_sync && !$is_running): ?>
        <div style="background:#f8d7da;border-left:4px solid #dc3545;padding:15px;margin:20px 0;">
            <strong>⚠️ Synchronisation interrompue détectée !</strong>
            <p style="margin:5px 0 0 0;">
                Page <strong><?php echo $persistent_state['current_page']; ?>/<?php echo $persistent_state['total_pages']; ?></strong> 
                (<?php echo number_format($persistent_state['products_imported']); ?>/<?php echo number_format($persistent_state['total_products']); ?> produits).
            </p>
        </div>
        <?php endif; ?>
        
        <?php if (!$has_completed_initial && !$is_running): ?>
        <div style="background:#d1ecf1;border-left:4px solid #17a2b8;padding:15px;margin:20px 0;">
            <strong>ℹ️ Première synchronisation requise</strong>
            <p style="margin:5px 0 0 0;">
                Aucune synchronisation initiale n'a été complétée.
            </p>
        </div>
        <?php elseif ($has_completed_initial && $last_completed_sync): ?>
        <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin:20px 0;">
            <strong>✓ Synchronisation initiale complétée</strong>
            <p style="margin:5px 0 0 0;">
                Dernière synchronisation : <strong><?php echo $last_completed_sync['completed_at']; ?></strong>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Statistiques -->
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
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #00a32a;">
                <h3 style="margin:0 0 10px 0;color:#00a32a;font-size:14px;">🔗 Statut WooCommerce</h3>
                <div style="font-size:14px;line-height:1.8;">
                    <div>✅ Publiés : <strong><?php echo number_format($wc_publish); ?></strong></div>
                    <div>📝 Brouillons : <strong><?php echo number_format($wc_draft); ?></strong></div>
                    <div>🗑️ Corbeille : <strong><?php echo number_format($wc_trash); ?></strong></div>
                    <div>❌ Supprimés : <strong><?php echo number_format($wc_deleted); ?></strong></div>
                    <div style="color:#999;">⚪ Jamais : <strong><?php echo number_format($wc_never_imported); ?></strong></div>
                </div>
            </div>
            
            <!-- ✅ NOUVEAU v8.4.1 : Carte État API -->
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid <?php echo $api_failures >= 10 ? '#dc3232' : '#826eb4'; ?>;">
                <h3 style="margin:0 0 10px 0;color:<?php echo $api_failures >= 10 ? '#dc3232' : '#826eb4'; ?>;font-size:14px;">📡 État API Prix</h3>
                <?php if ($api_failures > 0): ?>
                    <p style="font-size:32px;margin:0;font-weight:bold;color:#dc3232;"><?php echo $api_failures; ?></p>
                    <small style="color:#dc3232;">Échecs consécutifs</small>
                    <?php if ($last_failure): ?>
                        <div style="margin-top:5px;font-size:11px;color:#666;">
                            Dernier : <?php echo date('d/m H:i', strtotime($last_failure)); ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="font-size:24px;margin:0;font-weight:bold;color:#46b450;">✓ OK</p>
                    <small>Connexion stable</small>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Zone de synchronisation -->
        <div style="background:#fff;padding:20px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h2>⚙️ Synchronisation</h2>
            
            <?php if (!$has_completed_initial && !$has_interrupted_sync): ?>
            <p style="color:#666;margin-bottom:15px;">
                <strong>Mode:</strong> Synchronisation initiale (COMPLÈTE)
            </p>
            <button type="button" id="start-initial-sync" class="button button-primary button-large" style="font-size:16px;padding:10px 30px;" <?php echo $is_running ? 'disabled' : ''; ?>>
                <?php echo $is_running ? '⏳ Synchronisation en cours...' : '🔄 Lancer la Synchronisation Initiale'; ?>
            </button>
            
            <?php else: ?>
            
            <?php if ($has_interrupted_sync && !$is_running): ?>
            <p style="color:#666;margin-bottom:15px;">
                <strong>Sync interrompue détectée !</strong>
            </p>
            <button type="button" id="resume-sync" class="button button-primary button-large" style="font-size:16px;padding:10px 30px;background:#28a745;border-color:#28a745;">
                ▶️ Reprendre (page <?php echo $persistent_state['current_page'] + 1; ?>)
            </button>
            
            <button type="button" id="restart-incremental-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">
                🔄 Recommencer en Incrémental
            </button>
            
            <?php else: ?>
            <p style="color:#666;margin-bottom:15px;">
                <strong>Mode:</strong> Synchronisation incrémentale (avec sinceCreated/sinceUpdated)
            </p>
            
            <button type="button" id="start-incremental-sync" class="button button-primary button-large" style="font-size:16px;padding:10px 30px;" <?php echo $is_running ? 'disabled' : ''; ?>>
                <?php echo $is_running ? '⏳ Synchronisation en cours...' : '🔄 Lancer Synchronisation Manuelle'; ?>
            </button>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($is_running): ?>
            <button type="button" id="cancel-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">
                ⛔ Annuler
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
            <?php $next_scheduled = wp_next_scheduled('api_daily_incremental_sync'); ?>
            <table class="widefat" style="max-width:800px;">
                <tr>
                    <td colspan="2"><h3 style="margin:10px 0;">📦 Sync Produits (Incrémentale)</h3></td>
                </tr>
                <tr>
                    <td><strong>Status:</strong></td>
                    <td>
                        <?php if ($has_completed_initial): ?>
                        <span style="color:#28a745;">✓ Actif (toutes les 10 minutes)</span>
                        <?php else: ?>
                        <span style="color:#dc3232;">✗ En attente synchronisation initiale</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Prochaine exécution:</strong></td>
                    <td><?php echo $next_scheduled ? date('d/m/Y H:i:s', $next_scheduled) : 'Non programmé'; ?></td>
                </tr>
                
                <tr>
                    <td colspan="2"><h3 style="margin:10px 0;">🗑️ Sync Produits Supprimés</h3></td>
                </tr>
                <tr>
                    <td><strong>Status:</strong></td>
                    <td><span style="color:#28a745;">✓ Actif (toutes les heures)</span></td>
                </tr>
                <tr>
                    <td><strong>Prochaine exécution:</strong></td>
                    <td><?php echo $next_deleted_sync ? date('d/m/Y H:i:s', $next_deleted_sync) : 'Non programmé'; ?></td>
                </tr>
                <?php if ($last_deleted_sync): ?>
                <tr>
                    <td><strong>Dernière sync:</strong></td>
                    <td><?php echo $last_deleted_sync; ?></td>
                </tr>
                <tr>
                    <td><strong>Produits marqués:</strong></td>
                    <td><strong><?php echo number_format($last_deleted_count); ?></strong> produits</td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <!-- Logs de synchronisation -->
        <div style="background:#fff;padding:20px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h2>📋 Logs de Synchronisation</h2>
            <div id="sync-logs" style="background:#f9f9f9;padding:15px;border:1px solid #ddd;border-radius:3px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:12px;">
                <p style="color:#999;">En attente...</p>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($){
        var pollingInterval = null;
        var isRunning = <?php echo $is_running ? 'true' : 'false'; ?>;
        var persistentStateId = <?php echo $persistent_state ? intval($persistent_state['id']) : 'null'; ?>;
        var resumePage = <?php echo $persistent_state ? intval($persistent_state['current_page']) + 1 : 1; ?>;
        var lastStatusUpdate = null;
        var watchdogInterval = null;
        var watchdogTimeout = 30000;
        var currentProcessingPage = null;
        
        function updateUI(status, logs) {
            if (!status) {
                $('#sync-progress-container').hide();
                $('#sync-logs').html('<p style="color:#999;">En attente...</p>');
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
            
            if (lastStatus === currentStatus && (now - lastCheck) > watchdogTimeout) {
                console.warn('⚠️ BLOCAGE DÉTECTÉ ! Relance...');
                
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
            
            if (syncRetryCount <= maxSyncRetries) {
                setTimeout(function() {
                    processSyncPage(pageNum);
                }, 3000);
            } else {
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
            $('#sync-status').text('Démarrage...').css('color', '#2271b1');
            
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
                        $btn.text('⏳ En cours...');
                        
                        var cancelBtn = '<button type="button" id="cancel-sync" class="button button-secondary button-large" style="font-size:16px;padding:10px 30px;margin-left:10px;">⛔ Annuler</button>';
                        $btn.after(cancelBtn);
                        
                        setTimeout(function() {
                            processSyncPage(startPage);
                        }, 1000);
                    } else {
                        alert('Erreur: ' + (response.data.message || 'Impossible de démarrer'));
                        $btn.prop('disabled', false).text('🔄 Synchroniser');
                        if (pollingInterval) clearInterval(pollingInterval);
                        if (watchdogInterval) clearInterval(watchdogInterval);
                    }
                },
                error: function() {
                    alert('Erreur réseau');
                    $btn.prop('disabled', false).text('🔄 Synchroniser');
                    if (pollingInterval) clearInterval(pollingInterval);
                    if (watchdogInterval) clearInterval(watchdogInterval);
                }
            });
        }
        
        $('#start-initial-sync').on('click', function() {
            startSync(1, 'initial');
        });
        
        $('#start-incremental-sync').on('click', function() {
            startSync(1, 'incremental');
        });
        
        $('#resume-sync').on('click', function() {
            startSync(resumePage, 'resume');
        });
        
        $('#restart-incremental-sync').on('click', function() {
            if (!confirm('Recommencer en mode incrémental ?')) return;
            startSync(1, 'incremental');
        });
        
        $(document).on('click', '#cancel-sync', function() {
            if (!confirm('Annuler ?')) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_cancel_sync'
                },
                success: function(response) {
                    if (response.success) {
                        if (pollingInterval) clearInterval(pollingInterval);
                        if (watchdogInterval) clearInterval(watchdogInterval);
                        localStorage.removeItem('api_sync_last_check');
                        localStorage.removeItem('api_sync_last_status');
                        location.reload();
                    }
                }
            });
        });
        
        $('#clear-all').on('click', function() {
            if (isRunning) {
                alert('⚠️ Impossible pendant sync.');
                return;
            }
            
            if (!confirm('⚠️ Vider table ?')) return;
            
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
                        alert('✓ Table vidée !');
                        location.reload();
                    } else {
                        alert('✗ Erreur');
                        $btn.prop('disabled', false).text('🗑️ Vider');
                    }
                }
            });
        });
        
        $('#reset-state').on('click', function() {
            if (isRunning) {
                alert('⚠️ Impossible pendant sync.');
                return;
            }
            
            if (!confirm('⚠️ Réinitialiser ?')) return;
            
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
                        alert('✗ Erreur');
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
        wp_send_json_error(['message' => 'Sync déjà en cours']);
        return;
    }
    
    $sync_type = isset($_POST['sync_type']) ? sanitize_text_field($_POST['sync_type']) : 'initial';
    $is_resume = isset($_POST['resume']) && $_POST['resume'] == 1;
    $start_page = isset($_POST['start_page']) ? intval($_POST['start_page']) : 1;
    
    delete_transient('api_sync_logs');
    
    $since_created = null;
    $since_updated = null;
    
    if ($sync_type === 'incremental') {
        $last_completed = api_get_last_completed_sync();
        if ($last_completed) {
            $since_created = $last_completed['last_product_created_at'] ?? $last_completed['last_sync_completed'];
            $since_updated = $last_completed['last_product_updated_at'] ?? $last_completed['last_sync_completed'];
        }
    }
    
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
        api_add_sync_log('▶️ Reprise...', 'info');
    } elseif ($sync_type === 'incremental') {
        api_add_sync_log('🔄 Sync incrémentale...', 'info');
    } else {
        api_add_sync_log('🚀 Sync initiale...', 'info');
    }
    
    wp_send_json_success(['message' => 'Démarrée', 'state_id' => $state_id]);
});

add_action('wp_ajax_api_process_single_page', function() {
    set_time_limit(120);
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    
    $status = api_get_sync_status();
    
    if (!$status || $status['status'] !== 'running') {
        wp_send_json_error(['message' => 'Aucune sync en cours']);
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
    
    api_add_sync_log('⛔ Annulée', 'error');
    
    wp_send_json_success();
});

add_action('wp_ajax_api_clear_table', function() {
    $result = api_db_truncate_table();
    
    if ($result !== false) {
        delete_option('api_sync_db_marked_count');
        api_reset_all_sync_states();
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => 'Erreur suppression']);
    }
});

add_action('wp_ajax_api_reset_sync_state', function() {
    $result = api_reset_all_sync_states();
    api_reset_sync_status();
    delete_transient('api_sync_logs');
    
    if ($result !== false) {
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => 'Erreur réinitialisation']);
    }
});

// ============================================================
// DÉSACTIVATION DU CRON
// ============================================================
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('api_daily_incremental_sync');
    wp_clear_scheduled_hook('api_hourly_deleted_sync');
});