<?php

if (!defined('ABSPATH')) exit;

function api_get_progress() {
    $progress = get_transient('api_import_progress');
    if (!$progress) {
        $progress = [
            'variants_current' => 0,
            'variants_total' => 0,
            'products_current' => 0,
            'products_total' => 0
        ];
    }
    return $progress;
}

function api_set_progress($variants_current, $variants_total, $products_current, $products_total) {
    $progress = [
        'variants_current' => $variants_current,
        'variants_total' => $variants_total,
        'products_current' => $products_current,
        'products_total' => $products_total
    ];
    set_transient('api_import_progress', $progress, 3600);
}

function api_increment_variant() {
    $progress = api_get_progress();
    $progress['variants_current']++;
    set_transient('api_import_progress', $progress, 3600);
}

function api_increment_product() {
    $progress = api_get_progress();
    $progress['products_current']++;
    set_transient('api_import_progress', $progress, 3600);
}

function api_reset_progress() {
    delete_transient('api_import_progress');
}

function api_download_and_attach_image_local($image_url, $product_id = 0) {
    global $wpdb;
    if (!$image_url) return null;
    
    $image_url = esc_url_raw($image_url);
    
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} 
        WHERE guid=%s OR post_name=%s 
        LIMIT 1", 
        $image_url,
        sanitize_title(basename(parse_url($image_url, PHP_URL_PATH)))
    ));
    
    if ($existing) {
        return intval($existing);
    }
    
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        return null;
    }

    $file_array = [
        'name' => basename(parse_url($image_url, PHP_URL_PATH)), 
        'tmp_name' => $tmp
    ];
    
    $id = media_handle_sideload($file_array, $product_id);
    
    if (is_wp_error($id)) {
        @unlink($tmp);
        return null;
    }
    
    return $id;
}

function api_create_attribute_term_local($taxonomy, $term_name) {
    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, ['product'], []);
    }
    
    $term = get_term_by('name', $term_name, $taxonomy);
    if (!$term) {
        $term = wp_insert_term($term_name, $taxonomy);
        if (is_wp_error($term)) return null;
        return $term['term_id'];
    }
    return $term->term_id;
}

function api_ensure_global_attributes_local() {
    $attributes = ['pa_taille' => 'Taille', 'pa_couleur' => 'Couleur'];
    foreach ($attributes as $slug => $label) {
        $attribute_id = wc_attribute_taxonomy_id_by_name($slug);
        if (!$attribute_id) {
            $attribute_id = wc_create_attribute([
                'name' => $label,
                'slug' => $slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false
            ]);
            if (!is_wp_error($attribute_id)) {
                register_taxonomy($slug, ['product'], []);
                delete_transient('wc_attribute_taxonomies');
            }
        }
    }
}

function api_create_product_attributes($variant, $for_variation = false) {
    $attributes = [];
    
    if (empty($variant['attributes']) || !is_array($variant['attributes'])) {
        return $attributes;
    }
    
    foreach ($variant['attributes'] as $attr) {
        $type = $attr['type'] ?? '';
        $value = $attr['value'] ?? '';
        
        if (!$value) continue;
        
        switch ($type) {
            case 'sizes':
                $attribute = new WC_Product_Attribute();
                $attribute->set_name('Taille');
                $attribute->set_options([$value]);
                $attribute->set_visible(true);
                $attribute->set_variation($for_variation);
                $attributes[] = $attribute;
                break;
                
            case 'color':
                $color_name = $value;
                if (isset($attr['colorCode']) && $attr['colorCode']) {
                    $color_name .= ' (' . $attr['colorCode'] . ')';
                }
                $attribute = new WC_Product_Attribute();
                $attribute->set_name('Couleur');
                $attribute->set_options([$color_name]);
                $attribute->set_visible(true);
                $attribute->set_variation($for_variation);
                $attributes[] = $attribute;
                break;
                
            case 'material':
                $attribute = new WC_Product_Attribute();
                $attribute->set_name('Matière');
                $attribute->set_options([$value]);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[] = $attribute;
                break;
        }
    }
    
    return $attributes;
}

