<?php
/**
 * Fonctions de gestion des prix et stocks
 * Version optimisée avec requêtes batch
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('api_round_price_to_5cents')) {
    function api_round_price_to_5cents($price) {
        if ($price <= 0) return 0;
        $rounded = ceil($price / 0.05) * 0.05;
        return round($rounded, 2);
    }
}

/**
 * 🔥 NOUVELLE FONCTION : Récupération batch des prix/stocks
 * Accepte un array de références et retourne tous les prix en une seule requête
 * 
 * @param array $references Array de références (SKU) à récupérer
 * @return array|null Array associatif [reference => data] ou null en cas d'erreur
 */
/* function api_get_products_price_stock_batch($references) {
    if (empty($references) || !is_array($references)) {
        return null;
    }
    
    // Nettoyer et dédupliquer les références
    $references = array_unique(array_filter($references));
    
    if (empty($references)) {
        return null;
    }
    
    $api_url = API_BASE_URL . '/api/products/price-stock';
    
    // ✅ Construire les paramètres avec format array: products[]=REF1&products[]=REF2
    $params = [];
    foreach ($references as $ref) {
        $params[] = 'products[]=' . urlencode($ref);
    }
    $query_string = implode('&', $params);
    
    $full_url = $api_url . '?' . $query_string;
    
    $response = wp_remote_get($full_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . API_TOKEN,
            'Accept' => 'application/json'
        ],
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        error_log('API Imbretex - Erreur batch price-stock : ' . $response->get_error_message());
        return null;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        error_log('API Imbretex - HTTP ' . $http_code . ' pour batch price-stock');
        return null;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['products'])) {
        error_log('API Imbretex - JSON invalide dans batch price-stock');
        return null;
    }
    
    return $data['products'];
} */

/**
 * Récupération du prix/stock d'un seul produit
 * Utilise maintenant le batch en interne pour cohérence
 * 
 * @param string $reference SKU du produit
 * @return array|null Data du produit ou null
 */
/* function api_get_product_price_stock($reference) {
    if (empty($reference)) {
        return null;
    }
    
    // Utiliser le batch même pour une seule référence (simplicité)
    $batch_result = api_get_products_price_stock_batch([$reference]);
    
    if ($batch_result && isset($batch_result[$reference])) {
        return $batch_result[$reference];
    }
    
    return null;
} */

/**
 * Télécharger et attacher une image à WordPress
 */
function api_download_and_attach_image($image_url, $product_id = 0) {
    global $wpdb;
    if (!$image_url) return null;
    
    $image_url = esc_url_raw($image_url);
    $image_name = basename(parse_url($image_url, PHP_URL_PATH));
    
    // Vérifier si l'image existe déjà
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE guid=%s",
        $image_url
    ));
    
    if ($existing) {
        return intval($existing);
    }
    
    // Télécharger l'image
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        error_log('API Imbretex - Échec téléchargement image : ' . $tmp->get_error_message());
        return null;
    }
    
    $file_array = [
        'name' => $image_name,
        'tmp_name' => $tmp
    ];
    
    // Importer dans WordPress
    $id = media_handle_sideload($file_array, $product_id);
    
    if (is_wp_error($id)) {
        @unlink($tmp);
        error_log('API Imbretex - Échec sideload image : ' . $id->get_error_message());
        return null;
    }
    
    return $id;
}

/**
 * Créer les attributs globaux WooCommerce
 */
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

/**
 * Créer un terme d'attribut
 */
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