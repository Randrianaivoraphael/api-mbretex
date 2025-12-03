<?php
/**
 * Plugin Name: API Imbretex
 * Description: Import automatique des produits Imbretex avec prix et stock dans WooCommerce
 * Version: 6.3
 * Author: Raphael
 */

if (!defined('ABSPATH')) exit;

define('API_BASE_URL', 'https://api.imbretex.fr');
define('API_TOKEN', 'G%E]Bi0!o;PWb5^2aDDZcYt|u+#7s@[n$Ez6j].2St^E4ZJO.?O#.Nyf@d0*');

// ============================================================
// FONCTION : RÉCUPÉRER LES PRODUITS DE L'API
// ============================================================
function api_fetch_products_from_api($since_created = null, $since_updated = null, $per_page = 10, $max_products = 20) {
    $api_url = API_BASE_URL . '/api/products/products';
    $per_page = min($per_page, 50);
    $all_products = [];
    $page = 1;
    $max_pages = ceil($max_products / $per_page);

    do {
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

        if (is_wp_error($response)) break;

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) break;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['products']) || empty($data['products'])) break;

        foreach ($data['products'] as $product_api) {
            $variant = $product_api['variants'][0] ?? null;
            if (!$variant) continue;

            // IMPORTANT : Utiliser la même logique que dans api_create_woocommerce_product_full
            $is_variable = count($product_api['variants']) > 1;
            
            // Pour produit simple : utiliser variantReference
            // Pour produit variable : utiliser reference du produit parent
            if ($is_variable) {
                $main_reference = $product_api['reference'] ?? $variant['variantReference'];
            } else {
                $main_reference = $variant['variantReference'] ?? $product_api['reference'];
            }

            $all_products[] = [
                'sku' => $main_reference,
                'reference' => $main_reference,
                'name' => $variant['title']['fr'] ?? $main_reference,
                'brand' => $product_api['brands']['name'] ?? '',
                'created_at' => $product_api['createdAt'],
                'updated_at' => $product_api['updatedAt'],
                'product_data' => $product_api
            ];

            if (count($all_products) >= $max_products) break 2;
        }

        $page++;
        if (count($data['products']) < $per_page || $page > $max_pages) break;

    } while (true);

    return $all_products;
}

// ============================================================
// FONCTION : RÉCUPÉRER PRIX/STOCK
// ============================================================
function api_get_product_price_stock($reference) {
    $api_url = API_BASE_URL . '/api/products/price-stock';
    $params = ['products' => $reference];
    $full_url = $api_url . '?' . http_build_query($params);

    $response = wp_remote_get($full_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . API_TOKEN,
            'Accept' => 'application/json'
        ],
        'timeout' => 15
    ]);

    if (is_wp_error($response)) return null;

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) return null;

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['products'])) return null;

    return $data['products'][$reference] ?? null;
}

// ============================================================
// FONCTION : TÉLÉCHARGER ET ATTACHER UNE IMAGE
// ============================================================
function api_download_and_attach_image($image_url, $product_id = 0) {
    global $wpdb;
    
    if (!$image_url) return null;
    
    $image_url = esc_url_raw($image_url);
    $image_name = basename(parse_url($image_url, PHP_URL_PATH));

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE guid=%s",
        $image_url
    ));
    
    if ($existing) {
        return intval($existing);
    }

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        error_log('API Imbretex - Échec téléchargement image : ' . $tmp->get_error_message());
        return null;
    }

    $file_array = [
        'name' => $image_name,
        'tmp_name' => $tmp
    ];

    $id = media_handle_sideload($file_array, $product_id);
    
    if (is_wp_error($id)) {
        @unlink($tmp);
        error_log('API Imbretex - Échec sideload image : ' . $id->get_error_message());
        return null;
    }

    return $id;
}

// ============================================================
// FONCTION : S'ASSURER QUE LES ATTRIBUTS GLOBAUX EXISTENT
// ============================================================
function api_ensure_global_attributes() {
    $attributes = [
        'pa_taille' => 'Taille',
        'pa_couleur' => 'Couleur'
    ];
    
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

// ============================================================
// FONCTION : CRÉER UN TERME D'ATTRIBUT
// ============================================================
function api_create_attribute_term($taxonomy, $term_name) {
    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, ['product'], []);
    }
    
    $term = get_term_by('name', $term_name, $taxonomy);
    
    if (!$term) {
        $term = wp_insert_term($term_name, $taxonomy);
        if (is_wp_error($term)) {
            return null;
        }
        $term_id = $term['term_id'];
    } else {
        $term_id = $term->term_id;
    }
    
    return $term_id;
}