function api_create_woocommerce_product_full($product_api_data, $price_stock_data = null) {
    
    if (!function_exists('wc_get_product')) return false;

    api_ensure_global_attributes_local();

    $variants = $product_api_data['variants'] ?? [];
    if (empty($variants)) return false;

    $is_variable = count($variants) > 1;
    $first_variant = $variants[0];
    
    $main_reference = $is_variable 
        ? ($product_api_data['reference'] ?? $first_variant['variantReference'])
        : ($first_variant['variantReference'] ?? $product_api_data['reference']);

    $product_id = wc_get_product_id_by_sku($main_reference);
    
    if ($product_id) {
        $product = wc_get_product($product_id);
        if (($is_variable && !$product->is_type('variable')) || (!$is_variable && $product->is_type('variable'))) {
            wp_delete_post($product_id, true);
            $product = null;
        }
    }
    
    if (!isset($product) || !$product) {
        $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
    }

    $product_name = $first_variant['title']['fr'] ?? $main_reference;
    $product->set_name($product_name);
    $product->set_slug(sanitize_title($product_name));
    $product->set_sku($main_reference);
    $product->set_status('draft');

    if (!empty($first_variant['longDescription']['fr'])) {
        $product->set_description($first_variant['longDescription']['fr']);
    }
    if (!empty($first_variant['description']['fr'])) {
        $product->set_short_description($first_variant['description']['fr']);
    }

    $category_ids = [];
    if (!empty($first_variant['categories']) && is_array($first_variant['categories'])) {
        foreach ($first_variant['categories'] as $cat_data) {
            $cat_name = $cat_data['categories']['fr'] ?? $cat_data['families']['fr'] ?? null;
            if ($cat_name) {
                $term = term_exists($cat_name, 'product_cat') ?: wp_insert_term($cat_name, 'product_cat');
                if (!is_wp_error($term)) {
                    $category_ids[] = is_array($term) ? $term['term_id'] : $term;
                }
            }
        }
    }
    
    if (empty($category_ids)) {
        $term = term_exists('Autres', 'product_cat') ?: wp_insert_term('Autres', 'product_cat');
        if (!is_wp_error($term)) {
            $category_ids[] = is_array($term) ? $term['term_id'] : $term;
        }
    }
    
    if (!empty($category_ids)) $product->set_category_ids($category_ids);

    $tag_names = [];
    if (!empty($first_variant['tags']) && is_array($first_variant['tags'])) {
        foreach ($first_variant['tags'] as $tag) {
            if (is_string($tag)) $tag_names[] = $tag;
        }
    }
    
    if (!empty($first_variant['keywords']) && is_array($first_variant['keywords'])) {
        foreach ($first_variant['keywords'] as $keyword_group) {
            if (isset($keyword_group['fr']) && is_array($keyword_group['fr'])) {
                $tag_names = array_merge($tag_names, $keyword_group['fr']);
            }
        }
    }
    
    if (!empty($tag_names)) {
        $tag_ids = [];
        foreach ($tag_names as $tag_name) {
            $term = term_exists($tag_name, 'product_tag') ?: wp_insert_term($tag_name, 'product_tag');
            if (!is_wp_error($term)) {
                $tag_ids[] = is_array($term) ? $term['term_id'] : $term;
            }
        }
        $product->set_tag_ids($tag_ids);
    }

    if (!empty($first_variant['characteristics']['genders'])) {
        $product->update_meta_data('_gender', implode(', ', $first_variant['characteristics']['genders']));
    }
    if (!empty($first_variant['netWeight']['value'])) {
        $product->update_meta_data('_net_weight', $first_variant['netWeight']['value']);
    }
    if (!empty($first_variant['grammage']['value'])) {
        $product->update_meta_data('_grammage', $first_variant['grammage']['value']);
    }
    if (!empty($first_variant['countryOfOrigin'])) {
        $product->update_meta_data('_country_of_origin', implode(', ', $first_variant['countryOfOrigin']));
    }
    if (!empty($first_variant['longTitle']['fr'])) {
        $product->update_meta_data('_long_title', $first_variant['longTitle']['fr']);
    }

    if (!empty($first_variant['images']) && is_array($first_variant['images'])) {
        $attachment_ids = [];
        foreach ($first_variant['images'] as $image_data) {
            $image_url = is_string($image_data) ? $image_data : ($image_data['url'] ?? null);
            if ($image_url) {
                $attachment_id = api_download_and_attach_image_local($image_url, 0);
                if ($attachment_id) $attachment_ids[] = $attachment_id;
            }
        }
        if (!empty($attachment_ids)) {
            $product->set_image_id($attachment_ids[0]);
            if (count($attachment_ids) > 1) {
                $product->set_gallery_image_ids(array_slice($attachment_ids, 1));
            }
        }
    }

    try {
        $product_id = $product->save();
    } catch (Exception $e) {
        return false;
    }

    if (!$is_variable) {
        api_increment_variant();
        
        $regular_price = floatval($product_api_data['regular_price'] ?? $product_api_data['price'] ?? $first_variant['price'] ?? 0);
        if ($regular_price > 0) {
            if (function_exists('api_round_price_to_5cents')) {
                $regular_price = api_round_price_to_5cents($regular_price);
            }
            $product->set_regular_price($regular_price);
            $product->set_price($regular_price);
        }

        $total_stock = intval($product_api_data['stock_quantity'] ?? 0);
        if ($total_stock == 0) {
            $total_stock = intval($first_variant['stock'] ?? 0) + intval($first_variant['stock_supplier'] ?? 0);
        }
        $product->set_manage_stock(true);
        $product->set_stock_quantity($total_stock);
        $product->set_stock_status($total_stock > 0 ? 'instock' : 'outofstock');

        $attributes = api_create_product_attributes($first_variant, false);
        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }

        $product->save();
        return $product_id;
    }
    
    $margin_percent = floatval($product_api_data['applied_margin'] ?? 0);
    $category_name = $product_api_data['category_name'] ?? '';
    
    $all_sizes = [];
    $all_colors = [];
    $all_materials = [];
    
    foreach ($variants as $variant) {
        if (!empty($variant['attributes']) && is_array($variant['attributes'])) {
            foreach ($variant['attributes'] as $attr) {
                if ($attr['type'] === 'sizes' && !empty($attr['value'])) {
                    $size_term_id = api_create_attribute_term_local('pa_taille', $attr['value']);
                    if ($size_term_id) $all_sizes[$size_term_id] = $attr['value'];
                }
                if ($attr['type'] === 'color' && !empty($attr['value'])) {
                    $color_term_id = api_create_attribute_term_local('pa_couleur', $attr['value']);
                    if ($color_term_id) $all_colors[$color_term_id] = $attr['value'];
                }
                if ($attr['type'] === 'material' && !empty($attr['value'])) {
                    $all_materials[$attr['value']] = $attr['value'];
                }
            }
        }
    }
    
    $attributes = [];
    
    if (!empty($all_sizes)) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_taille'));
        $attribute->set_name('pa_taille');
        $attribute->set_options(array_keys($all_sizes));
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
    }
    
    if (!empty($all_colors)) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_couleur'));
        $attribute->set_name('pa_couleur');
        $attribute->set_options(array_keys($all_colors));
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
    }
    
    if (!empty($all_materials)) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Matière');
        $attribute->set_options(array_values($all_materials));
        $attribute->set_visible(true);
        $attribute->set_variation(false);
        $attributes[] = $attribute;
    }
    
    if (!empty($attributes)) {
        $product->set_attributes($attributes);
        $product->save();
    }

    $variant_images_cache = [];
    
    foreach ($variants as $variant) {
        if (!empty($variant['images'][0])) {
            $image_url = is_string($variant['images'][0]) ? $variant['images'][0] : ($variant['images'][0]['url'] ?? null);
            if ($image_url && !isset($variant_images_cache[$image_url])) {
                $attachment_id = api_download_and_attach_image_local($image_url, $product_id);
                if ($attachment_id) {
                    $variant_images_cache[$image_url] = $attachment_id;
                }
            }
        }
    }
    
    foreach ($variants as $variant_index => $variant) {
        $variant_sku = $variant['variantReference'] ?? '';
        if (!$variant_sku) continue;
        
        api_increment_variant();
        
        $variation_id = wc_get_product_id_by_sku($variant_sku);
        $variation = $variation_id ? new WC_Product_Variation($variation_id) : new WC_Product_Variation();
        if (!$variation_id) $variation->set_parent_id($product_id);
        
        $variation->set_sku($variant_sku);
        
        $variation_attributes = [];
        if (!empty($variant['attributes']) && is_array($variant['attributes'])) {
            foreach ($variant['attributes'] as $attr) {
                if ($attr['type'] === 'sizes' && !empty($attr['value'])) {
                    $term = get_term_by('name', $attr['value'], 'pa_taille');
                    if ($term) $variation_attributes['pa_taille'] = $term->slug;
                }
                if ($attr['type'] === 'color' && !empty($attr['value'])) {
                    $term = get_term_by('name', $attr['value'], 'pa_couleur');
                    if ($term) $variation_attributes['pa_couleur'] = $term->slug;
                }
            }
        }
        $variation->set_attributes($variation_attributes);

        if (!empty($variant['images'][0])) {
            $image_url = is_string($variant['images'][0]) ? $variant['images'][0] : ($variant['images'][0]['url'] ?? null);
            if ($image_url && isset($variant_images_cache[$image_url])) {
                $variation->set_image_id($variant_images_cache[$image_url]);
            }
        }

        $price_already_set = false;
        $regular_price = null;
        $sale_price = null;
        
        if (isset($variant['regular_price']) && $variant['regular_price'] > 0) {
            $regular_price = floatval($variant['regular_price']);
            $price_already_set = true;
        }
        
        if (isset($variant['price']) && $variant['price'] > 0) {
            if (!$regular_price) {
                $regular_price = floatval($variant['price']);
                $price_already_set = true;
            }
        }
        
        if (!$price_already_set) {
            $variant_price_stock = api_get_product_price_stock($variant_sku);
            
            if ($variant_price_stock) {
                if (isset($variant_price_stock['price']) && $variant_price_stock['price'] > 0) {
                    $regular_price = floatval($variant_price_stock['price']);
                    $regular_price = api_round_price_to_5cents($regular_price);
                }
                
                if (isset($variant_price_stock['price_box']) && $variant_price_stock['price_box'] > 0) {
                    $price_box = floatval($variant_price_stock['price_box']);
                    $price_box = api_round_price_to_5cents($price_box);
                    
                    if ($regular_price && $price_box < $regular_price) {
                        $sale_price = $price_box;
                    } elseif (!$regular_price) {
                        $regular_price = $price_box;
                    }
                }
            }
        }
        
        if ($regular_price) {
            $variation->set_regular_price($regular_price);
            
            if ($sale_price) {
                $variation->set_sale_price($sale_price);
            } else {
                $variation->set_price($regular_price);
            }
        }

        $total_stock = intval($variant['stock_quantity'] ?? 0);
        if ($total_stock == 0) {
            $total_stock = intval($variant['stock'] ?? 0) + intval($variant['stock_supplier'] ?? 0);
        }
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($total_stock);
        $variation->set_stock_status($total_stock > 0 ? 'instock' : 'outofstock');

        $variation->save();
    }

    WC_Product_Variable::sync($product_id);
    return $product_id;
}

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

