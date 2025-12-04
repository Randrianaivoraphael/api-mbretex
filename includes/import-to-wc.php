<?php
/**
 * Module : Importation vers WooCommerce (v7.3 - Avec gestion produits supprimés)
 * 
 * MODIFICATIONS v7.3 :
 * - Affichage visuel des produits supprimés (is_deleted = 1)
 * - Nouveau filtre "Statut suppression"
 * - Gestion du nouveau format de retour de api_create_woocommerce_product_full()
 * - Affichage des raisons d'échec détaillées
 * - Avertissement avant import de produits supprimés
 * 
 * Cette page permet d'importer les produits DEPUIS la table wp_imbretex_products
 * Les produits ont été synchronisés au préalable via la page "Synchronisation"
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// AJOUTER LE SOUS-MENU
// ============================================================
add_action('admin_menu', function() {
    add_submenu_page(
        'api-products-list',
        'Importation vers WooCommerce',
        '➡️ Import vers WC',
        'manage_woocommerce',
        'api-import-to-wc',
        'api_import_to_wc_page'
    );
}, 11);

// ============================================================
// FONCTIONS BASE DE DONNÉES (MODIFIÉES)
// ============================================================

// Récupérer les produits avec filtres et pagination
function api_db_get_products_filtered($filters = [], $limit = 10, $offset = 0) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $where = ['1=1'];
    $params = [];
    
    // Filtre SKU
    if (!empty($filters['sku'])) {
        $where[] = 'sku LIKE %s';
        $params[] = '%' . $wpdb->esc_like($filters['sku']) . '%';
    }
    
    // Filtre Nom
    if (!empty($filters['name'])) {
        $where[] = 'name LIKE %s';
        $params[] = '%' . $wpdb->esc_like($filters['name']) . '%';
    }
    
    // Filtre Marque
    if (!empty($filters['brand'])) {
        $where[] = 'brand = %s';
        $params[] = $filters['brand'];
    }
    
    // Filtre Catégorie
    if (!empty($filters['category'])) {
        $where[] = 'category LIKE %s';
        $params[] = '%' . $wpdb->esc_like($filters['category']) . '%';
    }
    
    // Filtre Statut
    if (!empty($filters['status'])) {
        $where[] = 'status = %s';
        $params[] = $filters['status'];
    }
    
    // Filtre Importé
    if (isset($filters['imported']) && $filters['imported'] !== '') {
        $where[] = 'imported = %d';
        $params[] = intval($filters['imported']);
    }
    
    // NOUVEAU : Filtre is_deleted
    if (isset($filters['is_deleted']) && $filters['is_deleted'] !== '') {
        $where[] = 'is_deleted = %d';
        $params[] = intval($filters['is_deleted']);
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Ajouter limit et offset
    $params[] = $limit;
    $params[] = $offset;
    
    $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY synced_at DESC LIMIT %d OFFSET %d";
    
    if (!empty($params)) {
        $query = $wpdb->prepare($query, $params);
    }
    
    return $wpdb->get_results($query, ARRAY_A);
}

// Compter les produits avec filtres
function api_db_count_products_filtered($filters = []) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $where = ['1=1'];
    $params = [];
    
    if (!empty($filters['sku'])) {
        $where[] = 'sku LIKE %s';
        $params[] = '%' . $wpdb->esc_like($filters['sku']) . '%';
    }
    
    if (!empty($filters['name'])) {
        $where[] = 'name LIKE %s';
        $params[] = '%' . $wpdb->esc_like($filters['name']) . '%';
    }
    
    if (!empty($filters['brand'])) {
        $where[] = 'brand = %s';
        $params[] = $filters['brand'];
    }
    
    if (!empty($filters['category'])) {
        $where[] = 'category LIKE %s';
        $params[] = '%' . $wpdb->esc_like($filters['category']) . '%';
    }
    
    if (!empty($filters['status'])) {
        $where[] = 'status = %s';
        $params[] = $filters['status'];
    }
    
    if (isset($filters['imported']) && $filters['imported'] !== '') {
        $where[] = 'imported = %d';
        $params[] = intval($filters['imported']);
    }
    
    // NOUVEAU : Filtre is_deleted
    if (isset($filters['is_deleted']) && $filters['is_deleted'] !== '') {
        $where[] = 'is_deleted = %d';
        $params[] = intval($filters['is_deleted']);
    }
    
    $where_clause = implode(' AND ', $where);
    
    $query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
    
    if (!empty($params)) {
        $query = $wpdb->prepare($query, $params);
    }
    
    return intval($wpdb->get_var($query));
}

// Récupérer toutes les marques distinctes
function api_db_get_all_brands() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $brands = $wpdb->get_col("SELECT DISTINCT brand FROM $table_name WHERE brand != '' ORDER BY brand ASC");
    
    return $brands;
}

// Récupérer toutes les catégories distinctes
function api_db_get_all_categories() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $categories = $wpdb->get_col("SELECT DISTINCT category FROM $table_name WHERE category != '' ORDER BY category ASC");
    
    return $categories;
}

// Récupérer un produit par ID
function api_db_get_product_by_id($id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $id
    ), ARRAY_A);
}

// ============================================================
// AJAX : IMPORTER UN PRODUIT (MODIFIÉ)
// ============================================================
add_action('wp_ajax_api_import_product_from_db', function() {
    check_ajax_referer('api_import_wc_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    
    // Récupérer le produit depuis la base
    $product_db = api_db_get_product_by_id($product_id);
    
    if (!$product_db) {
        wp_send_json_error(['message' => 'Produit non trouvé dans la base']);
        return;
    }
    
    // NOUVELLE VÉRIFICATION : Produit supprimé
    if ($product_db['is_deleted'] == 1) {
        wp_send_json_error([
            'message' => 'Produit marqué comme supprimé (is_deleted = 1)',
            'reason' => 'deleted',
            'sku' => $product_db['sku']
        ]);
        return;
    }
    
    // Décoder le product_data JSON
    $product_data = json_decode($product_db['product_data'], true);
    
    if (!$product_data) {
        wp_send_json_error(['message' => 'Données produit invalides']);
        return;
    }
    
    try {
        // Utiliser la fonction d'import (nouveau format de retour)
        $result = api_create_woocommerce_product_full($product_data, null);
        
        // Gérer le nouveau format de retour
        if (is_array($result)) {
            if ($result['success']) {
                $wc_product_id = $result['product_id'];
                
                // Mettre à jour le statut dans la base
                global $wpdb;
                $table_name = $wpdb->prefix . 'imbretex_products';
                
                $wpdb->update(
                    $table_name,
                    [
                        'imported' => 1,
                        'wc_product_id' => $wc_product_id
                    ],
                    ['id' => $product_id],
                    ['%d', '%d'],
                    ['%d']
                );
                
                wp_send_json_success([
                    'sku' => $product_db['sku'],
                    'name' => $product_db['name'],
                    'wc_product_id' => $wc_product_id,
                    'type' => $result['type'] ?? 'unknown'
                ]);
            } else {
                // Échec avec raison
                wp_send_json_error([
                    'message' => $result['message'] ?? 'Échec création produit',
                    'reason' => $result['reason'] ?? 'unknown',
                    'sku' => $result['sku'] ?? $product_db['sku']
                ]);
            }
        } else {
            // Format ancien (ID seulement) - retrocompatibilité
            if ($result) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'imbretex_products';
                
                $wpdb->update(
                    $table_name,
                    [
                        'imported' => 1,
                        'wc_product_id' => $result
                    ],
                    ['id' => $product_id],
                    ['%d', '%d'],
                    ['%d']
                );
                
                wp_send_json_success([
                    'sku' => $product_db['sku'],
                    'name' => $product_db['name'],
                    'wc_product_id' => $result
                ]);
            } else {
                wp_send_json_error(['message' => 'Échec création produit WooCommerce']);
            }
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// ============================================================
// PAGE ADMIN (MODIFIÉE)
// ============================================================
function api_import_to_wc_page() {
    // Récupérer les filtres
    $filter_sku = $_GET['filter_sku'] ?? '';
    $filter_name = $_GET['filter_name'] ?? '';
    $filter_brand = $_GET['filter_brand'] ?? '';
    $filter_category = $_GET['filter_category'] ?? '';
    $filter_is_deleted = $_GET['filter_is_deleted'] ?? ''; // NOUVEAU
    
    // Pagination
    $items_per_page = intval($_GET['items_per_page'] ?? 20);
    $paged = intval($_GET['paged'] ?? 1);
    
    // Construire les filtres
    $filters = [];
    if ($filter_sku) $filters['sku'] = $filter_sku;
    if ($filter_name) $filters['name'] = $filter_name;
    if ($filter_brand) $filters['brand'] = $filter_brand;
    if ($filter_category) $filters['category'] = $filter_category;
    if ($filter_is_deleted !== '') $filters['is_deleted'] = $filter_is_deleted;
    
    // Compter total
    $total_items = api_db_count_products_filtered($filters);
    $total_pages = ceil($total_items / $items_per_page);
    
    // Récupérer les produits
    $offset = ($paged - 1) * $items_per_page;
    $products = api_db_get_products_filtered($filters, $items_per_page, $offset);
    
    // Récupérer les listes pour les dropdowns
    $all_brands = api_db_get_all_brands();
    $all_categories = api_db_get_all_categories();
    
    // Fonction pour générer URL pagination
    function get_pagination_url_wc($page_num, $params) {
        $params['paged'] = $page_num;
        return add_query_arg($params, admin_url('admin.php'));
    }
    
    $pagination_params = [
        'page' => 'api-import-to-wc',
        'filter_sku' => $filter_sku,
        'filter_name' => $filter_name,
        'filter_brand' => $filter_brand,
        'filter_category' => $filter_category,
        'filter_is_deleted' => $filter_is_deleted,
        'items_per_page' => $items_per_page
    ];
    
    ?>
    <div class="wrap">
        <h1>➡️ Importation vers WooCommerce</h1>
        
        <!-- Statistiques rapides (MODIFIÉES) -->
        <?php
        $stats_total = api_db_count_products_filtered(['is_deleted' => 0]);
        $stats_imported = api_db_count_products_filtered(['imported' => 1, 'is_deleted' => 0]);
        $stats_not_imported = api_db_count_products_filtered(['imported' => 0, 'is_deleted' => 0]);
        $stats_deleted = api_db_count_products_filtered(['is_deleted' => 1]);
        ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0;">
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #2271b1;">
                <h3 style="margin:0 0 5px 0;color:#2271b1;font-size:14px;">📦 Produits Actifs</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_total); ?></p>
            </div>
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #46b450;">
                <h3 style="margin:0 0 5px 0;color:#46b450;font-size:14px;">✓ Déjà Importés</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_imported); ?></p>
            </div>
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #dc3232;">
                <h3 style="margin:0 0 5px 0;color:#dc3232;font-size:14px;">⏳ À Importer</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_not_imported); ?></p>
            </div>
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #826eb4;">
                <h3 style="margin:0 0 5px 0;color:#826eb4;font-size:14px;">🗑️ Supprimés</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_deleted); ?></p>
            </div>
        </div>
        
        <!-- FILTRES (MODIFIÉS) -->
        <div style="background:#fff;padding:15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin:0 0 10px 0;color:#2271b1;font-size:16px;">🔍 Filtres</h3>
            <form method="get" action="">
                <input type="hidden" name="page" value="api-import-to-wc">
                
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:15px;">
                    <!-- SKU -->
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">SKU</label>
                        <input type="text" name="filter_sku" value="<?php echo esc_attr($filter_sku); ?>" 
                               placeholder="Rechercher..." style="width:100%;height:32px;">
                    </div>
                    
                    <!-- Nom -->
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Nom</label>
                        <input type="text" name="filter_name" value="<?php echo esc_attr($filter_name); ?>" 
                               placeholder="Rechercher..." style="width:100%;height:32px;">
                    </div>
                    
                    <!-- Marque (Dropdown) -->
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Marque</label>
                        <select name="filter_brand" style="width:100%;height:32px;">
                            <option value="">-- Toutes --</option>
                            <?php foreach ($all_brands as $brand): ?>
                                <option value="<?php echo esc_attr($brand); ?>" <?php selected($filter_brand, $brand); ?>>
                                    <?php echo esc_html($brand); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Catégorie (Dropdown) -->
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Catégorie</label>
                        <select name="filter_category" style="width:100%;height:32px;">
                            <option value="">-- Toutes --</option>
                            <?php foreach ($all_categories as $category): ?>
                                <option value="<?php echo esc_attr($category); ?>" <?php selected($filter_category, $category); ?>>
                                    <?php echo esc_html($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- NOUVEAU : Statut Suppression -->
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Statut</label>
                        <select name="filter_is_deleted" style="width:100%;height:32px;">
                            <option value="">-- Tous --</option>
                            <option value="0" <?php selected($filter_is_deleted, '0'); ?>>✅ Actifs</option>
                            <option value="1" <?php selected($filter_is_deleted, '1'); ?>>🗑️ Supprimés</option>
                        </select>
                    </div>
                    
                    <!-- Par page -->
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Par page</label>
                        <select name="items_per_page" style="width:100%;height:32px;">
                            <option value="10" <?php selected($items_per_page, 10); ?>>10</option>
                            <option value="20" <?php selected($items_per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($items_per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($items_per_page, 100); ?>>100</option>
                        </select>
                    </div>
                </div>
                
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="button button-primary">🔍 Filtrer</button>
                    <a href="<?php echo admin_url('admin.php?page=api-import-to-wc'); ?>" class="button button-secondary">🔄 Réinitialiser</a>
                </div>
            </form>
        </div>
        
        <!-- Info pagination -->
        <div style="background:#fff;padding:10px 15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;justify-content:space-between;align-items:center;">
            <p style="margin:0;">
                <strong>Total : <?php echo number_format($total_items); ?> produits</strong> | 
                Page <?php echo $paged; ?> sur <?php echo $total_pages; ?>
            </p>
            <button type="button" id="start-import" class="button button-primary" style="font-weight:600;">
                <span id="import-btn-text">✅ Importer vers WooCommerce</span>
            </button>
        </div>
        
        <!-- Modal import -->
        <div id="import-modal" style="display:none;">
            <div class="import-modal-content">
                <div class="import-loader">
                    <div class="import-spinner"></div>
                    <h2>📥 Importation en cours</h2>
                    <div id="current-product">Importation des produits...</div>
                    <div id="progress-info" style="margin-top:10px;color:#666;font-size:13px;"></div>
                </div>
                
                <div id="import-summary" style="display:none;">
                    <div class="summary-success">
                        <strong>✅ Importation terminée !</strong>
                        <p><span id="success-count">0</span> produit(s) importé(s) avec succès</p>
                    </div>
                    <div id="error-section" style="display:none;">
                        <div class="summary-errors">
                            <strong>⚠️ <span id="error-count">0</span> erreur(s)</strong>
                            <ul id="error-messages"></ul>
                        </div>
                    </div>
                    <button id="close-modal" class="button button-primary">Fermer</button>
                </div>
            </div>
        </div>
        
        <!-- Modal détails variantes -->
        <div id="variants-modal" style="display:none;">
            <div class="variants-modal-content">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                    <h2 style="margin:0;">📦 Détails du produit</h2>
                    <button id="close-variants-modal" class="button" style="font-size:18px;line-height:1;">×</button>
                </div>
                <pre id="variants-json" style="background:#f5f5f5;padding:15px;border-radius:5px;max-height:500px;overflow:auto;font-size:12px;"></pre>
            </div>
        </div>
        
        <form method="post" id="import-form">
            <div id="products-table-wrapper">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="select-all"></th>
                            <th style="width:120px;">SKU</th>
                            <th>Nom</th>
                            <th style="width:120px;">Marque</th>
                            <th style="width:120px;">Catégorie</th>
                            <th style="width:80px;">Variants</th>
                            <th style="width:100px;">Statut</th>
                            <th style="width:130px;">Synchronisé le</th>
                            <th style="width:100px;">Importé WC</th>
                            <th style="width:50px;">Info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr><td colspan="10" style="text-align:center;padding:30px;">
                                Aucun produit trouvé. 
                                <a href="<?php echo admin_url('admin.php?page=api-synchronization'); ?>">Synchroniser depuis l'API</a>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($products as $product):
                                $is_imported = $product['imported'] == 1;
                                $is_deleted = $product['is_deleted'] == 1;
                                $product_data = json_decode($product['product_data'], true);
                                $variants_count = isset($product_data['variants']) ? count($product_data['variants']) : 0;
                                $variants_json = json_encode($product_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                
                                $row_style = $is_deleted ? 'background:#fff0f0;opacity:0.7;' : '';
                            ?>
                            <tr style="<?php echo $row_style; ?>">
                                <td>
                                    <input type="checkbox" name="product_ids[]" value="<?php echo $product['id']; ?>" 
                                           class="product-checkbox" 
                                           data-imported="<?php echo $is_imported ? '1' : '0'; ?>"
                                           data-deleted="<?php echo $is_deleted ? '1' : '0'; ?>"
                                           <?php echo $is_deleted ? 'disabled title="Produit supprimé - Import impossible"' : ''; ?>>
                                </td>
                                <td>
                                    <?php echo esc_html($product['sku']); ?>
                                    <?php if ($is_deleted): ?>
                                        <br><span style="color:#d63638;font-size:11px;font-weight:600;">🗑️ SUPPRIMÉ</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($product['name']); ?></td>
                                <td><?php echo esc_html($product['brand']); ?></td>
                                <td><?php echo esc_html($product['category']); ?></td>
                                <td style="text-align:center;">
                                    <span style="background:#0073aa;color:white;padding:2px 8px;border-radius:3px;font-size:11px;">
                                        <?php echo $variants_count; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($is_deleted): ?>
                                        <span style="color:#d63638;font-weight:600;">🗑️ Supprimé</span>
                                        <?php if ($product['deleted_at']): ?>
                                            <br><small style="color:#999;"><?php echo date('d/m/Y', strtotime($product['deleted_at'])); ?></small>
                                        <?php endif; ?>
                                    <?php elseif ($product['status'] === 'new'): ?>
                                        <span style="color:#2271b1;">🆕 Nouveau</span>
                                    <?php else: ?>
                                        <span style="color:#f0b849;">🔄 MAJ</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($product['synced_at'])); ?></td>
                                <td>
                                    <?php if ($is_imported): ?>
                                        <span style="color:#46b450;">✓ Oui</span>
                                        <?php if ($product['wc_product_id']): ?>
                                            <a href="<?php echo admin_url('post.php?post=' . $product['wc_product_id'] . '&action=edit'); ?>" 
                                               target="_blank" title="Voir dans WC" style="margin-left:5px;">🔗</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#999;">➕ Non</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="button button-small view-variants" 
                                            data-variants='<?php echo esc_attr($variants_json); ?>' 
                                            title="Voir les détails">📋</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format($total_items); ?> éléments</span>
                        <span class="pagination-links">
                            <?php if ($paged > 1): ?>
                                <a class="first-page button" href="<?php echo esc_url(get_pagination_url_wc(1, $pagination_params)); ?>">«</a>
                                <a class="prev-page button" href="<?php echo esc_url(get_pagination_url_wc($paged - 1, $pagination_params)); ?>">‹</a>
                            <?php else: ?>
                                <span class="tablenav-pages-navspan button disabled">«</span>
                                <span class="tablenav-pages-navspan button disabled">‹</span>
                            <?php endif; ?>

                            <span class="paging-input">
                                <input class="current-page" id="current-page-selector" type="text" 
                                       name="paged" value="<?php echo $paged; ?>" size="<?php echo strlen($total_pages); ?>">
                                <span class="tablenav-paging-text"> sur <span class="total-pages"><?php echo $total_pages; ?></span></span>
                            </span>

                            <?php if ($paged < $total_pages): ?>
                                <a class="next-page button" href="<?php echo esc_url(get_pagination_url_wc($paged + 1, $pagination_params)); ?>">›</a>
                                <a class="last-page button" href="<?php echo esc_url(get_pagination_url_wc($total_pages, $pagination_params)); ?>">»</a>
                            <?php else: ?>
                                <span class="tablenav-pages-navspan button disabled">›</span>
                                <span class="tablenav-pages-navspan button disabled">»</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
    
    <script>
    jQuery(document).ready(function($){
        function updateImportButton() {
            var importedCount = 0;
            var notImportedCount = 0;
            var deletedCount = 0;
            
            $('.product-checkbox:checked').each(function() {
                if ($(this).data('deleted') == '1') {
                    deletedCount++;
                } else if ($(this).data('imported') == '1') {
                    importedCount++;
                } else {
                    notImportedCount++;
                }
            });
            
            var totalChecked = importedCount + notImportedCount + deletedCount;
            
            if (totalChecked === 0) {
                $('#import-btn-text').html('✅ Importer vers WooCommerce');
                $('#start-import').prop('disabled', false);
            } else if (deletedCount > 0) {
                $('#import-btn-text').html('⚠️ ' + deletedCount + ' produit(s) supprimé(s) sélectionné(s)');
                $('#start-import').prop('disabled', true);
            } else if (notImportedCount > 0 && importedCount > 0) {
                $('#import-btn-text').html('✅ Importer/Mettre à jour (' + totalChecked + ')');
                $('#start-import').prop('disabled', false);
            } else if (importedCount > 0 && notImportedCount === 0) {
                $('#import-btn-text').html('🔄 Mettre à jour (' + totalChecked + ')');
                $('#start-import').prop('disabled', false);
            } else {
                $('#import-btn-text').html('➕ Importer (' + totalChecked + ')');
                $('#start-import').prop('disabled', false);
            }
        }
        
        $('#select-all').on('change', function() {
            $('.product-checkbox:not(:disabled)').prop('checked', $(this).prop('checked'));
            updateImportButton();
        });
        
        $('.product-checkbox').on('change', function() {
            if (!$(this).prop('checked')) {
                $('#select-all').prop('checked', false);
            }
            updateImportButton();
        });
        
        // Voir détails produit
        $('.view-variants').on('click', function() {
            var variants = $(this).data('variants');
            if (typeof variants === 'object') {
                $('#variants-json').text(JSON.stringify(variants, null, 2));
            } else {
                $('#variants-json').text(variants);
            }
            $('#variants-modal').fadeIn(200);
        });
        
        $('#close-variants-modal').on('click', function() {
            $('#variants-modal').fadeOut(200);
        });
        
        $('#variants-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).fadeOut(200);
            }
        });
        
        // Navigation pagination
        $('#current-page-selector').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                var page = parseInt($(this).val());
                var maxPage = parseInt($('.total-pages').text());
                
                if (page > 0 && page <= maxPage) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('paged', page);
                    window.location.href = url.toString();
                }
            }
        });
        
        // IMPORT AJAX (MODIFIÉ - gestion nouveau format)
        $('#start-import').on('click', function() {
            var selectedIds = [];
            $('.product-checkbox:checked:not(:disabled)').each(function() {
                selectedIds.push(parseInt($(this).val()));
            });

            if (selectedIds.length === 0) {
                alert('Veuillez sélectionner au moins un produit');
                return;
            }

            // Afficher modal
            $('#import-modal').fadeIn(200);
            $('.import-loader').show();
            $('#import-summary').hide();

            var imported = 0;
            var errors = 0;
            var errorMessages = [];
            var totalProducts = selectedIds.length;

            function importNext(index) {
                if (index >= totalProducts) {
                    // Terminé
                    $('.import-loader').hide();
                    $('#success-count').text(imported);
                    $('#error-count').text(errors);
                    
                    if (errors > 0) {
                        $('#error-section').show();
                        errorMessages.forEach(function(msg) {
                            $('#error-messages').append('<li>' + msg + '</li>');
                        });
                    } else {
                        $('#error-section').hide();
                    }
                    
                    $('#import-summary').show();
                    return;
                }

                var productId = selectedIds[index];
                var currentNum = index + 1;
                
                $('#current-product').text('Import produit ' + currentNum + ' sur ' + totalProducts + '...');
                $('#progress-info').text('ID: ' + productId);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'api_import_product_from_db',
                        nonce: '<?php echo wp_create_nonce('api_import_wc_nonce'); ?>',
                        product_id: productId
                    },
                    success: function(response) {
                        if (response.success) {
                            imported++;
                        } else {
                            errors++;
                            var errorMsg = 'ID ' + productId;
                            if (response.data.sku) {
                                errorMsg = 'SKU ' + response.data.sku;
                            }
                            
                            // Ajouter la raison d'échec
                            if (response.data.reason === 'deleted') {
                                errorMsg += ' : 🗑️ Produit supprimé';
                            } else if (response.data.message) {
                                errorMsg += ' : ' + response.data.message;
                            } else {
                                errorMsg += ' : Erreur inconnue';
                            }
                            
                            errorMessages.push(errorMsg);
                        }
                    },
                    error: function() {
                        errors++;
                        errorMessages.push('ID ' + productId + ' : Erreur réseau');
                    },
                    complete: function() {
                        importNext(index + 1);
                    }
                });
            }

            // Démarrer
            importNext(0);
        });

        $('#close-modal').on('click', function() {
            $('#import-modal').fadeOut(200);
            location.reload();
        });
    });
    </script>
    
    <style>
    /* Styles généraux */
    .wp-list-table th { 
        font-weight:600; 
        background:#f0f0f1; 
        padding:8px 10px; 
    }
    .wp-list-table td { 
        vertical-align:middle; 
        padding:8px; 
    }
    .wp-list-table tbody tr:hover { 
        background:#f6f7f7; 
    }
    
    /* Modal import */
    #import-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .import-modal-content {
        background: white;
        padding: 40px;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        text-align: center;
    }

    .import-spinner {
        border: 6px solid #f3f3f3;
        border-top: 6px solid #2271b1;
        border-radius: 50%;
        width: 60px;
        height: 60px;
        animation: spin 1s linear infinite;
        margin: 0 auto 30px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .import-loader h2 {
        margin: 0 0 20px 0;
        color: #2271b1;
        font-size: 24px;
    }

    #current-product {
        padding: 15px;
        background: #f0f6ff;
        border-radius: 8px;
        margin: 20px 0;
        font-size: 15px;
        color: #2271b1;
        font-weight: 500;
    }
    
    #progress-info {
        font-size: 12px;
        color: #666;
        margin-top: 10px;
    }

    .summary-success {
        padding: 20px;
        background: #e7f7e7;
        border-radius: 8px;
        margin-bottom: 15px;
    }

    .summary-success strong {
        color: #46b450;
        font-size: 18px;
        display: block;
        margin-bottom: 10px;
    }

    .summary-errors {
        padding: 20px;
        background: #fff0f0;
        border-left: 4px solid #dc3232;
        border-radius: 8px;
        text-align: left;
    }

    #error-messages {
        max-height: 150px;
        overflow-y: auto;
        margin: 10px 0 0 0;
        padding-left: 20px;
    }

    #close-modal {
        width: 100%;
        padding: 15px;
        font-size: 16px;
        font-weight: 600;
        margin-top: 20px;
    }

    /* Modal variantes */
    #variants-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        z-index: 999998;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .variants-modal-content {
        background: white;
        padding: 30px;
        border-radius: 12px;
        width: 90%;
        max-width: 800px;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }

    #variants-json {
        background: #f5f5f5;
        border: 1px solid #ddd;
        padding: 15px;
        border-radius: 5px;
        max-height: 500px;
        overflow: auto;
        font-family: 'Courier New', monospace;
        font-size: 12px;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    /* Pagination */
    .tablenav {
        margin: 15px 0;
    }

    .tablenav-pages {
        float: right;
    }

    .pagination-links {
        white-space: nowrap;
        display: inline-block;
        margin-left: 10px;
    }

    .pagination-links .button {
        margin-left: 2px;
        padding: 4px 8px;
        font-size: 13px;
    }

    .current-page {
        width: 50px;
        text-align: center;
        margin: 0 4px;
    }

    .displaying-num {
        margin-right: 10px;
        padding: 4px 0;
        font-size: 13px;
        color: #646970;
    }
    </style>
    <?php
}