// ============================================================
// FONCTION : CRÉER/METTRE À JOUR PRODUIT WOOCOMMERCE
// ============================================================
function api_create_woocommerce_product_full($product_api_data, $price_stock_data) {
    if (!function_exists('wc_get_product')) return false;

    api_ensure_global_attributes();

    $variants = $product_api_data['variants'] ?? [];
    if (empty($variants)) {
        error_log('API Imbretex - Aucune variante trouvée pour le produit');
        return false;
    }

    $is_variable = count($variants) > 1;
    $first_variant = $variants[0];
    
    // Pour produit simple : utiliser variantReference
    // Pour produit variable : utiliser reference du produit parent
    if ($is_variable) {
        $main_reference = $product_api_data['reference'] ?? $first_variant['variantReference'];
    } else {
        $main_reference = $first_variant['variantReference'] ?? $product_api_data['reference'];
    }
    
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
    
    // Définir le statut sur brouillon (non publié par défaut)
    $product->set_status('draft');

    if (!empty($first_variant['longDescription']['fr'])) {
        $product->set_description($first_variant['longDescription']['fr']);
    }
    if (!empty($first_variant['description']['fr'])) {
        $product->set_short_description($first_variant['description']['fr']);
    }

    // Gestion catégorie
    $category_ids = [];
    if (!empty($first_variant['categories']) && is_array($first_variant['categories'])) {
        foreach ($first_variant['categories'] as $cat_data) {
            $cat_name = null;
            if (isset($cat_data['categories']['fr']) && !empty($cat_data['categories']['fr'])) {
                $cat_name = $cat_data['categories']['fr'];
            } elseif (isset($cat_data['families']['fr']) && !empty($cat_data['families']['fr'])) {
                $cat_name = $cat_data['families']['fr'];
            }

            if ($cat_name) {
                $term = term_exists($cat_name, 'product_cat');
                if (!$term) {
                    $term = wp_insert_term($cat_name, 'product_cat');
                }
                if (!is_wp_error($term)) {
                    $term_id = is_array($term) ? $term['term_id'] : $term;
                    $category_ids[] = $term_id;
                }
            }
        }
    }
    
    if (empty($category_ids)) {
        $term = term_exists('Autres', 'product_cat');
        if (!$term) {
            $term = wp_insert_term('Autres', 'product_cat');
        }
        if (!is_wp_error($term)) {
            $term_id = is_array($term) ? $term['term_id'] : $term;
            $category_ids[] = $term_id;
        }
    }
    
    if (!empty($category_ids)) {
        $product->set_category_ids($category_ids);
    }

    // Gestion des TAGS
    $tag_names = [];
    if (!empty($first_variant['tags']) && is_array($first_variant['tags'])) {
        foreach ($first_variant['tags'] as $tag) {
            if (is_string($tag)) {
                $tag_names[] = $tag;
            }
        }
    }
    
    if (!empty($first_variant['keywords']) && is_array($first_variant['keywords'])) {
        foreach ($first_variant['keywords'] as $keyword_group) {
            if (isset($keyword_group['fr']) && is_array($keyword_group['fr'])) {
                foreach ($keyword_group['fr'] as $keyword) {
                    $tag_names[] = $keyword;
                }
            }
        }
    }
    
    if (!empty($tag_names)) {
        $tag_ids = [];
        foreach ($tag_names as $tag_name) {
            $term = term_exists($tag_name, 'product_tag');
            if (!$term) {
                $term = wp_insert_term($tag_name, 'product_tag');
            }
            if (!is_wp_error($term)) {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                $tag_ids[] = $term_id;
            }
        }
        $product->set_tag_ids($tag_ids);
    }

    // Gestion des META DATA
    if (!empty($first_variant['characteristics']['genders']) && is_array($first_variant['characteristics']['genders'])) {
        $product->update_meta_data('_gender', implode(', ', $first_variant['characteristics']['genders']));
    }
    
    if (!empty($first_variant['netWeight']['value'])) {
        $product->update_meta_data('_net_weight', $first_variant['netWeight']['value']);
    }
    
    if (!empty($first_variant['grammage']['value'])) {
        $product->update_meta_data('_grammage', $first_variant['grammage']['value']);
    }
    
    if (!empty($first_variant['countryOfOrigin']) && is_array($first_variant['countryOfOrigin'])) {
        $product->update_meta_data('_country_of_origin', implode(', ', $first_variant['countryOfOrigin']));
    }
    
    if (!empty($first_variant['longTitle']['fr'])) {
        $product->update_meta_data('_long_title', $first_variant['longTitle']['fr']);
    }

    // Gestion images du produit parent
    if (!empty($first_variant['images']) && is_array($first_variant['images'])) {
        $attachment_ids = [];

        foreach ($first_variant['images'] as $image_data) {
            $image_url = null;
            if (is_string($image_data)) {
                $image_url = $image_data;
            } elseif (is_array($image_data) && isset($image_data['url'])) {
                $image_url = $image_data['url'];
            }

            if ($image_url) {
                $attachment_id = api_download_and_attach_image($image_url, 0);
                if ($attachment_id) {
                    $attachment_ids[] = $attachment_id;
                }
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
        error_log('API Imbretex - Produit parent sauvegardé : ID=' . $product_id . ', SKU=' . $main_reference);
    } catch (Exception $e) {
        error_log('API Imbretex - Erreur sauvegarde produit parent : ' . $e->getMessage());
        return false;
    }

    // SI PRODUIT SIMPLE
    if (!$is_variable) {
        $variant_sku = $first_variant['variantReference'] ?? $main_reference;
        $variant_price_stock = api_get_product_price_stock($variant_sku);
        
        if ($variant_price_stock) {
            $regular_price = null;
            $sale_price = null;

            if (isset($variant_price_stock['price']) && $variant_price_stock['price'] > 0) {
                $regular_price = floatval($variant_price_stock['price']);
            }
            
            if (isset($variant_price_stock['price_box']) && $variant_price_stock['price_box'] > 0) {
                $price_box = floatval($variant_price_stock['price_box']);
                if ($regular_price && $price_box < $regular_price) {
                    $sale_price = $price_box;
                } elseif (!$regular_price) {
                    $regular_price = $price_box;
                }
            }

            if ($regular_price) {
                $product->set_regular_price($regular_price);
                if ($sale_price) {
                    $product->set_sale_price($sale_price);
                }
            }

            $total_stock = 0;
            if (isset($variant_price_stock['stock'])) {
                $total_stock += intval($variant_price_stock['stock']);
            }
            if (isset($variant_price_stock['stock_supplier'])) {
                $total_stock += intval($variant_price_stock['stock_supplier']);
            }

            $product->set_manage_stock(true);
            $product->set_stock_quantity($total_stock);
            $product->set_stock_status($total_stock > 0 ? 'instock' : 'outofstock');
        }

        $attributes = api_create_product_attributes($first_variant, false);
        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }

        $product->save();
        return $product_id;
    }

    // SI PRODUIT VARIABLE
    $all_sizes = [];
    $all_colors = [];
    
    foreach ($variants as $variant) {
        if (!empty($variant['attributes']) && is_array($variant['attributes'])) {
            foreach ($variant['attributes'] as $attr) {
                if ($attr['type'] === 'sizes' && !empty($attr['value'])) {
                    $size_value = $attr['value'];
                    $size_term_id = api_create_attribute_term('pa_taille', $size_value);
                    if ($size_term_id) {
                        $all_sizes[$size_term_id] = $size_value;
                    }
                }
                if ($attr['type'] === 'color' && !empty($attr['value'])) {
                    $color_value = $attr['value'];
                    $color_term_id = api_create_attribute_term('pa_couleur', $color_value);
                    if ($color_term_id) {
                        $all_colors[$color_term_id] = $color_value;
                    }
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
    
    if (!empty($attributes)) {
        $product->set_attributes($attributes);
        $product->save();
    }

    // Créer les variations AVEC IMAGES
    foreach ($variants as $variant) {
        $variant_sku = $variant['variantReference'] ?? '';
        if (!$variant_sku) continue;

        $variation_id = wc_get_product_id_by_sku($variant_sku);
        
        if ($variation_id) {
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
        }

        $variation->set_sku($variant_sku);
        
        // Définir les attributs
        $variation_attributes = [];
        
        if (!empty($variant['attributes']) && is_array($variant['attributes'])) {
            foreach ($variant['attributes'] as $attr) {
                if ($attr['type'] === 'sizes' && !empty($attr['value'])) {
                    $term = get_term_by('name', $attr['value'], 'pa_taille');
                    if ($term) {
                        $variation_attributes['pa_taille'] = $term->slug;
                    }
                }
                if ($attr['type'] === 'color' && !empty($attr['value'])) {
                    $term = get_term_by('name', $attr['value'], 'pa_couleur');
                    if ($term) {
                        $variation_attributes['pa_couleur'] = $term->slug;
                    }
                }
            }
        }
        
        $variation->set_attributes($variation_attributes);

        // IMPORTANT : Attribuer l'image spécifique à cette variation
        if (!empty($variant['images']) && is_array($variant['images'])) {
            $first_image = $variant['images'][0];
            $image_url = null;
            
            if (is_string($first_image)) {
                $image_url = $first_image;
            } elseif (is_array($first_image) && isset($first_image['url'])) {
                $image_url = $first_image['url'];
            }
            
            if ($image_url) {
                $attachment_id = api_download_and_attach_image($image_url, $product_id);
                if ($attachment_id) {
                    $variation->set_image_id($attachment_id);
                }
            }
        }

        // Prix et stock
        $variant_price_stock = api_get_product_price_stock($variant_sku);
        
        if ($variant_price_stock) {
            $regular_price = null;
            $sale_price = null;

            if (isset($variant_price_stock['price']) && $variant_price_stock['price'] > 0) {
                $regular_price = floatval($variant_price_stock['price']);
            }
            
            if (isset($variant_price_stock['price_box']) && $variant_price_stock['price_box'] > 0) {
                $price_box = floatval($variant_price_stock['price_box']);
                if ($regular_price && $price_box < $regular_price) {
                    $sale_price = $price_box;
                } elseif (!$regular_price) {
                    $regular_price = $price_box;
                }
            }

            if ($regular_price) {
                $variation->set_regular_price($regular_price);
                if ($sale_price) {
                    $variation->set_sale_price($sale_price);
                }
            }

            $total_stock = 0;
            if (isset($variant_price_stock['stock'])) {
                $total_stock += intval($variant_price_stock['stock']);
            }
            if (isset($variant_price_stock['stock_supplier'])) {
                $total_stock += intval($variant_price_stock['stock_supplier']);
            }

            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($total_stock);
            $variation->set_stock_status($total_stock > 0 ? 'instock' : 'outofstock');
        }

        $variation->save();
        error_log('API Imbretex - Variation sauvegardée : ID=' . $variation->get_id() . ', SKU=' . $variant_sku);
    }

    WC_Product_Variable::sync($product_id);

    return $product_id;
}

// ============================================================
// FONCTION : CRÉER LES ATTRIBUTS D'UN PRODUIT
// ============================================================
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

// ============================================================
// CRON : RÉCUPÉRATION AUTOMATIQUE DES NOUVEAUX PRODUITS
// ============================================================

// Activer le cron lors de l'activation du plugin
register_activation_hook(__FILE__, 'api_schedule_daily_sync');
function api_schedule_daily_sync() {
    if (!wp_next_scheduled('api_daily_product_sync')) {
        wp_schedule_event(time(), 'daily', 'api_daily_product_sync');
    }
}

// Désactiver le cron lors de la désactivation du plugin
register_deactivation_hook(__FILE__, 'api_unschedule_daily_sync');
function api_unschedule_daily_sync() {
    $timestamp = wp_next_scheduled('api_daily_product_sync');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'api_daily_product_sync');
    }
}

// Action du cron : récupérer les produits des dernières 24h
add_action('api_daily_product_sync', 'api_sync_new_products');
function api_sync_new_products() {
    // Date d'hier au format de l'API
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    
    // Récupérer les produits créés ou modifiés depuis hier
    $new_products = api_fetch_products_from_api(
        $yesterday, // sinceCreated
        $yesterday, // sinceUpdated
        10,
        50 // Max 50 produits par jour
    );
    
    if (!empty($new_products)) {
        // Stocker les nouveaux produits
        set_transient('api_new_products', $new_products, DAY_IN_SECONDS * 7); // 7 jours
        
        // Compter les nouveaux vs mis à jour
        $count_new = 0;
        $count_updated = 0;
        
        foreach ($new_products as $product) {
            $wc_product_id = wc_get_product_id_by_sku($product['sku']);
            if ($wc_product_id) {
                $count_updated++;
            } else {
                $count_new++;
            }
        }
        
        // Stocker les statistiques
        update_option('api_sync_stats', [
            'last_sync' => current_time('mysql'),
            'total' => count($new_products),
            'new' => $count_new,
            'updated' => $count_updated,
            'date' => $yesterday
        ]);
        
        error_log('API Imbretex - Sync automatique : ' . count($new_products) . ' produits récupérés');
    }
}

// Fonction pour obtenir le nombre de nouveaux produits
function api_get_new_products_count() {
    $new_products = get_transient('api_new_products');
    if (empty($new_products)) {
        return 0;
    }
    
    $count_new = 0;
    foreach ($new_products as $product) {
        $wc_product_id = wc_get_product_id_by_sku($product['sku']);
        if (!$wc_product_id) {
            $count_new++;
        }
    }
    
    return $count_new;
}

// Fonction pour obtenir le nombre de produits mis à jour
function api_get_updated_products_count() {
    $new_products = get_transient('api_new_products');
    if (empty($new_products)) {
        return 0;
    }
    
    $count_updated = 0;
    foreach ($new_products as $product) {
        $wc_product_id = wc_get_product_id_by_sku($product['sku']);
        if ($wc_product_id) {
            $count_updated++;
        }
    }
    
    return $count_updated;
}

// AJAX : Marquer les produits comme vus
add_action('wp_ajax_api_mark_products_seen', function() {
    delete_transient('api_new_products');
    delete_option('api_sync_stats');
    wp_send_json_success();
});

// AJAX : Lancer le cron manuellement
add_action('wp_ajax_api_run_sync_now', function() {
    check_ajax_referer('api_sync_nonce', 'nonce');
    
    api_sync_new_products();
    
    $stats = get_option('api_sync_stats', []);
    wp_send_json_success([
        'message' => 'Synchronisation terminée',
        'stats' => $stats
    ]);
});

// ============================================================
// AJAX : IMPORTER UN SEUL PRODUIT
// ============================================================
add_action('wp_ajax_api_import_single_product', function() {
    check_ajax_referer('api_import_nonce', 'nonce');
    
    $index = intval($_POST['index']);
    $products_cache = get_transient('api_products_current_list');
    
    if (!$products_cache || !isset($products_cache[$index])) {
        wp_send_json_error(['message' => 'Produit non trouvé']);
        return;
    }
    
    $product = $products_cache[$index];
    
    try {
        $wc_product_id = api_create_woocommerce_product_full($product['product_data'], null);
        
        if ($wc_product_id) {
            wp_send_json_success([
                'sku' => $product['sku'],
                'name' => $product['name']
            ]);
        } else {
            wp_send_json_error(['message' => 'Échec création produit']);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// ============================================================
// PAGE ADMIN
// ============================================================
add_action('admin_menu', function() {
    $new_count = api_get_new_products_count();
    $updated_count = api_get_updated_products_count();
    $total_count = $new_count + $updated_count;
    
    // Toujours afficher le badge, même si le compte est 0
    $menu_title = 'API Imbretex <span class="update-plugins count-' . $total_count . '"><span class="update-count">' . $total_count . '</span></span>';
    
    add_menu_page(
        'API Imbretex',
        $menu_title,
        'manage_options',
        'api-products-list',
        'api_products_list_page',
        'dashicons-download'
    );
});

function api_products_list_page() {
    $search_since_created = $_GET['since_created'] ?? '';
    $search_since_updated = $_GET['since_updated'] ?? '';
    $per_page = 10;
    $max_products = intval($_GET['max_products'] ?? 20);
    $force_reload = isset($_GET['reload']);

    // Vérifier si des produits synchronisés existent
    $sync_products = get_transient('api_new_products');
    $has_sync_products = !empty($sync_products);
    
    // Par défaut, afficher les produits synchronisés s'ils existent
    // Sinon, charger via API normale
    $showing_sync = false;
    
    if ($has_sync_products && empty($search_since_created) && empty($search_since_updated) && !$force_reload) {
        // Afficher les produits synchronisés par défaut
        $products = $sync_products;
        $showing_sync = true;
    } else {
        // Comportement normal - charger via API
        $cache_key = 'api_products_list_' . md5($search_since_created . $search_since_updated . $per_page . $max_products);
        if ($force_reload) {
            delete_transient($cache_key);
        }

        $products = get_transient($cache_key);
        
        // Si pas de cache OU si le cache est vide, charger depuis l'API
        if (false === $products || empty($products)) {
            $products = api_fetch_products_from_api(
                !empty($search_since_created) ? $search_since_created : null,
                !empty($search_since_updated) ? $search_since_updated : null,
                $per_page,
                $max_products
            );
            set_transient($cache_key, $products, HOUR_IN_SECONDS);
        }
    }
    
    set_transient('api_products_current_list', $products, HOUR_IN_SECONDS);

    // Appliquer les filtres tableau
    $filter_sku = $_GET['filter_sku'] ?? '';
    $filter_name = $_GET['filter_name'] ?? '';
    $filter_brand = $_GET['filter_brand'] ?? '';
    $filter_category = $_GET['filter_category'] ?? '';

    if (!empty($filter_sku) || !empty($filter_name) || !empty($filter_brand) || !empty($filter_category)) {
        $products = array_filter($products, function($product) use ($filter_sku, $filter_name, $filter_brand, $filter_category) {
            // Filtre SKU
            if (!empty($filter_sku) && stripos($product['sku'], $filter_sku) === false) {
                return false;
            }
            
            // Filtre Nom
            if (!empty($filter_name) && stripos($product['name'], $filter_name) === false) {
                return false;
            }
            
            // Filtre Marque
            if (!empty($filter_brand) && stripos($product['brand'], $filter_brand) === false) {
                return false;
            }
            
            // Filtre Catégorie
            if (!empty($filter_category)) {
                $cat_found = false;
                if (!empty($product['product_data']['variants'][0]['categories'])) {
                    foreach ($product['product_data']['variants'][0]['categories'] as $cat_data) {
                        $cat_name = '';
                        if (isset($cat_data['categories']['fr'])) {
                            $cat_name = $cat_data['categories']['fr'];
                        } elseif (isset($cat_data['families']['fr'])) {
                            $cat_name = $cat_data['families']['fr'];
                        }
                        
                        if (stripos($cat_name, $filter_category) !== false) {
                            $cat_found = true;
                            break;
                        }
                    }
                }
                
                if (!$cat_found) {
                    return false;
                }
            }
            
            return true;
        });
        
        // Réindexer le tableau
        $products = array_values($products);
    }

    $items_per_page = intval($_GET['items_per_page'] ?? 10);
    $paged = intval($_GET['paged'] ?? 1);
    $total_items = count($products);
    $total_pages = ceil($total_items / $items_per_page);
    $offset = ($paged - 1) * $items_per_page;
    $products_page = array_slice($products, $offset, $items_per_page);

    function get_pagination_url($page_num, $params) {
        $params['paged'] = $page_num;
        return add_query_arg($params, admin_url('admin.php'));
    }

    $pagination_params = [
        'page' => 'api-products-list',
        'since_created' => $search_since_created,
        'since_updated' => $search_since_updated,
        'max_products' => $max_products,
        'items_per_page' => $items_per_page,
        'filter_sku' => $filter_sku,
        'filter_name' => $filter_name,
        'filter_brand' => $filter_brand,
        'filter_category' => $filter_category
    ];

    ?>
    <div class="wrap">
        <h1>📦 API Imbretex - Import Produits</h1>

        <!-- Section Synchronisation automatique -->
        <?php 
        $sync_stats = get_option('api_sync_stats', []);
        $new_products = get_transient('api_new_products');
        $has_new_products = !empty($new_products);
        $new_count = api_get_new_products_count();
        $updated_count = api_get_updated_products_count();
        $total_sync_count = $new_count + $updated_count;
        ?>
        <div style="background:#e7f7ff;padding:15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid #2271b1;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;color:#2271b1;">🔄 Synchronisation automatique</h3>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="background:<?php echo $total_sync_count > 0 ? '#dc3232' : '#7e8993'; ?>;color:white;padding:5px 12px;border-radius:50%;font-weight:bold;font-size:14px;">
                        <?php echo $total_sync_count; ?>
                    </span>
                    <?php if ($has_new_products): ?>
                        <button type="button" id="clear-sync" class="button button-small" style="font-size:11px;" title="Effacer les produits synchronisés">
                            ✕
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($sync_stats)): ?>
                <p style="margin:10px 0 0 0;">
                    <strong>Dernière synchronisation :</strong> <?php echo date('d/m/Y à H:i', strtotime($sync_stats['last_sync'])); ?><br>
                    <strong>Produits trouvés :</strong> 
                    <span style="color:#2271b1;font-weight:600;"><?php echo $sync_stats['total']; ?></span>
                    (<?php echo $sync_stats['new']; ?> nouveaux, <?php echo $sync_stats['updated']; ?> mis à jour)
                </p>
                <?php if ($has_new_products && $showing_sync): ?>
                    <p style="margin:5px 0 0 0;color:#2271b1;font-weight:600;">
                        ✓ Les produits synchronisés sont affichés ci-dessous
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p style="margin:10px 0 0 0;color:#666;">
                    Aucune synchronisation effectuée pour le moment. Le cron s'exécutera automatiquement chaque jour.
                </p>
            <?php endif; ?>
        </div>

        <!-- Loader de recherche -->
        <div id="api-loader-overlay" style="display:none;">
            <div class="api-loader-content">
                <div class="api-spinner"></div>
                <p>Chargement des produits en cours...</p>
            </div>
        </div>

        <!-- Modal d'import simplifié -->
        <div id="import-modal" style="display:none;">
            <div class="import-modal-content">
                <div class="import-loader">
                    <div class="import-spinner"></div>
                    <h2>📥 Importation en cours</h2>
                    <div id="current-product">Importation des produits...</div>
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

        <!-- FILTRES API -->
        <div style="background:#fff;padding:12px 15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin:0 0 8px 0;color:#2271b1;font-size:16px;">🔌 Filtres API</h3>
            <form method="get" action="" id="api-search-form">
                <input type="hidden" name="page" value="api-products-list">
                <input type="hidden" name="reload" value="1">
                <?php if (!empty($filter_sku)): ?><input type="hidden" name="filter_sku" value="<?php echo esc_attr($filter_sku); ?>"><?php endif; ?>
                <?php if (!empty($filter_name)): ?><input type="hidden" name="filter_name" value="<?php echo esc_attr($filter_name); ?>"><?php endif; ?>
                <?php if (!empty($filter_brand)): ?><input type="hidden" name="filter_brand" value="<?php echo esc_attr($filter_brand); ?>"><?php endif; ?>
                <?php if (!empty($filter_category)): ?><input type="hidden" name="filter_category" value="<?php echo esc_attr($filter_category); ?>"><?php endif; ?>
                
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label style="font-size:12px;"><strong>Créé depuis le</strong></label>
                        <input type="text" name="since_created" value="<?php echo esc_attr($search_since_created); ?>" placeholder="01-01-2024" style="height:32px;">
                    </div>
                    <div>
                        <label style="font-size:12px;"><strong>Modifié depuis le</strong></label>
                        <input type="text" name="since_updated" value="<?php echo esc_attr($search_since_updated); ?>" placeholder="01-01-2024" style="height:32px;">
                    </div>
                    <div>
                        <label style="font-size:12px;"><strong>Max produits</strong></label>
                        <input type="number" name="max_products" value="<?php echo $max_products; ?>" min="1" max="1000" style="width:80px;height:32px;">
                    </div>
                    <div>
                        <label style="font-size:12px;"><strong>Par page</strong></label>
                        <select name="items_per_page" style="height:32px;">
                            <option value="10" <?php selected($items_per_page, 10); ?>>10</option>
                            <option value="20" <?php selected($items_per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($items_per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($items_per_page, 100); ?>>100</option>
                        </select>
                    </div>
                    <button type="submit" class="button button-primary" style="height:32px;">🔍 Rechercher API</button>
                    <a href="<?php echo admin_url('admin.php?page=api-products-list&reload=1'); ?>" class="button button-secondary" style="height:32px;line-height:30px;">🔄 Actualiser</a>
                </div>
            </form>
        </div>

        <!-- FILTRES TABLEAU -->
        <div style="background:#fff;padding:12px 15px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin:0 0 8px 0;color:#2271b1;font-size:16px;">📋 Filtres tableau</h3>
            <form method="get" action="" id="table-filter-form">
                <input type="hidden" name="page" value="api-products-list">
                <?php if (!empty($search_since_created)): ?><input type="hidden" name="since_created" value="<?php echo esc_attr($search_since_created); ?>"><?php endif; ?>
                <?php if (!empty($search_since_updated)): ?><input type="hidden" name="since_updated" value="<?php echo esc_attr($search_since_updated); ?>"><?php endif; ?>
                <?php if (!empty($max_products)): ?><input type="hidden" name="max_products" value="<?php echo esc_attr($max_products); ?>"><?php endif; ?>
                <?php if (!empty($items_per_page)): ?><input type="hidden" name="items_per_page" value="<?php echo esc_attr($items_per_page); ?>"><?php endif; ?>
                
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label style="font-size:12px;"><strong>SKU</strong></label>
                        <input type="text" name="filter_sku" value="<?php echo esc_attr($filter_sku); ?>" placeholder="Rechercher..." style="height:32px;">
                    </div>
                    <div>
                        <label style="font-size:12px;"><strong>Nom</strong></label>
                        <input type="text" name="filter_name" value="<?php echo esc_attr($filter_name); ?>" placeholder="Rechercher..." style="height:32px;">
                    </div>
                    <div>
                        <label style="font-size:12px;"><strong>Marque</strong></label>
                        <input type="text" name="filter_brand" value="<?php echo esc_attr($filter_brand); ?>" placeholder="Rechercher..." style="height:32px;">
                    </div>
                    <div>
                        <label style="font-size:12px;"><strong>Catégorie</strong></label>
                        <input type="text" name="filter_category" value="<?php echo esc_attr($filter_category); ?>" placeholder="Rechercher..." style="height:32px;">
                    </div>
                    <button type="submit" class="button button-primary" style="height:32px;">🔍 Filtrer tableau</button>
                </div>
            </form>
        </div>

        <div style="background:#fff;padding:8px 15px;margin:8px 0;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;justify-content:space-between;align-items:center;">
            <p style="margin:0;">
                <strong>Total : <?php echo $total_items; ?> produits</strong> | Page <?php echo $paged; ?> sur <?php echo $total_pages; ?>
                <?php if ($showing_sync): ?>
                    <span style="background:#2271b1;color:white;padding:3px 10px;border-radius:3px;margin-left:10px;font-size:12px;">
                        🔄 Produits synchronisés
                    </span>
                <?php endif; ?>
            </p>
            <button type="button" id="start-import" class="button button-primary" style="font-weight:600;">
                <span id="import-btn-text">✅ Importer</span>
            </button>
        </div>

        <!-- Modal pour afficher les variantes JSON -->
        <div id="variants-modal" style="display:none;">
            <div class="variants-modal-content">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                    <h2 style="margin:0;">📦 Détails des variantes</h2>
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
                            <th style="width:150px;">Catégorie</th>
                            <th style="width:80px;">Variants</th>
                            <th style="width:130px;">Créé le</th>
                            <th style="width:130px;">Mis à jour le</th>
                            <th style="width:100px;">Statut WC</th>
                            <th style="width:50px;">Info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products_page)): ?>
                            <tr><td colspan="10" style="text-align:center;">Aucun produit trouvé.</td></tr>
                        <?php else: ?>
                            <?php foreach ($products_page as $index => $product):
                                $global_index = $offset + $index;
                                $wc_product_id = wc_get_product_id_by_sku($product['sku']);
                                $exists = $wc_product_id ? true : false;
                                
                                $cat_name = 'Autres';
                                if (!empty($product['product_data']['variants'][0]['categories'])) {
                                    $first_cat = $product['product_data']['variants'][0]['categories'][0];
                                    if (isset($first_cat['categories']['fr'])) {
                                        $cat_name = $first_cat['categories']['fr'];
                                    } elseif (isset($first_cat['families']['fr'])) {
                                        $cat_name = $first_cat['families']['fr'];
                                    }
                                }
                                
                                $variant_count = count($product['product_data']['variants'] ?? []);
                                $variants_json = json_encode($product['product_data']['variants'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            ?>
                            <tr>
                                <td><input type="checkbox" name="product_indices[]" value="<?php echo $global_index; ?>" class="product-checkbox" data-exists="<?php echo $exists ? '1' : '0'; ?>"></td>
                                <td><?php echo esc_html($product['sku']); ?></td>
                                <td><?php echo esc_html($product['name']); ?></td>
                                <td><?php echo esc_html($product['brand']); ?></td>
                                <td><?php echo esc_html($cat_name); ?></td>
                                <td style="text-align:center;">
                                    <span style="background:#0073aa;color:white;padding:2px 8px;border-radius:3px;font-size:11px;">
                                        <?php echo $variant_count; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($product['created_at'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($product['updated_at'])); ?></td>
                                <td><?php echo $exists ? '<span style="color:#46b450;">✓ Existe</span>' : '<span style="color:#999;">➕ Nouveau</span>'; ?></td>
                                <td style="text-align:center;">
                                    <button type="button" class="button button-small view-variants" data-variants='<?php echo esc_attr($variants_json); ?>' title="Voir les variantes">
                                        📋
                                    </button>
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
                        <span class="displaying-num"><?php echo $total_items; ?> éléments</span>
                        <span class="pagination-links">
                            <?php if ($paged > 1): ?>
                                <a class="first-page button" href="<?php echo esc_url(get_pagination_url(1, $pagination_params)); ?>">«</a>
                                <a class="prev-page button" href="<?php echo esc_url(get_pagination_url($paged - 1, $pagination_params)); ?>">‹</a>
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
                                <a class="next-page button" href="<?php echo esc_url(get_pagination_url($paged + 1, $pagination_params)); ?>">›</a>
                                <a class="last-page button" href="<?php echo esc_url(get_pagination_url($total_pages, $pagination_params)); ?>">»</a>
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
        // Bouton Effacer les produits synchronisés
        $('#clear-sync').on('click', function() {
            if (!confirm('Effacer les produits synchronisés de la liste ?')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'api_mark_products_seen'
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    }
                }
            });
        });

        // Fonction pour mettre à jour le label du bouton d'import
        function updateImportButton() {
            var existCount = 0;
            var newCount = 0;
            
            $('.product-checkbox:checked').each(function() {
                if ($(this).data('exists') == '1') {
                    existCount++;
                } else {
                    newCount++;
                }
            });
            
            var totalChecked = existCount + newCount;
            
            if (totalChecked === 0) {
                $('#import-btn-text').html('✅ Importer');
            } else if (newCount > 0 && existCount > 0) {
                $('#import-btn-text').html('✅ Ajouter et mettre à jour (' + totalChecked + ')');
            } else if (existCount > 0 && newCount === 0) {
                $('#import-btn-text').html('🔄 Mettre à jour (' + totalChecked + ')');
            } else {
                $('#import-btn-text').html('➕ Ajouter (' + totalChecked + ')');
            }
        }

        // Gestion checkboxes
        $('#select-all').on('change', function() {
            $('.product-checkbox').prop('checked', $(this).prop('checked'));
            updateImportButton();
        });
        
        $('.product-checkbox').on('change', function() {
            if (!$(this).prop('checked')) {
                $('#select-all').prop('checked', false);
            }
            updateImportButton();
        });

        // Afficher le modal des variantes
        $('.view-variants').on('click', function() {
            var variants = $(this).data('variants');
            // Les variants sont déjà au format JSON string formaté, on les affiche directement
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

        // Fermer le modal en cliquant en dehors
        $('#variants-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).fadeOut(200);
            }
        });

        // Loader UNIQUEMENT pour la recherche API
        $('#api-search-form').on('submit', function() {
            $('#api-loader-overlay').fadeIn(200);
        });

        // Loader aussi pour le bouton Actualiser (qui recharge l'API)
        $('a[href*="reload=1"]').on('click', function() {
            $('#api-loader-overlay').fadeIn(200);
        });

        // Pas de loader pour le filtre tableau
        $('#table-filter-form').on('submit', function() {
            // Aucun loader - le filtre tableau est rapide
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

        // IMPORT AJAX
        $('#start-import').on('click', function() {
            var selectedIndices = [];
            $('.product-checkbox:checked').each(function() {
                selectedIndices.push(parseInt($(this).val()));
            });

            if (selectedIndices.length === 0) {
                alert('Veuillez sélectionner au moins un produit');
                return;
            }

            // Afficher modal directement
            $('#import-modal').fadeIn(200);
            $('.import-loader').show();
            $('#import-summary').hide();

            var imported = 0;
            var errors = 0;
            var errorMessages = [];
            var totalProducts = selectedIndices.length;

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

                var productIndex = selectedIndices[index];
                var currentNum = index + 1;
                
                $('#current-product').text('Import produit ' + currentNum + ' sur ' + totalProducts + '...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'api_import_single_product',
                        nonce: '<?php echo wp_create_nonce('api_import_nonce'); ?>',
                        index: productIndex
                    },
                    success: function(response) {
                        if (response.success) {
                            imported++;
                        } else {
                            errors++;
                            errorMessages.push(response.data.message || 'Erreur inconnue');
                        }
                    },
                    error: function() {
                        errors++;
                        errorMessages.push('Erreur réseau');
                    },
                    complete: function() {
                        // Importer le suivant
                        importNext(index + 1);
                    }
                });
            }

            // Démarrer l'import
            importNext(0);
        });

        $('#close-modal').on('click', function() {
            $('#import-modal').fadeOut(200);
            location.reload();
        });
    });
    </script>

    <style>
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

    /* Loader recherche */
    #api-loader-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .api-loader-content {
        background: white;
        padding: 40px;
        border-radius: 8px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    }

    .api-spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid #2271b1;
        border-radius: 50%;
        width: 50px;
        height: 50px;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .api-loader-content p {
        margin: 0;
        font-size: 16px;
        font-weight: 500;
        color: #333;
    }

    /* Modal import simplifié */
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

    .import-loader {
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

    #import-summary {
        text-align: center;
        margin: 20px 0;
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

    .summary-success p {
        margin: 0;
        color: #2d5e2d;
        font-size: 15px;
    }

    .summary-errors {
        padding: 20px;
        background: #fff0f0;
        border-left: 4px solid #dc3232;
        border-radius: 8px;
        text-align: left;
    }

    .summary-errors strong {
        color: #dc3232;
        font-size: 16px;
        display: block;
        margin-bottom: 10px;
    }

    #error-messages {
        max-height: 150px;
        overflow-y: auto;
        margin: 10px 0 0 0;
        padding-left: 20px;
    }

    #error-messages li {
        color: #dc3232;
        margin: 5px 0;
        font-size: 14px;
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

    .variants-modal-content h2 {
        color: #2271b1;
        margin-bottom: 15px;
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