function api_db_get_products_filtered($filters = [], $limit = 10, $offset = 0) {
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
        $where[] = 'category = %s';
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['status'])) {
        $where[] = 'status = %s';
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['filter_status_combined'])) {
        switch ($filters['filter_status_combined']) {
            case 'to_import':
                $where[] = 'is_deleted = 0 AND imported = 0';
                break;
            case 'imported':
                $where[] = 'imported = 1 AND is_deleted = 0';
                break;
            case 'deleted':
                $where[] = 'is_deleted = 1';
                break;
        }
    }
    
    $where_clause = implode(' AND ', $where);
    $params[] = $limit;
    $params[] = $offset;
    
    $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY synced_at DESC LIMIT %d OFFSET %d";
    
    if (!empty($params)) {
        $query = $wpdb->prepare($query, $params);
    }
    
    return $wpdb->get_results($query, ARRAY_A);
}

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
        $where[] = 'category = %s';
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['status'])) {
        $where[] = 'status = %s';
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['filter_status_combined'])) {
        switch ($filters['filter_status_combined']) {
            case 'to_import':
                $where[] = 'is_deleted = 0 AND imported = 0';
                break;
            case 'imported':
                $where[] = 'imported = 1 AND is_deleted = 0';
                break;
            case 'deleted':
                $where[] = 'is_deleted = 1';
                break;
        }
    }
    
    $where_clause = implode(' AND ', $where);
    
    $query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
    
    if (!empty($params)) {
        $query = $wpdb->prepare($query, $params);
    }
    
    return intval($wpdb->get_var($query));
}

function api_db_get_all_brands() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    return $wpdb->get_col("SELECT DISTINCT brand FROM $table_name WHERE brand != '' ORDER BY brand ASC");
}

function api_db_get_all_categories() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    return $wpdb->get_col("SELECT DISTINCT category FROM $table_name WHERE category != '' ORDER BY category ASC");
}

function api_db_get_product_by_id($id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'imbretex_products';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id), ARRAY_A);
}

