<?php
/**
 * Plugin Name: Marpico Woo Sync
 * Description: Sincroniza productos y categorías desde la API de Marpico hacia WooCommerce.
 * Version: 1.0.2
 * Author: David Perez
 * Author URI:  https://impactosdigitales.co
 * Plugin URI:  https://github.com/davidpezcas/marpico-woo-sync
 * Text Domain: marpico-woo-sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Definir constante de versión
if ( ! defined( 'MARPICO_SYNC_VERSION' ) ) {
    define( 'MARPICO_SYNC_VERSION', '1.0.2' );
}

define( 'MARPICO_WOO_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'MARPICO_WOO_SYNC_URL',  plugin_dir_url( __FILE__ ) );

// includes
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-marpico-client.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-marpico-sync.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-marpico-admin.php';
require_once MARPICO_WOO_SYNC_PATH . 'includes/class-attribute-helper.php';

if ( is_admin() ) {
    require_once MARPICO_WOO_SYNC_PATH . 'includes/github-updater.php';

    // Conectar el plugin a GitHub
    $repo_url = 'https://github.com/davidpezcas/marpico-woo-sync';
    new GitHub_Updater(__FILE__, $repo_url);
}

// init admin
add_action( 'plugins_loaded', function() {
    new Marpico_Admin();
});
