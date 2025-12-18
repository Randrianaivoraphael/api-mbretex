<?php

if (!defined('ABSPATH')) exit;

if (!function_exists('api_round_price_to_5cents')) {
    function api_round_price_to_5cents($price) {
        if ($price <= 0) return 0;
        $rounded = ceil($price / 0.05) * 0.05;
        return round($rounded, 2);
    }
}

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
        error_log('API Imbretex v10.2 - Échec téléchargement image : ' . $tmp->get_error_message());
        return null;
    }

    $file_array = [
        'name' => $image_name,
        'tmp_name' => $tmp
    ];

    $id = media_handle_sideload($file_array, $product_id);
    
    if (is_wp_error($id)) {
        @unlink($tmp);
        error_log('API Imbretex v10.2 - Échec sideload image : ' . $id->get_error_message());
        return null;
    }

    return $id;
}

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
