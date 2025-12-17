<?php
/**
 * Module : Prix et Marges par Catégorie (v1.3 - Select2 + Auto-reload)
 * 
 * MODIFICATIONS v1.3 :
 * - Ajout de Select2 pour la recherche dans le select des catégories
 * - Actualisation automatique de la page après l'enregistrement d'une marge
 * - Amélioration de l'UX
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// FONCTION : SAUVEGARDER LES MARGES
// ============================================================
function api_save_category_margins($margins) {
    update_option('api_imbretex_category_margins', $margins);
}

// ============================================================
// FONCTION : RÉCUPÉRER TOUTES LES MARGES
// ============================================================
function api_get_category_margins() {
    return get_option('api_imbretex_category_margins', []);
}

// ============================================================
// FONCTION : RÉCUPÉRER LA MARGE D'UNE CATÉGORIE
// ============================================================
function api_get_category_margin($category) {
    $margins = api_get_category_margins();
    return isset($margins[$category]) ? floatval($margins[$category]) : 0;
}

// ============================================================
// FONCTION : CALCULER LE PRIX AVEC MARGE
// ============================================================
function api_calculate_price_with_margin($base_price, $category) {
    $margin_percent = api_get_category_margin($category);
    $multiplier = 1 + ($margin_percent / 100);
    return $base_price * $multiplier;
}

// ============================================================
// NOUVELLE FONCTION : METTRE À JOUR LES PRIX WC D'UNE CATÉGORIE
// ============================================================
function api_update_wc_prices_for_category($category) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    error_log("API Imbretex - Début mise à jour des prix WC pour catégorie: {$category}");
    
    $products = $wpdb->get_results($wpdb->prepare(
        "SELECT id, sku, price, wc_product_id, product_data 
         FROM $table_name 
         WHERE category = %s 
         AND imported = 1 
         AND is_deleted = 0 
         AND wc_product_id IS NOT NULL 
         AND wc_product_id > 0",
        $category
    ), ARRAY_A);
    
    if (empty($products)) {
        error_log("API Imbretex - Aucun produit WC trouvé pour la catégorie: {$category}");
        return [
            'updated' => 0, 
            'errors' => 0, 
            'message' => 'Aucun produit WooCommerce trouvé pour cette catégorie'
        ];
    }
    
    $margin_percent = api_get_category_margin($category);
    $updated_count = 0;
    $errors_count = 0;
    $updated_products = [];
    
    error_log("API Imbretex - " . count($products) . " produits à traiter, marge: {$margin_percent}%");
    
    foreach ($products as $product_db) {
        $wc_product_id = intval($product_db['wc_product_id']);
        $base_price = floatval($product_db['price']);
        $sku = $product_db['sku'];
        
        if ($base_price <= 0) {
            error_log("API Imbretex - SKU {$sku}: Prix de base invalide, ignoré");
            continue;
        }
        
        $wc_product = wc_get_product($wc_product_id);
        if (!$wc_product) {
            error_log("API Imbretex - SKU {$sku}: Produit WC non trouvé (ID: {$wc_product_id})");
            $errors_count++;
            continue;
        }
        
        $new_price = $base_price;
        if ($margin_percent > 0) {
            $new_price = api_calculate_price_with_margin($base_price, $category);
        }
        
        // Appliquer l'arrondi psychologique si la fonction existe
        if (function_exists('api_round_price_to_5cents')) {
            $new_price = api_round_price_to_5cents($new_price);
        }
        
        error_log("API Imbretex - SKU {$sku}: Base={$base_price}€, Nouveau={$new_price}€, Type=" . $wc_product->get_type());
        
        try {
            if ($wc_product->is_type('variable')) {
                $product_data = json_decode($product_db['product_data'], true);
                $variations_updated = 0;
                
                if (isset($product_data['variants']) && is_array($product_data['variants'])) {
                    foreach ($product_data['variants'] as $variant) {
                        $variant_sku = $variant['variantReference'] ?? '';
                        if (!$variant_sku) continue;
                        
                        $variation_id = wc_get_product_id_by_sku($variant_sku);
                        if (!$variation_id) {
                            error_log("API Imbretex - Variation SKU {$variant_sku}: Non trouvée");
                            continue;
                        }
                        
                        $variation = new WC_Product_Variation($variation_id);
                        
                        $variant_base_price = 0;
                        if (isset($variant['base_price'])) {
                            $variant_base_price = floatval($variant['base_price']);
                        } elseif (isset($variant['price'])) {
                            $variant_base_price = floatval($variant['price']);
                        }
                        
                        if ($variant_base_price <= 0 && function_exists('api_get_product_price_stock')) {
                            $price_stock_data = api_get_product_price_stock($variant_sku);
                            if ($price_stock_data && isset($price_stock_data['price'])) {
                                $variant_base_price = floatval($price_stock_data['price']);
                            }
                        }
                        
                        if ($variant_base_price <= 0) {
                            error_log("API Imbretex - Variation SKU {$variant_sku}: Prix de base non trouvé");
                            continue;
                        }
                        
                        $variant_new_price = $variant_base_price;
                        if ($margin_percent > 0) {
                            $variant_new_price = api_calculate_price_with_margin($variant_base_price, $category);
                        }
                        
                        if (function_exists('api_round_price_to_5cents')) {
                            $variant_new_price = api_round_price_to_5cents($variant_new_price);
                        }
                        
                        $variation->set_regular_price($variant_new_price);
                        $variation->set_price($variant_new_price);
                        $variation->save();
                        
                        $variations_updated++;
                        error_log("API Imbretex - Variation SKU {$variant_sku}: Prix mis à jour {$variant_base_price}€ → {$variant_new_price}€");
                    }
                }
                
                WC_Product_Variable::sync($wc_product_id);
                
                if ($variations_updated > 0) {
                    $updated_count++;
                    $updated_products[] = [
                        'sku' => $sku,
                        'type' => 'variable',
                        'variations' => $variations_updated
                    ];
                    error_log("API Imbretex - SKU {$sku}: {$variations_updated} variations mises à jour");
                }
            } else {
                $wc_product->set_regular_price($new_price);
                $wc_product->set_price($new_price);
                $wc_product->save();
                
                $updated_count++;
                $updated_products[] = [
                    'sku' => $sku,
                    'type' => 'simple',
                    'old_price' => $base_price,
                    'new_price' => $new_price
                ];
                error_log("API Imbretex - SKU {$sku}: Prix mis à jour (simple)");
            }
        } catch (Exception $e) {
            error_log("API Imbretex - SKU {$sku}: Erreur lors de la mise à jour - " . $e->getMessage());
            $errors_count++;
        }
    }
    
    error_log("API Imbretex - Fin mise à jour: {$updated_count} produits mis à jour, {$errors_count} erreurs");
    
    return [
        'updated' => $updated_count,
        'errors' => $errors_count,
        'total' => count($products),
        'products' => $updated_products,
        'message' => "{$updated_count} produit(s) WooCommerce mis à jour"
    ];
}

// ============================================================
// AJAX : SAUVEGARDER LES MARGES (TOUTES)
// ============================================================
add_action('wp_ajax_api_save_margins', function() {
    check_ajax_referer('api_margins_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission refusée']);
        return;
    }
    
    $margins = isset($_POST['margins']) ? $_POST['margins'] : [];
    
    $old_margins = api_get_category_margins();
    
    $clean_margins = [];
    $changed_categories = [];
    
    foreach ($margins as $category => $margin) {
        $category_clean = sanitize_text_field($category);
        $margin_clean = floatval($margin);
        
        if ($margin_clean >= 0 && $margin_clean <= 1000) {
            $clean_margins[$category_clean] = $margin_clean;
            
            $old_margin = isset($old_margins[$category_clean]) ? floatval($old_margins[$category_clean]) : 0;
            if ($old_margin != $margin_clean) {
                $changed_categories[] = $category_clean;
            }
        }
    }
    
    api_save_category_margins($clean_margins);
    
    $wc_update_results = [];
    $total_updated = 0;
    $total_errors = 0;
    
    if (!empty($changed_categories)) {
        foreach ($changed_categories as $category) {
            $result = api_update_wc_prices_for_category($category);
            $wc_update_results[$category] = $result;
            $total_updated += $result['updated'];
            $total_errors += $result['errors'];
        }
    }
    
    $message = 'Marges sauvegardées avec succès';
    if ($total_updated > 0) {
        $message .= " - {$total_updated} produit(s) WooCommerce mis à jour";
    }
    if ($total_errors > 0) {
        $message .= " ({$total_errors} erreur(s))";
    }
    
    wp_send_json_success([
        'message' => $message,
        'count' => count($clean_margins),
        'changed_categories' => count($changed_categories),
        'wc_updated' => $total_updated,
        'wc_errors' => $total_errors,
        'details' => $wc_update_results
    ]);
});

// ============================================================
// AJAX : SAUVEGARDER UNE SEULE MARGE
// ============================================================
add_action('wp_ajax_api_save_single_margin', function() {
    check_ajax_referer('api_margins_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission refusée']);
        return;
    }
    
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $margin = isset($_POST['margin']) ? floatval($_POST['margin']) : 0;
    
    if (empty($category)) {
        wp_send_json_error(['message' => 'Catégorie invalide']);
        return;
    }
    
    if ($margin < 0 || $margin > 1000) {
        wp_send_json_error(['message' => 'Marge doit être entre 0% et 1000%']);
        return;
    }
    
    $old_margin = api_get_category_margin($category);
    
    $all_margins = api_get_category_margins();
    
    $all_margins[$category] = $margin;
    
    api_save_category_margins($all_margins);
    
    $wc_result = ['updated' => 0, 'errors' => 0];
    if ($old_margin != $margin) {
        $wc_result = api_update_wc_prices_for_category($category);
    }
    
    $message = 'Marge sauvegardée';
    if ($wc_result['updated'] > 0) {
        $message .= " - {$wc_result['updated']} produit(s) WooCommerce mis à jour";
    }
    if ($wc_result['errors'] > 0) {
        $message .= " ({$wc_result['errors']} erreur(s))";
    }
    
    wp_send_json_success([
        'message' => $message,
        'category' => $category,
        'margin' => $margin,
        'old_margin' => $old_margin,
        'wc_updated' => $wc_result['updated'],
        'wc_errors' => $wc_result['errors'],
        'wc_total' => $wc_result['total'] ?? 0,
        'wc_details' => $wc_result
    ]);
});

// ============================================================
// PAGE ADMIN : GESTION DES MARGES
// ============================================================
function api_prix_marge_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    
    if (!$table_exists) {
        ?>
        <div class="wrap">
            <h1>💰 Prix et Marges par Catégorie</h1>
            <div class="notice notice-warning" style="margin:20px 0;padding:15px;">
                <p style="font-size:15px;"><strong>⚠️ Table de synchronisation non trouvée</strong></p>
                <p>Veuillez d'abord <a href="<?php echo admin_url('admin.php?page=api-imbretex'); ?>" class="button button-primary">🔄 Synchroniser les produits depuis l'API</a> pour obtenir la liste des catégories.</p>
            </div>
        </div>
        <?php
        return;
    }
    
    $filter_category = isset($_GET['filter_category']) ? sanitize_text_field($_GET['filter_category']) : '';
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 10;
    
    $all_categories_list = $wpdb->get_col(
        "SELECT DISTINCT category 
         FROM $table_name 
         WHERE category != '' AND category IS NOT NULL AND is_deleted = 0 AND price > 0
         ORDER BY category ASC"
    );
    
    $where_clause = "WHERE category != '' AND category IS NOT NULL AND is_deleted = 0 AND price > 0";
    $params = [];
    
    if (!empty($filter_category)) {
        $where_clause .= " AND category = %s";
        $params[] = $filter_category;
    }
    
    $count_query = "SELECT COUNT(DISTINCT category) FROM $table_name " . $where_clause;
    if (!empty($params)) {
        $count_query = $wpdb->prepare($count_query, $params);
    }
    $total_categories_filtered = intval($wpdb->get_var($count_query));
    $total_pages = ceil($total_categories_filtered / $per_page);
    
    $offset = ($paged - 1) * $per_page;
    
    $query = "SELECT category, 
                COUNT(*) as product_count,
                AVG(price) as avg_price,
                MIN(price) as min_price,
                MAX(price) as max_price,
                SUM(CASE WHEN imported = 1 THEN 1 ELSE 0 END) as imported_count
         FROM $table_name 
         " . $where_clause . "
         GROUP BY category 
         ORDER BY category ASC
         LIMIT %d OFFSET %d";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $categories = $wpdb->get_results(
        $wpdb->prepare($query, $params),
        ARRAY_A
    );
    
    $saved_margins = api_get_category_margins();
    
    $stats_query = "SELECT COUNT(DISTINCT category) as total FROM $table_name WHERE category != '' AND category IS NOT NULL AND is_deleted = 0 AND price > 0";
    $total_categories = intval($wpdb->get_var($stats_query));
    
    $categories_with_margin = 0;
    $total_margin_sum = 0;
    
    foreach ($saved_margins as $margin) {
        if ($margin > 0) {
            $categories_with_margin++;
            $total_margin_sum += $margin;
        }
    }
    
    $average_margin = $categories_with_margin > 0 ? round($total_margin_sum / $categories_with_margin, 2) : 0;
    
    ?>
    <div class="wrap">
        <h1>💰 Prix et Marges par Catégorie Imbretex (v1.3)</h1>
        
        <div class="notice notice-info" style="margin:15px 0;padding:12px;">
            <p style="margin:0;">
                <strong>✨ v1.3 :</strong> Recherche dans les catégories + Actualisation automatique après sauvegarde
            </p>
        </div>
        
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0;">
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #2271b1;">
                <h3 style="margin:0 0 10px 0;color:#2271b1;font-size:14px;">📂 Total Catégories</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($total_categories); ?></p>
                <small>Catégories trouvées</small>
            </div>
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #46b450;">
                <h3 style="margin:0 0 10px 0;color:#46b450;font-size:14px;">✓ Avec Marge</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($categories_with_margin); ?></p>
                <small>Catégories configurées</small>
            </div>
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #dc3232;">
                <h3 style="margin:0 0 10px 0;color:#dc3232;font-size:14px;">⏳ Sans Marge</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($total_categories - $categories_with_margin); ?></p>
                <small>Marge = 0% par défaut</small>
            </div>
            
            <div style="background:#fff;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #826eb4;">
                <h3 style="margin:0 0 10px 0;color:#826eb4;font-size:14px;">📊 Marge Moyenne</h3>
                <p style="font-size:32px;margin:0;font-weight:bold;"><?php echo number_format($average_margin, 1); ?>%</p>
                <small>Sur les catégories configurées</small>
            </div>
        </div>
        
        <?php if (empty($categories)): ?>
            <div class="notice notice-warning" style="margin:20px 0;padding:15px;">
                <p style="font-size:15px;"><strong>⚠️ Aucune catégorie trouvée</strong></p>
                <?php if (!empty($filter_category)): ?>
                    <p>Aucune catégorie ne correspond au filtre "<?php echo esc_html($filter_category); ?>". 
                    <a href="<?php echo admin_url('admin.php?page=api-prix-marge'); ?>" class="button">Réinitialiser le filtre</a></p>
                <?php else: ?>
                    <p>Veuillez d'abord <a href="<?php echo admin_url('admin.php?page=api-imbretex'); ?>" class="button button-primary">🔄 Synchroniser les produits depuis l'API</a>.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Filtre par catégorie avec Select2 -->
            <div style="background:#fff;padding:15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <form method="get" action="">
                    <input type="hidden" name="page" value="api-prix-marge">
                    <div style="display:flex;align-items:center;gap:15px;">
                        <label style="font-weight:600;font-size:14px;">🔍 Filtrer par catégorie :</label>
                        <select name="filter_category" id="category-filter-select" style="width:400px;height:36px;font-size:14px;">
                            <option value="">-- Toutes les catégories (<?php echo count($all_categories_list); ?>) --</option>
                            <?php foreach ($all_categories_list as $cat_name): ?>
                                <option value="<?php echo esc_attr($cat_name); ?>" <?php selected($filter_category, $cat_name); ?>>
                                    <?php echo esc_html($cat_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary">Filtrer</button>
                        <?php if (!empty($filter_category)): ?>
                            <a href="<?php echo admin_url('admin.php?page=api-prix-marge'); ?>" class="button">🔄 Réinitialiser</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div style="background:#fff;padding:10px 15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;justify-content:space-between;align-items:center;">
                <p style="margin:0;">
                    <strong>Affichage : <?php echo count($categories); ?> catégorie(s)</strong> | 
                    Total : <?php echo number_format($total_categories_filtered); ?> | 
                    Page <?php echo $paged; ?> sur <?php echo $total_pages; ?>
                </p>
            </div>
            
            <form id="margins-form" method="post">
                <div style="background:#fff;padding:20px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                        <h2 style="margin:0;">📋 Configuration des Marges</h2>
                        <div>
                            <button type="button" id="apply-global-margin" class="button" style="margin-right:10px;">
                                🌍 Appliquer une marge globale
                            </button>
                            <button type="submit" class="button button-primary button-large">
                                💾 Enregistrer toutes les marges
                            </button>
                        </div>
                    </div>
                    
                    <div id="global-margin-input" style="display:none;background:#f0f6ff;padding:15px;border-radius:5px;margin-bottom:15px;border:2px solid #2271b1;">
                        <div style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;">
                            <label style="font-weight:600;font-size:14px;">🌍 Marge globale à appliquer :</label>
                            <input type="number" id="global-margin-value" step="0.01" min="0" max="1000" 
                                   placeholder="Ex: 30" style="width:100px;padding:8px;font-size:14px;">
                            <span style="font-weight:600;">%</span>
                            <button type="button" id="confirm-global-margin" class="button button-primary">✓ Appliquer à toutes</button>
                            <button type="button" id="cancel-global-margin" class="button">✗ Annuler</button>
                        </div>
                    </div>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:50px;text-align:center;">#</th>
                                <th style="width:200px;">Catégorie Imbretex</th>
                                <th style="width:150px;text-align:center;">
                                    Importés dans WooCommerce
                                    <span style="display:block;font-size:11px;font-weight:400;color:#666;margin-top:3px;">
                                        (produits synchronisés)
                                    </span>
                                </th>
                                <th style="width:250px;">Marge (%)</th>
                                <th style="width:100px;text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $index = 1;
                            foreach ($categories as $cat): 
                                $category = $cat['category'];
                                $product_count = $cat['product_count'];
                                $imported_count = $cat['imported_count'];
                                $avg_price = floatval($cat['avg_price']);
                                $current_margin = isset($saved_margins[$category]) ? $saved_margins[$category] : 0;
                            ?>
                            <tr data-category="<?php echo esc_attr($category); ?>">
                                <td style="text-align:center;font-weight:600;color:#666;">
                                    <?php echo $index++; ?>
                                </td>
                                <td>
                                    <strong style="font-size:14px;"><?php echo esc_html($category); ?></strong>
                                </td>
                                <td style="text-align:center;">
                                    <div style="display:flex;align-items:center;justify-content:center;gap:5px;">
                                        <span style="background:#46b450;color:white;padding:5px 14px;border-radius:4px;font-size:13px;font-weight:600;" 
                                              title="<?php echo $imported_count; ?> produit(s) déjà synchronisé(s) dans WooCommerce sur un total de <?php echo $product_count; ?> produit(s) disponibles">
                                            <?php echo number_format($imported_count); ?>
                                        </span>
                                        <span style="color:#999;font-size:13px;">/</span>
                                        <span style="background:#e0e0e0;color:#333;padding:5px 14px;border-radius:4px;font-size:13px;font-weight:600;" 
                                              title="Total de produits disponibles dans cette catégorie">
                                            <?php echo number_format($product_count); ?>
                                        </span>
                                    </div>
                                    <div style="margin-top:4px;font-size:10px;color:#666;">
                                        importé<?php echo $imported_count > 1 ? 's' : ''; ?> / total
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <input type="number" 
                                               name="margins[<?php echo esc_attr($category); ?>]" 
                                               value="<?php echo esc_attr($current_margin); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               max="1000"
                                               class="margin-input"
                                               data-category="<?php echo esc_attr($category); ?>"
                                               data-base-price="<?php echo esc_attr($avg_price); ?>"
                                               style="width:100px;padding:6px 10px;font-size:14px;"
                                               placeholder="0">
                                        <span style="font-weight:600;">%</span>
                                        <?php if ($current_margin > 0): ?>
                                            <span style="color:#46b450;font-size:12px;font-weight:600;">✓ Défini</span>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:12px;">Non défini</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="button button-primary save-single-margin" 
                                            data-category="<?php echo esc_attr($category); ?>"
                                            data-imported-count="<?php echo esc_attr($imported_count); ?>"
                                            style="padding:6px 12px;">
                                        💾 Enregistrer
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="tablenav bottom" style="margin-top:20px;">
                            <div class="tablenav-pages">
                                <span class="displaying-num"><?php echo number_format($total_categories_filtered); ?> catégorie(s)</span>
                                <span class="pagination-links">
                                    <?php
                                    $pagination_base = add_query_arg(['page' => 'api-prix-marge', 'filter_category' => $filter_category], admin_url('admin.php'));
                                    
                                    if ($paged > 1): ?>
                                        <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1, $pagination_base)); ?>">«</a>
                                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $paged - 1, $pagination_base)); ?>">‹</a>
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
                                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $paged + 1, $pagination_base)); ?>">›</a>
                                        <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $total_pages, $pagination_base)); ?>">»</a>
                                    <?php else: ?>
                                        <span class="tablenav-pages-navspan button disabled">›</span>
                                        <span class="tablenav-pages-navspan button disabled">»</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top:20px;text-align:right;">
                        <button type="submit" class="button button-primary button-large" style="padding:10px 30px;font-size:15px;">
                            💾 Enregistrer toutes les marges
                        </button>
                    </div>
                </div>
            </form>
            
            <div id="save-notification" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:30px;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,0.3);z-index:9999;min-width:400px;">
                <div style="text-align:center;">
                    <div class="save-spinner" style="border:4px solid #f3f3f3;border-top:4px solid #46b450;border-radius:50%;width:50px;height:50px;animation:spin 1s linear infinite;margin:0 auto 20px;"></div>
                    <p id="notification-message" style="margin:0;font-size:16px;font-weight:600;">Enregistrement en cours...</p>
                    <p id="notification-details" style="margin:10px 0 0 0;font-size:13px;color:#666;"></p>
                </div>
            </div>
            
            <div id="confirm-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;">
                <div style="background:white;padding:0;border-radius:12px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.4);">
                    <div id="confirm-header" style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);padding:25px;border-radius:12px 12px 0 0;text-align:center;">
                        <div style="font-size:48px;margin-bottom:10px;">⚠️</div>
                        <h3 style="margin:0;color:white;font-size:22px;font-weight:600;">Confirmation requise</h3>
                    </div>
                    
                    <div style="padding:30px;">
                        <div id="confirm-message" style="font-size:15px;line-height:1.6;color:#333;margin-bottom:20px;text-align:center;">
                        </div>
                        
                        <div id="confirm-details" style="background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:25px;border-left:4px solid #667eea;">
                            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                                <span style="font-size:24px;">📦</span>
                                <div>
                                    <div style="font-size:13px;color:#666;margin-bottom:3px;">Catégorie concernée</div>
                                    <div id="confirm-category" style="font-weight:600;color:#333;font-size:15px;"></div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="font-size:24px;">🔄</span>
                                <div>
                                    <div style="font-size:13px;color:#666;margin-bottom:3px;">Produits WooCommerce à mettre à jour</div>
                                    <div id="confirm-count" style="font-weight:700;color:#667eea;font-size:18px;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background:#fff3cd;padding:12px;border-radius:8px;margin-bottom:25px;border-left:4px solid #ffc107;">
                            <div style="display:flex;align-items:start;gap:10px;">
                                <span style="font-size:20px;">💡</span>
                                <div style="font-size:13px;color:#856404;line-height:1.5;">
                                    <strong>Information :</strong> Les prix de tous les produits déjà importés dans WooCommerce 
                                    seront automatiquement recalculés avec la nouvelle marge.
                                </div>
                            </div>
                        </div>
                        
                        <div style="display:flex;gap:12px;justify-content:center;">
                            <button id="confirm-cancel" class="button button-large" style="min-width:140px;padding:10px 25px;font-size:15px;">
                                ✗ Annuler
                            </button>
                            <button id="confirm-ok" class="button button-primary button-large" style="min-width:140px;padding:10px 25px;font-size:15px;background:#667eea;border-color:#667eea;">
                                ✓ Confirmer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="confirm-global-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;align-items:center;justify-content:center;">
                <div style="background:white;padding:0;border-radius:12px;max-width:550px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.4);">
                    <div style="background:linear-gradient(135deg, #f093fb 0%, #f5576c 100%);padding:25px;border-radius:12px 12px 0 0;text-align:center;">
                        <div style="font-size:48px;margin-bottom:10px;">⚡</div>
                        <h3 style="margin:0;color:white;font-size:22px;font-weight:600;">Mise à jour globale</h3>
                    </div>
                    
                    <div style="padding:30px;">
                        <div style="font-size:15px;line-height:1.6;color:#333;margin-bottom:25px;text-align:center;">
                            Vous êtes sur le point de mettre à jour <strong>plusieurs catégories</strong> en une seule fois.
                        </div>
                        
                        <div style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:25px;border-left:4px solid #f5576c;">
                            <div style="display:flex;align-items:center;gap:15px;margin-bottom:15px;">
                                <span style="font-size:32px;">🏷️</span>
                                <div>
                                    <div style="font-size:13px;color:#666;margin-bottom:3px;">Catégories modifiées</div>
                                    <div id="global-categories-count" style="font-weight:700;color:#f5576c;font-size:20px;"></div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:15px;">
                                <span style="font-size:32px;">🛍️</span>
                                <div>
                                    <div style="font-size:13px;color:#666;margin-bottom:3px;">Total produits WooCommerce concernés</div>
                                    <div id="global-products-count" style="font-weight:700;color:#f5576c;font-size:20px;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background:#d1ecf1;padding:12px;border-radius:8px;margin-bottom:25px;border-left:4px solid #0c5460;">
                            <div style="display:flex;align-items:start;gap:10px;">
                                <span style="font-size:20px;">⏱️</span>
                                <div style="font-size:13px;color:#0c5460;line-height:1.5;">
                                    <strong>Note :</strong> Cette opération peut prendre quelques instants selon le nombre de produits. 
                                    Merci de patienter jusqu'à la fin du traitement.
                                </div>
                            </div>
                        </div>
                        
                        <div style="display:flex;gap:12px;justify-content:center;">
                            <button id="global-confirm-cancel" class="button button-large" style="min-width:140px;padding:10px 25px;font-size:15px;">
                                ✗ Annuler
                            </button>
                            <button id="global-confirm-ok" class="button button-primary button-large" style="min-width:140px;padding:10px 25px;font-size:15px;background:#f5576c;border-color:#f5576c;">
                                ✓ Lancer la mise à jour
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
    
    <!-- Charger Select2 depuis CDN -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .wp-list-table th {
        font-weight: 600;
        background: #f0f0f1;
        padding: 12px 10px;
        font-size: 13px;
    }
    
    .wp-list-table td {
        vertical-align: middle;
        padding: 12px 10px;
    }
    
    .wp-list-table tbody tr:hover {
        background: #f6f7f7;
    }
    
    .margin-input {
        padding: 6px 10px;
        border: 2px solid #8c8f94;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
    }
    
    .margin-input:focus {
        border-color: #2271b1;
        outline: none;
        box-shadow: 0 0 0 1px #2271b1;
    }
    
    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button {
        opacity: 1;
    }
    
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
    
    /* Personnalisation Select2 */
    .select2-container .select2-selection--single {
        height: 36px !important;
        border: 1px solid #8c8f94 !important;
        border-radius: 4px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 34px !important;
        padding-left: 12px !important;
        font-size: 14px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 34px !important;
    }
    
    .select2-dropdown {
        border: 1px solid #8c8f94 !important;
        border-radius: 4px !important;
    }
    
    .select2-search--dropdown .select2-search__field {
        border: 1px solid #8c8f94 !important;
        border-radius: 4px !important;
        padding: 6px 12px !important;
        font-size: 14px !important;
    }
    
    .select2-results__option {
        padding: 8px 12px !important;
        font-size: 14px !important;
    }
    
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #2271b1 !important;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($){
        // ✅ Initialiser Select2 sur le select des catégories
        $('#category-filter-select').select2({
            placeholder: '-- Rechercher une catégorie --',
            allowClear: true,
            width: '400px',
            language: {
                noResults: function() {
                    return "Aucune catégorie trouvée";
                },
                searching: function() {
                    return "Recherche en cours...";
                },
                inputTooShort: function() {
                    return "Tapez pour rechercher";
                }
            }
        });
        
        $('#confirm-modal, #confirm-global-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).fadeOut(200);
            }
        });
        
        $('.save-single-margin').on('click', function() {
            var $btn = $(this);
            var category = $btn.data('category');
            var importedCount = parseInt($btn.data('imported-count')) || 0;
            var $row = $btn.closest('tr');
            var $input = $row.find('.margin-input[data-category="' + category + '"]');
            var margin = parseFloat($input.val()) || 0;
            
            if (margin < 0 || margin > 1000) {
                alert('⚠️ La marge doit être entre 0% et 1000%');
                return;
            }
            
            if (importedCount === 0) {
                saveMargin($btn, category, margin, importedCount);
                return;
            }
            
            $('#confirm-category').text(category);
            $('#confirm-count').text(importedCount + ' produit' + (importedCount > 1 ? 's' : ''));
            $('#confirm-modal').css('display', 'flex').hide().fadeIn(200);
            
            $('#confirm-ok').off('click').on('click', function() {
                $('#confirm-modal').fadeOut(200);
                saveMargin($btn, category, margin, importedCount);
            });
            
            $('#confirm-cancel').off('click').on('click', function() {
                $('#confirm-modal').fadeOut(200);
            });
        });
        
        function saveMargin($btn, category, margin, importedCount) {
            $btn.prop('disabled', true).text('⏳ Enregistrement...');
            
            $('#notification-message').text('Enregistrement en cours...');
            $('#notification-details').text('Mise à jour de la catégorie : ' + category);
            $('#save-notification').fadeIn(200);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_save_single_margin',
                    nonce: '<?php echo wp_create_nonce('api_margins_nonce'); ?>',
                    category: category,
                    margin: margin
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        $('#notification-message').text('✅ ' + data.message);
                        
                        var details = '';
                        if (data.wc_updated > 0) {
                            details += data.wc_updated + ' produit(s) WooCommerce mis à jour';
                        }
                        if (data.wc_errors > 0) {
                            details += ' (' + data.wc_errors + ' erreur(s))';
                        }
                        $('#notification-details').text(details);
                        
                        // ✅ Actualiser la page après 2 secondes
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#save-notification').fadeOut(200);
                        alert('❌ Erreur : ' + (response.data.message || 'Erreur inconnue'));
                        $btn.prop('disabled', false).text('💾 Enregistrer');
                    }
                },
                error: function() {
                    $('#save-notification').fadeOut(200);
                    alert('❌ Erreur réseau lors de l\'enregistrement');
                    $btn.prop('disabled', false).text('💾 Enregistrer');
                }
            });
        }
        
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
        
        $('#apply-global-margin').on('click', function() {
            $('#global-margin-input').slideDown(200);
            $('#global-margin-value').focus();
        });
        
        $('#cancel-global-margin').on('click', function() {
            $('#global-margin-input').slideUp(200);
            $('#global-margin-value').val('');
        });
        
        $('#confirm-global-margin').on('click', function() {
            var globalMargin = parseFloat($('#global-margin-value').val());
            
            if (isNaN(globalMargin) || globalMargin < 0) {
                alert('⚠️ Veuillez saisir une marge valide (nombre positif)');
                return;
            }
            
            if (!confirm('Êtes-vous sûr de vouloir appliquer ' + globalMargin + '% à TOUTES les catégories visibles sur cette page ?\n\nCette action remplacera les marges actuelles de ces catégories.')) {
                return;
            }
            
            $('.margin-input').val(globalMargin);
            
            $('#global-margin-input').slideUp(200);
            $('#global-margin-value').val('');
            
            alert('✅ Marge de ' + globalMargin + '% appliquée.\n\n💡 Cliquez sur "Enregistrer" sur chaque ligne ou utilisez "Enregistrer toutes les marges" en bas de page.');
        });
        
        $('#margins-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serializeArray();
            var margins = {};
            
            formData.forEach(function(field) {
                var match = field.name.match(/margins\[(.+)\]/);
                if (match) {
                    margins[match[1]] = field.value;
                }
            });
            
            var totalWcProducts = 0;
            $('.save-single-margin').each(function() {
                totalWcProducts += parseInt($(this).data('imported-count')) || 0;
            });
            
            if (totalWcProducts === 0) {
                saveAllMargins(margins);
                return;
            }
            
            $('#global-categories-count').text($('.save-single-margin').length + ' catégorie' + ($('.save-single-margin').length > 1 ? 's' : ''));
            $('#global-products-count').text(totalWcProducts + ' produit' + (totalWcProducts > 1 ? 's' : ''));
            $('#confirm-global-modal').css('display', 'flex').hide().fadeIn(200);
            
            $('#global-confirm-ok').off('click').on('click', function() {
                $('#confirm-global-modal').fadeOut(200);
                saveAllMargins(margins);
            });
            
            $('#global-confirm-cancel').off('click').on('click', function() {
                $('#confirm-global-modal').fadeOut(200);
            });
        });
        
        function saveAllMargins(margins) {
            $('#notification-message').text('Enregistrement en cours...');
            $('#notification-details').text('Mise à jour des marges et des prix WooCommerce...');
            $('#save-notification').fadeIn(200);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_save_margins',
                    nonce: '<?php echo wp_create_nonce('api_margins_nonce'); ?>',
                    margins: margins
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        $('#notification-message').text('✅ ' + data.message);
                        
                        var details = data.count + ' catégorie(s) configurée(s)';
                        if (data.changed_categories > 0) {
                            details += ' - ' + data.changed_categories + ' modifiée(s)';
                        }
                        if (data.wc_updated > 0) {
                            details += ' - ' + data.wc_updated + ' produit(s) WC mis à jour';
                        }
                        $('#notification-details').text(details);
                        
                        // ✅ Recharger après 2 secondes
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#save-notification').fadeOut(200);
                        alert('❌ Erreur : ' + (response.data.message || 'Erreur inconnue'));
                    }
                },
                error: function() {
                    $('#save-notification').fadeOut(200);
                    alert('❌ Erreur réseau lors de l\'enregistrement.\n\nVeuillez réessayer.');
                }
            });
        }
    });
    </script>
    <?php
}