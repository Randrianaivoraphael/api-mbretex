<?php
/**
 * Module : Fonctions Communes (v7.2)
 * 
 * Ce module contient les fonctions de base utilisées par les autres modules :
 * - Récupération prix/stock depuis l'API
 * - Création/mise à jour de produits WooCommerce
 * - Gestion des images
 * - Gestion des attributs
 * 
 * AUCUNE interface utilisateur
 * Utilisé par : synchronisation.php et import-to-wc.php
 */

if (!defined('ABSPATH')) exit;

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