add_action('wp_ajax_api_get_variant_prices', function() {
    check_ajax_referer('api_import_wc_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $product_db = api_db_get_product_by_id($product_id);
    
    if (!$product_db) {
        wp_send_json_error(['message' => 'Produit non trouvé']);
        return;
    }
    
    $product_data = json_decode($product_db['product_data'], true);
    
    if (!$product_data || !isset($product_data['variants']) || !is_array($product_data['variants'])) {
        wp_send_json_error(['message' => 'Aucune variante trouvée']);
        return;
    }
    
    $category = $product_db['category']; 
    $margin_percent = 0;
    if (function_exists('api_get_category_margin')) {
        $margin_percent = api_get_category_margin($category);
    }
    
    $variants_with_prices = [];
    
    foreach ($product_data['variants'] as $variant) {
        $variant_sku = $variant['variantReference'] ?? $variant['sku'] ?? '';
        
        if (!$variant_sku) {
            continue;
        }
        
        $variant_base_price = 0;
        if (isset($variant['price']) && $variant['price'] > 0) {
            $variant_base_price = floatval($variant['price']);
        }
        
        $variant_final_price = $variant_base_price;
        if ($margin_percent > 0 && $variant_base_price > 0) {
            if (function_exists('api_calculate_price_with_margin')) {
                $variant_final_price = api_calculate_price_with_margin($variant_base_price, $category);
            } else {
                $variant_final_price = $variant_base_price * (1 + ($margin_percent / 100));
            }
            
            if (function_exists('api_round_price_to_5cents')) {
                $variant_final_price = api_round_price_to_5cents($variant_final_price);
            } else {
                $variant_final_price = ceil($variant_final_price / 0.05) * 0.05;
            }
        }
        
        $variant_info = [
            'sku' => $variant_sku,
            'base_price' => $variant_base_price,
            'final_price' => $variant_final_price,
            'margin' => $margin_percent,
            'attributes' => []
        ];
        
        if (isset($variant['attributes']) && is_array($variant['attributes'])) {
            foreach ($variant['attributes'] as $attribute) {
                $variant_info['attributes'][] = [
                    'name' => $attribute['type'] ?? '',
                    'value' => $attribute['value'] ?? ''
                ];
            }
        }
        
        $variants_with_prices[] = $variant_info;
    }
    
    wp_send_json_success([
        'variants' => $variants_with_prices,
        'product_name' => $product_db['name']
    ]);
});

add_action('wp_ajax_api_get_import_progress', function() {
    check_ajax_referer('api_import_wc_nonce', 'nonce');
    
    $progress = api_get_progress();
    
    wp_send_json_success($progress);
});

add_action('wp_ajax_api_init_import_progress', function() {
    check_ajax_referer('api_import_wc_nonce', 'nonce');
    
    $total_variants = intval($_POST['total_variants'] ?? 0);
    $total_products = intval($_POST['total_products'] ?? 0);
    
    api_set_progress(0, $total_variants, 0, $total_products);
    
    wp_send_json_success([
        'message' => 'Initialized',
        'variants_total' => $total_variants,
        'products_total' => $total_products
    ]);
});

add_action('wp_ajax_api_import_single_product', function() {
    check_ajax_referer('api_import_wc_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    
    $product_db = api_db_get_product_by_id($product_id);
    
    if (!$product_db) {
        wp_send_json_error(['message' => 'Produit non trouvé dans la base']);
        return;
    }
    
    if ($product_db['is_deleted'] == 1) {
        wp_send_json_error([
            'message' => 'Produit marqué comme supprimé',
            'reason' => 'deleted',
            'sku' => $product_db['sku']
        ]);
        return;
    }
    
    $base_price = floatval($product_db['price']);
    if ($base_price <= 0) {
        wp_send_json_error([
            'message' => 'Prix invalide ou manquant',
            'reason' => 'no_price',
            'sku' => $product_db['sku']
        ]);
        return;
    }
    
    $product_data = json_decode($product_db['product_data'], true);
    
    if (!$product_data) {
        wp_send_json_error(['message' => 'Données produit invalides']);
        return;
    }
    
    $category = $product_db['category'];
    $margin_percent = 0;
    if (function_exists('api_get_category_margin')) {
        $margin_percent = api_get_category_margin($category);
    }
    
    $wc_price = $base_price;
    if ($margin_percent > 0 && function_exists('api_calculate_price_with_margin')) {
        $wc_price = api_calculate_price_with_margin($base_price, $category);
        if (function_exists('api_round_price_to_5cents')) {
            $wc_price = api_round_price_to_5cents($wc_price);
        }
    }
    
    $product_data['calculated_price'] = $wc_price;
    $product_data['base_price'] = $base_price;
    $product_data['applied_margin'] = $margin_percent;
    $product_data['category_name'] = $category;
    $product_data['price'] = $wc_price;
    $product_data['regular_price'] = $wc_price;
    
    if (isset($product_data['variants']) && is_array($product_data['variants'])) {
        foreach ($product_data['variants'] as $index => &$variant) {
            $variant_base_price = 0;
            
            if (isset($variant['price']) && $variant['price'] > 0) {
                $variant_base_price = floatval($variant['price']);
            }
            
            if ($variant_base_price > 0) {
                $variant_wc_price = $variant_base_price;
                
                if ($margin_percent > 0 && function_exists('api_calculate_price_with_margin')) {
                    $variant_wc_price = api_calculate_price_with_margin($variant_base_price, $category);
                    if (function_exists('api_round_price_to_5cents')) {
                        $variant_wc_price = api_round_price_to_5cents($variant_wc_price);
                    }
                }
                
                $variant['price'] = $variant_wc_price;
                $variant['regular_price'] = $variant_wc_price;
                $variant['calculated_price'] = $variant_wc_price;
                $variant['base_price'] = $variant_base_price;
                $variant['applied_margin'] = $margin_percent;
            }
        }
        unset($variant);
    }
    
    $variants_count = isset($product_data['variants']) ? count($product_data['variants']) : 1;
    
    try {
        $result = api_create_woocommerce_product_full($product_data, null);
        
        if ($result) {
            api_increment_product();
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'imbretex_products';
            
            $wpdb->update(
                $table_name,
                [
                    'imported' => 1,
                    'wc_product_id' => $result,
                    'wc_status' => 'draft'
                ],
                ['id' => $product_id],
                ['%d', '%d', '%s'],
                ['%d']
            );
            
            wp_send_json_success([
                'sku' => $product_db['sku'],
                'name' => $product_db['name'],
                'brand' => $product_db['brand'],
                'category' => $category,
                'wc_product_id' => $result,
                'variants_count' => $variants_count,
                'base_price' => $base_price,
                'final_price' => $wc_price,
                'margin' => $margin_percent
            ]);
        } else {
            wp_send_json_error(['message' => 'Échec création produit WooCommerce']);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

function api_import_to_wc_page() {
    $filter_sku = $_GET['filter_sku'] ?? '';
    $filter_name = $_GET['filter_name'] ?? '';
    $filter_brand = $_GET['filter_brand'] ?? '';
    $filter_category = $_GET['filter_category'] ?? '';
    $filter_status_combined = $_GET['filter_status_combined'] ?? '';
    $items_per_page = intval($_GET['items_per_page'] ?? 20);
    $paged = intval($_GET['paged'] ?? 1);
    
    $filters = [];
    if ($filter_sku) $filters['sku'] = $filter_sku;
    if ($filter_name) $filters['name'] = $filter_name;
    if ($filter_brand) $filters['brand'] = $filter_brand;
    if ($filter_category) $filters['category'] = $filter_category;
    if ($filter_status_combined !== '') $filters['filter_status_combined'] = $filter_status_combined;
    
    $total_items = api_db_count_products_filtered($filters);
    $total_pages = ceil($total_items / $items_per_page);
    
    $offset = ($paged - 1) * $items_per_page;
    $products = api_db_get_products_filtered($filters, $items_per_page, $offset);
    
    $all_brands = api_db_get_all_brands();
    $all_categories = api_db_get_all_categories();
    
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
        'filter_status_combined' => $filter_status_combined,
        'items_per_page' => $items_per_page
    ];
    
    ?>
    <div class="wrap">
        <h1>➡️ Importation vers WooCommerce <span style="font-size:14px;color:#666;">v11.8 - Vérification Auto Statut WC ✅</span></h1>
        
        <?php if (!function_exists('api_get_category_margin')): ?>
        <div class="notice notice-warning" style="margin:15px 0;padding:12px;">
            <p style="margin:0;">
                <strong>⚠️ Module Prix (Marge) non activé</strong> - 
                Les produits seront importés avec leur prix API sans marge.
            </p>
        </div>
        <?php endif; ?>
        
        <?php
        $stats_total = api_db_count_products_filtered(['filter_status_combined' => '']);
        $stats_to_import = api_db_count_products_filtered(['filter_status_combined' => 'to_import']);
        $stats_imported = api_db_count_products_filtered(['filter_status_combined' => 'imported']);
        $stats_deleted = api_db_count_products_filtered(['filter_status_combined' => 'deleted']);
        ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0;">
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #2271b1;">
                <h3 style="margin:0 0 5px 0;color:#2271b1;font-size:14px;">📦 Total</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_total); ?></p>
            </div>
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #dc3232;">
                <h3 style="margin:0 0 5px 0;color:#dc3232;font-size:14px;">⏳ À Importer</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_to_import); ?></p>
            </div>
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #46b450;">
                <h3 style="margin:0 0 5px 0;color:#46b450;font-size:14px;">✓ Importés</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_imported); ?></p>
            </div>
            <div style="background:#fff;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #826eb4;">
                <h3 style="margin:0 0 5px 0;color:#826eb4;font-size:14px;">🗑️ Supprimés</h3>
                <p style="font-size:28px;margin:0;font-weight:bold;"><?php echo number_format($stats_deleted); ?></p>
            </div>
        </div>
        
        <div style="background:#fff;padding:15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin:0 0 10px 0;color:#2271b1;font-size:16px;">🔍 Filtres</h3>
            <form method="get" action="">
                <input type="hidden" name="page" value="api-import-to-wc">
                
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:15px;">
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">SKU</label>
                        <input type="text" name="filter_sku" value="<?php echo esc_attr($filter_sku); ?>" 
                               placeholder="Rechercher..." style="width:100%;height:32px;">
                    </div>
                    
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Nom</label>
                        <input type="text" name="filter_name" value="<?php echo esc_attr($filter_name); ?>" 
                               placeholder="Rechercher..." style="width:100%;height:32px;">
                    </div>
                    
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Marque</label>
                        <select name="filter_brand" id="filter-brand-select" style="width:100%;">
                            <option value="">-- Toutes (<?php echo count($all_brands); ?>) --</option>
                            <?php foreach ($all_brands as $brand): ?>
                                <option value="<?php echo esc_attr($brand); ?>" <?php selected($filter_brand, $brand); ?>>
                                    <?php echo esc_html($brand); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Catégorie</label>
                        <select name="filter_category" id="filter-category-select" style="width:100%;">
                            <option value="">-- Toutes (<?php echo count($all_categories); ?>) --</option>
                            <?php foreach ($all_categories as $category): ?>
                                <option value="<?php echo esc_attr($category); ?>" <?php selected($filter_category, $category); ?>>
                                    <?php echo esc_html($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Statut</label>
                        <select name="filter_status_combined" style="width:100%;height:32px;">
                            <option value="">-- Tous --</option>
                            <option value="to_import" <?php selected($filter_status_combined, 'to_import'); ?>>⏳ À importer</option>
                            <option value="imported" <?php selected($filter_status_combined, 'imported'); ?>>✓ Importés</option>
                            <option value="deleted" <?php selected($filter_status_combined, 'deleted'); ?>>🗑️ Supprimés</option>
                        </select>
                    </div>
                    
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
        
        <div style="background:#fff;padding:10px 15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;justify-content:space-between;align-items:center;">
            <p style="margin:0;">
                <strong><?php echo number_format($total_items); ?> produit(s)</strong> | 
                Page <?php echo $paged; ?>/<?php echo $total_pages; ?>
            </p>
            <button type="button" id="start-import" class="button button-primary" style="font-weight:600;">
                <span id="import-btn-text">✅ Importer vers WooCommerce</span>
            </button>
        </div>
        
        <div id="variants-prices-modal" style="display:none;">
            <div class="variants-prices-modal-content">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:2px solid #2271b1;padding-bottom:15px;">
                    <div>
                        <h2 style="margin:0;color:#2271b1;font-size:22px;">💰 Prix des Variantes</h2>
                        <p id="variant-product-name" style="margin:5px 0 0 0;color:#666;font-size:14px;"></p>
                    </div>
                    <button id="close-variants-prices-modal" class="button" style="font-size:20px;line-height:1;padding:5px 12px;">×</button>
                </div>
                
                <div id="variants-prices-container" style="max-height:500px;overflow-y:auto;"></div>
            </div>
        </div>
        
        <div id="import-modal" style="display:none;">
            <div class="import-modal-content">
                <div id="import-progress-view">
                    <h2 style="text-align:center;color:#2271b1;margin-bottom:30px;">📥 Importation en cours</h2>
                    
                    <div id="parent-progress-section" style="margin:25px 0;">
                        <div style="text-align:center;margin-bottom:10px;">
                            <strong style="color:#f0b849;">📦 Création du produit parent</strong>
                            <br><small style="color:#666;">Catégories, images, meta-données...</small>
                        </div>
                        <div style="background:#f0f0f0;border-radius:10px;overflow:hidden;height:30px;position:relative;box-shadow:inset 0 2px 4px rgba(0,0,0,0.1);">
                            <div id="parent-progress-bar" style="height:100%;width:0%;transition:width 0.5s ease;background:linear-gradient(90deg, #f0b849 0%, #f39c12 100%);"></div>
                            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-weight:700;font-size:14px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,0.5);">
                                <span id="parent-progress-percent">0%</span>
                            </div>
                        </div>
                    </div>
                    
                    <div id="variants-progress-section" style="margin:25px 0;display:none;">
                        <div style="text-align:center;margin-bottom:10px;">
                            <strong style="color:#2271b1;">🔧 Création des variantes</strong>
                        </div>
                        <div style="background:#f0f0f0;border-radius:10px;overflow:hidden;height:40px;position:relative;box-shadow:inset 0 2px 4px rgba(0,0,0,0.1);">
                            <div id="variants-progress-bar" style="height:100%;width:0%;transition:width 0.3s ease, background-color 0.3s ease;background-color:#dc3232;"></div>
                            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-weight:700;font-size:16px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,0.5);">
                                <span id="variants-progress-percent">0%</span>
                            </div>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:13px;color:#666;">
                            <span id="variants-counter">0/0 variantes</span>
                            <span id="products-counter">0/0 produits</span>
                        </div>
                    </div>
                    
                    <div id="current-product-box" style="margin:20px 0;padding:20px;background:#f0f6ff;border-radius:8px;border-left:4px solid #2271b1;min-height:150px;">
                        <div id="current-product-info" style="font-size:14px;color:#666;">En attente...</div>
                    </div>
                </div>
                
                <div id="import-summary" style="display:none;text-align:center;padding:40px 20px;">
                    <div style="font-size:48px;margin-bottom:20px;">✅</div>
                    <h2 style="color:#46b450;margin-bottom:20px;font-size:28px;">Importation terminée !</h2>
                    
                    <div style="background:#e7f7e7;padding:20px;border-radius:8px;margin-bottom:30px;">
                        <div style="font-size:42px;font-weight:700;color:#46b450;margin-bottom:10px;">
                            <span id="total-imported">0</span>
                        </div>
                        <div style="font-size:16px;color:#666;">produit(s) importé(s)</div>
                        
                        <div style="margin-top:15px;padding-top:15px;border-top:1px solid #ddd;">
                            <div style="font-size:32px;font-weight:700;color:#2271b1;margin-bottom:5px;">
                                <span id="total-variants">0</span>
                            </div>
                            <div style="font-size:14px;color:#666;">variante(s) créée(s)</div>
                        </div>
                    </div>
                    
                    <div id="errors-summary" style="display:none;background:#fff0f0;padding:15px;border-radius:8px;margin-bottom:20px;border-left:4px solid #dc3232;">
                        <div style="font-weight:600;color:#dc3232;margin-bottom:10px;">
                            ⚠️ <span id="total-errors">0</span> erreur(s)
                        </div>
                        <ul id="error-list" style="text-align:left;margin:0;padding-left:20px;font-size:13px;color:#666;"></ul>
                    </div>
                    
                    <button id="close-modal-btn" class="button button-primary" style="font-size:16px;padding:12px 40px;">
                        Fermer
                    </button>
                </div>
            </div>
        </div>
        
        <form method="post" id="import-form">
            <div id="products-table-wrapper">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="select-all"></th>
                            <th style="width:100px;">SKU</th>
                            <th>Nom</th>
                            <th style="width:100px;">Marque</th>
                            <th style="width:120px;">Catégorie</th>
                            <th style="width:90px;">Prix Initial</th>
                            <th style="width:80px;">Marge</th>
                            <th style="width:110px;">Prix Final</th>
                            <th style="width:90px;">Variants</th>
                            <th style="width:100px;">Importé WC</th>
                            <th style="width:110px;">Statut WC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr><td colspan="12" style="text-align:center;padding:30px;">
                                Aucun produit trouvé.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($products as $product):
                                $is_imported = $product['imported'] == 1;
                                $is_deleted = $product['is_deleted'] == 1;
                                $base_price = floatval($product['price']);
                                $has_no_price = ($base_price <= 0);
                                
                                $wc_status = $product['wc_status'] ?? null;
                                
                                $category = $product['category'];
                                $margin_percent = 0;
                                $wc_price = $base_price;
                                
                                if (function_exists('api_get_category_margin')) {
                                    $margin_percent = api_get_category_margin($category);
                                    if ($margin_percent > 0 && !$has_no_price) {
                                        $wc_price = api_calculate_price_with_margin($base_price, $category);
                                        if (function_exists('api_round_price_to_5cents')) {
                                            $wc_price = api_round_price_to_5cents($wc_price);
                                        }
                                    }
                                }
                                
                                if ($is_imported && ($wc_status === null || $wc_status === '') && $product['wc_product_id']) {
                                    $wc_product_obj = wc_get_product($product['wc_product_id']);
                                    
                                    if ($wc_product_obj && !is_wp_error($wc_product_obj)) {
                                        $wc_status = $wc_product_obj->get_status();
                                        
                                        global $wpdb;
                                        $table_name = $wpdb->prefix . 'imbretex_products';
                                        $wpdb->update(
                                            $table_name,
                                            ['wc_status' => $wc_status],
                                            ['id' => $product['id']],
                                            ['%s'],
                                            ['%d']
                                        );
                                    } else {
                                        $wc_status = 'deleted';
                                        
                                        global $wpdb;
                                        $table_name = $wpdb->prefix . 'imbretex_products';
                                        $wpdb->update(
                                            $table_name,
                                            ['wc_status' => 'deleted'],
                                            ['id' => $product['id']],
                                            ['%s'],
                                            ['%d']
                                        );
                                    }
                                }
                                
                                $row_style = '';
                                if ($is_deleted) {
                                    $row_style = 'background:#fff0f0;opacity:0.7;';
                                } elseif ($has_no_price) {
                                    $row_style = 'background:#fff9e6;opacity:0.8;';
                                }
                                
                                $checkbox_disabled = ($is_deleted || $has_no_price);
                                $disable_reason = '';
                                if ($is_deleted) {
                                    $disable_reason = 'Produit supprimé';
                                } elseif ($has_no_price) {
                                    $disable_reason = 'Prix manquant';
                                }
                                
                                $product_data = json_decode($product['product_data'], true);
                                $variants_count = isset($product_data['variants']) ? count($product_data['variants']) : 0;
                            ?>
                            <tr style="<?php echo $row_style; ?>">
                                <td>
                                    <input type="checkbox" 
                                           name="product_ids[]" 
                                           value="<?php echo $product['id']; ?>" 
                                           class="product-checkbox" 
                                           data-imported="<?php echo $is_imported ? '1' : '0'; ?>"
                                           data-deleted="<?php echo $is_deleted ? '1' : '0'; ?>"
                                           data-no-price="<?php echo $has_no_price ? '1' : '0'; ?>"
                                           data-variants-count="<?php echo $variants_count; ?>"
                                           data-sku="<?php echo esc_attr($product['sku']); ?>"
                                           data-name="<?php echo esc_attr($product['name']); ?>"
                                           data-brand="<?php echo esc_attr($product['brand']); ?>"
                                           data-category="<?php echo esc_attr($product['category']); ?>"
                                           data-base-price="<?php echo $base_price; ?>"
                                           data-final-price="<?php echo $wc_price; ?>"
                                           data-margin="<?php echo $margin_percent; ?>"
                                           <?php echo $checkbox_disabled ? 'disabled title="' . esc_attr($disable_reason) . '"' : ''; ?>>
                                </td>
                                <td>
                                    <?php echo esc_html($product['sku']); ?>
                                    <?php if ($is_deleted): ?>
                                        <br><span style="color:#d63638;font-size:11px;font-weight:600;">🗑️</span>
                                    <?php elseif ($has_no_price): ?>
                                        <br><span style="color:#f0b849;font-size:11px;font-weight:600;">⚠️</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($product['name']); ?></td>
                                <td><?php echo esc_html($product['brand']); ?></td>
                                <td><strong><?php echo esc_html($product['category']); ?></strong></td>
                                <td style="text-align:right;font-weight:600;">
                                    <?php if ($has_no_price): ?>
                                        <span style="color:#f0b849;">0,00€</span>
                                    <?php else: ?>
                                        <?php echo number_format($base_price, 2, ',', ' '); ?>€
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($margin_percent > 0): ?>
                                        <span style="background:#46b450;color:white;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:600;">
                                            +<?php echo number_format($margin_percent, 0); ?>%
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#999;font-size:11px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;font-weight:700;color:#2271b1;">
                                    <?php if ($has_no_price): ?>
                                        <span style="color:#f0b849;">0,00€</span>
                                    <?php else: ?>
                                        <?php echo number_format($wc_price, 2, ',', ' '); ?>€
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <div style="display:flex;align-items:center;justify-content:center;gap:5px;">
                                        <span style="background:#0073aa;color:white;padding:2px 8px;border-radius:3px;font-size:11px;">
                                            <?php echo $variants_count; ?>
                                        </span>
                                        <?php if ($variants_count > 0): ?>
                                            <button type="button" class="button button-small view-variant-prices" 
                                                    data-product-id="<?php echo $product['id']; ?>"
                                                    title="Voir les prix des variantes"
                                                    style="padding:3px 8px;font-size:12px;line-height:1;min-height:0;">
                                                👁️
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($is_deleted): ?>
                                        <span style="color:#d63638;font-weight:600;">🗑️ Supprimé</span>
                                    <?php elseif ($has_no_price): ?>
                                        <span style="color:#f0b849;font-weight:600;">⚠️ Sans prix</span>
                                    <?php elseif ($product['status'] === 'new'): ?>
                                        <span style="color:#2271b1;">🆕 Nouveau</span>
                                    <?php else: ?>
                                        <span style="color:#f0b849;">🔄 MAJ</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td style="text-align:center;">
                                    <?php if (!$is_imported || !$wc_status): ?>
                                        <span style="background:#e0e0e0;color:#666;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;display:inline-block;">
                                            ⚪ À importer 
                                        </span>
                                    <?php elseif ($wc_status === 'publish'): ?>
                                        <span style="background:#46b450;color:white;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;display:inline-block;">
                                            ✅ Publié
                                        </span>
                                    <?php elseif ($wc_status === 'draft'): ?>
                                        <span style="background:#f0b849;color:white;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;display:inline-block;">
                                            📝 Brouillon
                                        </span>
                                    <?php elseif ($wc_status === 'trash'): ?>
                                        <span style="background:#ff9800;color:white;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;display:inline-block;">
                                            🗑️ Corbeille
                                        </span>
                                    <?php elseif ($wc_status === 'deleted'): ?>
                                        <span style="background:#dc3232;color:white;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;display:inline-block;">
                                            ❌ Supprimé
                                        </span>
                                    <?php else: ?>
                                        <span style="background:#826eb4;color:white;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;display:inline-block;">
                                            ⚡ <?php echo esc_html(ucfirst($wc_status)); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
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
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
    .wp-list-table th { font-weight:600; background:#f0f0f1; padding:8px 10px; }
    .wp-list-table td { vertical-align:middle; padding:8px; }
    .wp-list-table tbody tr:hover { background:#f6f7f7; }
    
    #variants-prices-modal, #import-modal {
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

    .variants-prices-modal-content, .import-modal-content {
        background: white;
        padding: 30px;
        border-radius: 12px;
        width: 90%;
        max-width: 800px;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }
    
    .variant-price-card {
        background: #f8f9fa;
        border: 1px solid #e0e0e0;
        border-left: 4px solid #2271b1;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 12px;
        transition: all 0.2s ease;
    }
    
    .variant-price-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        border-left-color: #46b450;
    }
    
    .variant-sku {
        font-size: 15px;
        font-weight: 700;
        color: #333;
        margin-bottom: 10px;
    }
    
    .variant-attributes {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 12px;
    }
    
    .variant-attribute-badge {
        background: #e0e0e0;
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 12px;
        color: #333;
        font-weight: 500;
    }
    
    .variant-prices {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        padding-top: 12px;
        border-top: 1px solid #ddd;
    }
    
    .price-item {
        text-align: center;
    }
    
    .price-label {
        font-size: 11px;
        color: #666;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .price-value {
        font-size: 18px;
        font-weight: 700;
    }
    
    .price-base {
        color: #dc3232;
    }
    
    .price-margin {
        color: #46b450;
    }
    
    .price-final {
        color: #2271b1;
    }
    
    .variants-loading {
        text-align: center;
        padding: 60px 20px;
        color: #666;
    }
    
    .variants-loading-spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid #2271b1;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .product-info-card {
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .product-info-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .product-info-row:last-child {
        border-bottom: none;
    }
    
    .product-info-label {
        font-weight: 600;
        color: #666;
        font-size: 13px;
    }
    
    .product-info-value {
        font-size: 13px;
        color: #333;
    }

    .tablenav { margin: 15px 0; }
    .tablenav-pages { float: right; }
    .pagination-links { white-space: nowrap; display: inline-block; margin-left: 10px; }
    .pagination-links .button { margin-left: 2px; padding: 4px 8px; font-size: 13px; }
    .current-page { width: 50px; text-align: center; margin: 0 4px; }
    .displaying-num { margin-right: 10px; padding: 4px 0; font-size: 13px; color: #646970; }
    
    .select2-container .select2-selection--single {
        height: 32px !important;
        border: 1px solid #8c8f94 !important;
        border-radius: 4px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 30px !important;
        padding-left: 12px !important;
        font-size: 13px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 30px !important;
    }
    
    .select2-dropdown {
        border: 1px solid #8c8f94 !important;
        border-radius: 4px !important;
    }
    
    .select2-search--dropdown .select2-search__field {
        border: 1px solid #8c8f94 !important;
        border-radius: 4px !important;
        padding: 6px 12px !important;
        font-size: 13px !important;
    }
    
    .select2-results__option {
        padding: 6px 12px !important;
        font-size: 13px !important;
    }
    
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #2271b1 !important;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($){
        
        var progressPollInterval = null;
        var parentCreationStarted = false;
        var parentProgressPercent = 0;
        
        function startProgressPolling() {
            
            progressPollInterval = setInterval(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'api_get_import_progress',
                        nonce: '<?php echo wp_create_nonce('api_import_wc_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            
                            if (data.variants_current === 0 && data.variants_total > 0) {
                                if (!parentCreationStarted) {
                                    parentCreationStarted = true;
                                    parentProgressPercent = 30;
                                } else if (parentProgressPercent < 90) {
                                    parentProgressPercent += 10;
                                }
                                
                                $('#parent-progress-bar').css('width', parentProgressPercent + '%');
                                $('#parent-progress-percent').text(parentProgressPercent + '%');
                            }
                            
                            if (data.variants_current > 0) {
                                $('#parent-progress-bar').css('width', '100%');
                                $('#parent-progress-percent').html('<strong style="color:#46b450;">100%</strong>');
                                
                                setTimeout(function() {
                                    $('#parent-progress-section').fadeOut(300, function() {
                                        $('#variants-progress-section').fadeIn(300);
                                    });
                                }, 500);
                            }
                            
                            updateProgressBar(
                                data.variants_current,
                                data.variants_total,
                                data.products_current,
                                data.products_total
                            );
                        }
                    },
                    error: function(xhr, status, error) {
                    }
                });
            }, 1000);
        }
        
        function stopProgressPolling() {
            if (progressPollInterval) {
                clearInterval(progressPollInterval);
                progressPollInterval = null;
            }
        }
        
        function updateProgressBar(variantsProcessed, totalVariants, productsImported, totalProducts) {
            var percent = totalVariants > 0 ? Math.round((variantsProcessed / totalVariants) * 100) : 0;
            
            $('#variants-progress-bar').css({
                'width': percent + '%',
                'background-color': getProgressColor(percent)
            });
            
            $('#variants-progress-percent').text(percent + '%');
            $('#variants-counter').text(variantsProcessed + '/' + totalVariants + ' variantes');
            $('#products-counter').text(productsImported + '/' + totalProducts + ' produits');
        }
        
        function getProgressColor(percent) {
            var red = Math.round(220 - (percent / 100) * 150);
            var green = Math.round(50 + (percent / 100) * 130);
            var blue = Math.round(50 + (percent / 100) * 30);
            return 'rgb(' + red + ', ' + green + ', ' + blue + ')';
        }
        
        $('#filter-brand-select').select2({
            placeholder: 'Rechercher une marque...',
            allowClear: true,
            width: '100%',
            language: {
                noResults: function() { return "Aucune marque trouvée"; },
                searching: function() { return "Recherche..."; }
            }
        });
        
        $('#filter-category-select').select2({
            placeholder: 'Rechercher une catégorie...',
            allowClear: true,
            width: '100%',
            language: {
                noResults: function() { return "Aucune catégorie trouvée"; },
                searching: function() { return "Recherche..."; }
            }
        });
        
        $('.view-variant-prices').on('click', function() {
            var productId = $(this).data('product-id');
            
            $('#variants-prices-container').html(
                '<div class="variants-loading">' +
                '<div class="variants-loading-spinner"></div>' +
                '<p>Chargement des prix des variantes...</p>' +
                '</div>'
            );
            
            $('#variant-product-name').text('Chargement...');
            $('#variants-prices-modal').fadeIn(200);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_get_variant_prices',
                    nonce: '<?php echo wp_create_nonce('api_import_wc_nonce'); ?>',
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var variants = data.variants;
                        
                        $('#variant-product-name').text(data.product_name);
                        $('#variants-prices-container').empty();
                        
                        if (variants && variants.length > 0) {
                            variants.forEach(function(variant) {
                                var attributesHtml = '';
                                if (variant.attributes && variant.attributes.length > 0) {
                                    attributesHtml = '<div class="variant-attributes">';
                                    variant.attributes.forEach(function(attr) {
                                        attributesHtml += '<span class="variant-attribute-badge">' + attr.name + ': ' + attr.value + '</span>';
                                    });
                                    attributesHtml += '</div>';
                                }
                                
                                var cardHtml = '<div class="variant-price-card">';
                                cardHtml += '<div class="variant-sku">📌 ' + (variant.sku || 'SKU non défini') + '</div>';
                                cardHtml += attributesHtml;
                                cardHtml += '<div class="variant-prices">';
                                
                                cardHtml += '<div class="price-item">';
                                cardHtml += '<div class="price-label">Prix Initial</div>';
                                cardHtml += '<div class="price-value price-base">' + (variant.base_price > 0 ? parseFloat(variant.base_price).toFixed(2) + '€' : '-') + '</div>';
                                cardHtml += '</div>';
                                
                                cardHtml += '<div class="price-item">';
                                cardHtml += '<div class="price-label">Marge</div>';
                                cardHtml += '<div class="price-value price-margin">+' + (variant.margin > 0 ? variant.margin + '%' : '0%') + '</div>';
                                cardHtml += '</div>';
                                
                                cardHtml += '<div class="price-item">';
                                cardHtml += '<div class="price-label">Prix Final</div>';
                                cardHtml += '<div class="price-value price-final">' + (variant.final_price > 0 ? parseFloat(variant.final_price).toFixed(2) + '€' : '-') + '</div>';
                                cardHtml += '</div>';
                                
                                cardHtml += '</div></div>';
                                
                                $('#variants-prices-container').append(cardHtml);
                            });
                        } else {
                            $('#variants-prices-container').html('<p style="text-align:center;color:#666;padding:40px;">Aucune variante disponible</p>');
                        }
                    } else {
                        $('#variants-prices-container').html('<p style="text-align:center;color:#dc3232;padding:40px;">❌ ' + (response.data.message || 'Erreur de chargement') + '</p>');
                    }
                },
                error: function() {
                    $('#variants-prices-container').html('<p style="text-align:center;color:#dc3232;padding:40px;">❌ Erreur réseau</p>');
                }
            });
        });
        
        $('#close-variants-prices-modal').on('click', function() {
            $('#variants-prices-modal').fadeOut(200);
        });
        
        $('#variants-prices-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).fadeOut(200);
            }
        });
        
        function updateImportButton() {
            var importedCount = 0;
            var notImportedCount = 0;
            var deletedCount = 0;
            var noPriceCount = 0;
            
            $('.product-checkbox:checked').each(function() {
                if ($(this).data('deleted') == '1') {
                    deletedCount++;
                } else if ($(this).data('no-price') == '1') {
                    noPriceCount++;
                } else if ($(this).data('imported') == '1') {
                    importedCount++;
                } else {
                    notImportedCount++;
                }
            });
            
            var totalChecked = importedCount + notImportedCount + deletedCount + noPriceCount;
            
            if (totalChecked === 0) {
                $('#import-btn-text').html('✅ Importer vers WooCommerce');
                $('#start-import').prop('disabled', false);
            } else if (deletedCount > 0) {
                $('#import-btn-text').html('⚠️ ' + deletedCount + ' produit(s) supprimé(s)');
                $('#start-import').prop('disabled', true);
            } else if (noPriceCount > 0) {
                $('#import-btn-text').html('⚠️ ' + noPriceCount + ' produit(s) sans prix');
                $('#start-import').prop('disabled', true);
            } else if (notImportedCount > 0 && importedCount > 0) {
                $('#import-btn-text').html('✅ Importer/MAJ (' + totalChecked + ')');
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
        
        function displayProductInfo(productData) {
            var html = '<div class="product-info-card">';
            html += '<div style="font-weight:700;font-size:16px;color:#2271b1;margin-bottom:12px;">📦 ' + productData.sku + '</div>';
            
            html += '<div class="product-info-row">';
            html += '<span class="product-info-label">Nom:</span>';
            html += '<span class="product-info-value">' + productData.name + '</span>';
            html += '</div>';
            
            if (productData.brand) {
                html += '<div class="product-info-row">';
                html += '<span class="product-info-label">Marque:</span>';
                html += '<span class="product-info-value">' + productData.brand + '</span>';
                html += '</div>';
            }
            
            html += '<div class="product-info-row">';
            html += '<span class="product-info-label">Catégorie:</span>';
            html += '<span class="product-info-value"><strong>' + productData.category + '</strong></span>';
            html += '</div>';
            
            html += '<div class="product-info-row">';
            html += '<span class="product-info-label">Prix initial:</span>';
            html += '<span class="product-info-value" style="color:#dc3232;font-weight:600;">' + parseFloat(productData.base_price).toFixed(2) + '€</span>';
            html += '</div>';
            
            if (productData.margin > 0) {
                html += '<div class="product-info-row">';
                html += '<span class="product-info-label">Marge appliquée:</span>';
                html += '<span class="product-info-value" style="color:#46b450;font-weight:600;">+' + productData.margin + '%</span>';
                html += '</div>';
            }
            
            html += '<div class="product-info-row">';
            html += '<span class="product-info-label">Prix final:</span>';
            html += '<span class="product-info-value" style="color:#2271b1;font-weight:700;font-size:15px;">' + parseFloat(productData.final_price).toFixed(2) + '€</span>';
            html += '</div>';
            
            html += '<div class="product-info-row">';
            html += '<span class="product-info-label">Variantes:</span>';
            html += '<span class="product-info-value"><span style="background:#0073aa;color:white;padding:2px 8px;border-radius:3px;font-size:11px;">' + productData.variants_count + '</span></span>';
            html += '</div>';
            
            html += '</div>';
            
            return html;
        }
        
        $('#start-import').on('click', function() {
            
            var selectedIds = [];
            var selectedProductsData = [];
            var totalVariantsExpected = 0;
            
            $('.product-checkbox:checked:not(:disabled)').each(function() {
                var productId = parseInt($(this).val());
                var variantsCount = parseInt($(this).data('variants-count')) || 1;
                
                var productData = {
                    id: productId,
                    sku: $(this).data('sku'),
                    name: $(this).data('name'),
                    brand: $(this).data('brand'),
                    category: $(this).data('category'),
                    base_price: parseFloat($(this).data('base-price')),
                    final_price: parseFloat($(this).data('final-price')),
                    margin: parseFloat($(this).data('margin')),
                    variants_count: variantsCount
                };
                
                selectedIds.push(productId);
                selectedProductsData.push(productData);
                totalVariantsExpected += variantsCount;
            });

            if (selectedIds.length === 0) {
                alert('Veuillez sélectionner au moins un produit');
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_init_import_progress',
                    nonce: '<?php echo wp_create_nonce('api_import_wc_nonce'); ?>',
                    total_variants: totalVariantsExpected,
                    total_products: selectedIds.length
                }
            });

            $('#import-modal').fadeIn(200);
            $('#import-progress-view').show();
            $('#import-summary').hide();
            
            $('#parent-progress-section').show();
            $('#variants-progress-section').hide();
            $('#parent-progress-bar').css('width', '0%');
            $('#parent-progress-percent').text('0%');
            $('#variants-progress-bar').css('width', '0%');
            $('#variants-progress-percent').text('0%');
            $('#variants-counter').text('0/' + totalVariantsExpected + ' variantes');
            $('#products-counter').text('0/' + selectedIds.length + ' produits');
            $('#current-product-info').html('En attente...');
            
            parentCreationStarted = false;
            parentProgressPercent = 0;
            
            var totalProductsImported = 0;
            var totalVariantsProcessed = 0;
            var totalErrors = 0;
            var errorMessages = [];
            
            startProgressPolling();
            
            function importNext(index) {
                if (index >= selectedIds.length) {
                    stopProgressPolling();
                    
                    $('#import-progress-view').hide();
                    $('#total-imported').text(totalProductsImported);
                    $('#total-variants').text(totalVariantsProcessed);
                    $('#total-errors').text(totalErrors);
                    
                    if (totalErrors > 0) {
                        $('#errors-summary').show();
                        $('#error-list').empty();
                        errorMessages.forEach(function(msg) {
                            $('#error-list').append('<li>' + msg + '</li>');
                        });
                    }
                    
                    $('#import-summary').fadeIn(300);
                    return;
                }
                
                var productId = selectedIds[index];
                var productData = selectedProductsData[index];
                var currentNum = index + 1;
                
                var productInfoHtml = '<div style="text-align:center;color:#f0b849;font-size:14px;margin-bottom:10px;">⏳ Produit ' + currentNum + '/' + selectedIds.length + ' en cours...</div>';
                productInfoHtml += displayProductInfo(productData);
                
                $('#current-product-info').html(productInfoHtml);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'api_import_single_product',
                        nonce: '<?php echo wp_create_nonce('api_import_wc_nonce'); ?>',
                        product_id: productId
                    },
                    success: function(response) {
                        
                        if (response.success) {
                            var data = response.data;
                            totalProductsImported++;
                            
                            var variantsCount = data.variants_count || 1;
                            totalVariantsProcessed += variantsCount;
                            
                            var successHtml = '<div style="text-align:center;color:#46b450;font-size:16px;margin-bottom:15px;font-weight:700;">✅ Produit importé avec succès !</div>';
                            successHtml += displayProductInfo(productData);
                            successHtml += '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;text-align:center;">';
                            successHtml += '<span style="color:#2271b1;font-weight:600;">WooCommerce ID: ' + data.wc_product_id + '</span>';
                            successHtml += '</div>';
                            
                            $('#current-product-info').html(successHtml);
                        } else {
                            totalErrors++;
                            var sku = response.data && response.data.sku ? response.data.sku : 'Produit ' + productId;
                            var message = response.data && response.data.message ? response.data.message : 'Erreur inconnue';
                            errorMessages.push(sku + ': ' + message);
                            
                            var errorHtml = '<div style="text-align:center;color:#dc3232;font-size:16px;margin-bottom:15px;font-weight:700;">❌ Erreur d\'importation</div>';
                            errorHtml += displayProductInfo(productData);
                            errorHtml += '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;text-align:center;color:#dc3232;">';
                            errorHtml += '<strong>Erreur:</strong> ' + message;
                            errorHtml += '</div>';
                            
                            $('#current-product-info').html(errorHtml);
                        }
                    },
                    error: function(xhr, status, error) {
                        totalErrors++;
                        errorMessages.push('Produit ' + productId + ': Erreur réseau - ' + error);
                        
                        var errorHtml = '<div style="text-align:center;color:#dc3232;font-size:16px;margin-bottom:15px;font-weight:700;">❌ Erreur réseau</div>';
                        errorHtml += displayProductInfo(productData);
                        errorHtml += '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;text-align:center;color:#dc3232;">';
                        errorHtml += '<strong>Erreur:</strong> ' + error;
                        errorHtml += '</div>';
                        
                        $('#current-product-info').html(errorHtml);
                    },
                    complete: function() {
                        setTimeout(function() {
                            importNext(index + 1);
                        }, 500);
                    }
                });
            }
            
            importNext(0);
        });

        $('#close-modal-btn').on('click', function() {
            stopProgressPolling();
            $('#import-modal').fadeOut(200);
            location.reload();
        });
    });
    </script>
    <?php
}