<?php
/**
 * Plugin Name: API Imbretex
 * Description: Import automatique des produits Imbretex avec synchronisation et table personnalisée
 * Version: 7.8
 * Author: Raphael
 */
if (!defined('ABSPATH')) exit;

define('API_BASE_URL', 'https://api.imbretex.fr');
define('API_TOKEN', 'G%E]Bi0!o;PWb5^2aDDZcYt|u+#7s@[n$Ez6j].2St^E4ZJO.?O#.Nyf@d0*');

// ============================================================
// INCLUSION DES MODULES
// ============================================================

// Module 1 : common (CODE V6.3)
require_once plugin_dir_path(__FILE__) . 'includes/common.php';

// Module 2 : Synchronisation (CODE V7.8)
require_once plugin_dir_path(__FILE__) . 'includes/synchronisation.php';

// Module 3 : Import vers WC (CODE V7.4)
require_once plugin_dir_path(__FILE__) . 'includes/import-to-wc.php';

// Module 4 : Prix et Marges (CODE V1.0) ⭐ NOUVEAU
require_once plugin_dir_path(__FILE__) . 'includes/prix-marge.php';

// ============================================================
// MENU PRINCIPAL AVEC SOUS-MENUS
// ============================================================
add_action('admin_menu', function() {
    // Compter les produits non importés de la table DB
    $db_count = function_exists('api_db_count_products') ? api_db_count_products(['imported' => 0]) : 0;
    
    // Badge avec le nombre de produits à importer
    $menu_title = 'API Imbretex';
    if ($db_count > 0) {
        $menu_title .= ' <span class="update-plugins count-' . $db_count . '"><span class="update-count">' . $db_count . '</span></span>';
    }
    
    // Menu principal
    add_menu_page(
        'API Imbretex',
        $menu_title,
        'manage_options',
        'api-imbretex',
        'api_sync_page',
        'dashicons-update'
    );
    
    // Sous-menu 1 : Synchronisation
    add_submenu_page(
        'api-imbretex',
        'Synchronisation',
        '🔄 Synchronisation',
        'manage_options',
        'api-imbretex',
        'api_sync_page'
    );
    
    // Sous-menu 2 : Import vers WC
    add_submenu_page(
        'api-imbretex',
        'Importation vers WooCommerce',
        '➡️ Import vers WC',
        'manage_options',
        'api-import-to-wc',
        'api_import_to_wc_page'
    );
    
    // Sous-menu 3 : Prix et Marges ⭐ NOUVEAU
    add_submenu_page(
        'api-imbretex',
        'Prix et Marges',
        '💰 Prix (Marge)',
        'manage_options',
        'api-prix-marge',
        'api_prix_marge_page'
    );
}, 10);

// ============================================================
// ACTIVATION / DÉSACTIVATION
// ============================================================
register_activation_hook(__FILE__, 'api_plugin_activation');
function api_plugin_activation() {
    // Créer la table pour la synchronisation
    if (function_exists('api_create_sync_table')) {
        api_create_sync_table();
    }
}

register_deactivation_hook(__FILE__, 'api_plugin_deactivation');
function api_plugin_deactivation() {
    // Fonction de désactivation (vide pour l'instant)
}
