<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_marpico_sync_products', [ $this, 'ajax_sync_products' ] );
        add_action( 'wp_ajax_marpico_sync_products_batch', [ $this, 'ajax_sync_products_batch' ] );
        add_action( 'wp_ajax_marpico_sync_product_individual', [ $this, 'ajax_sync_product_individual' ] );
        /* add_action( 'wp_ajax_marpico_get_sync_stats', [ $this, 'ajax_get_sync_stats' ] ); */
    }

    public function add_menu() {
        add_menu_page(
            'Marpico Sync',
            'Marpico Sync',
            'manage_options',
            'marpico-sync',
            [ $this, 'settings_page' ],
            //'dashicons-update',
            plugin_dir_url(dirname(__FILE__)) . 'assets/icon-128x128.png',
            56
        );
    }

    public function register_settings() {
        // El primer parámetro debe coincidir con settings_fields() en la vista
        register_setting( 'marpico_sync_settings', 'marpico_api_endpoint', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ] );
        register_setting( 'marpico_sync_settings', 'marpico_api_token', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ] );
        /* register_setting( 'marpico_sync_settings', 'marpico_api_product_code', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ] ); */
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_marpico-sync' ) return;
        wp_enqueue_style( 'marpico-admin', MARPICO_WOO_SYNC_URL . 'assets/css/admin-styles.css' );
        wp_enqueue_script( 'marpico-admin', MARPICO_WOO_SYNC_URL . 'assets/js/admin.js', [ 'jquery' ], false, true );
        wp_localize_script( 'marpico-admin', 'marpico_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'marpico_sync_nonce' ),
        ] );
    }

    public function settings_page() {
        include_once( MARPICO_WOO_SYNC_PATH . 'admin-interface.html' );
    }

    /*** AJAX: sincronizar producto con el código guardado en opciones */
    public function ajax_sync_products() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $sync = new Marpico_Sync();
        $res = $sync->sync_all_products(5);

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        }

        wp_send_json_success( 'Producto sincronizado correctamente. Post ID: ' . $res );
    }

    public function ajax_sync_products_batch() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $offset = intval( $_POST['offset'] ?? 0 );
        $batch_size = intval( $_POST['batch_size'] ?? 10 );

        $sync = new Marpico_Sync();
        $res = $sync->sync_all_products( $offset, $batch_size );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        }

        //$this->update_sync_stats( $res );

        wp_send_json_success( $res );
    }

    public function ajax_sync_product_individual() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $product_code = sanitize_text_field( $_POST['product_code'] ?? '' );
        
        if ( empty( $product_code ) ) {
            wp_send_json_error( 'Código de producto requerido' );
        }

        $sync = new Marpico_Sync();
        $res = $sync->sync_product_by_family( $product_code );

        if ( is_wp_error( $res ) ) {
            $this->log_sync_event( "Error sincronizando producto {$product_code}: " . $res->get_error_message(), 'error' );
            wp_send_json_error( $res->get_error_message() );
        }

        $this->log_sync_event( "Producto {$product_code} sincronizado exitosamente (ID: {$res})", 'success' );
        //$this->update_individual_sync_stats();

        wp_send_json_success( "Producto sincronizado correctamente. Post ID: {$res}" );
    }

/*     public function ajax_get_sync_stats() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'No permission' );
        check_ajax_referer( 'marpico_sync_nonce', 'security' );

        $stats = [
            'total_products' => $this->get_total_synced_products(),
            'last_sync' => get_option( 'marpico_last_sync_time', 'Nunca' ),
            'sync_errors' => get_option( 'marpico_sync_errors_count', 0 ),
            'api_status' => $this->check_api_status()
        ];

        wp_send_json_success( $stats );
    } */

/*     private function get_total_synced_products() {
        $query = new WP_Query([
            'post_type' => 'product',
            'meta_query' => [
                [
                    'key' => '_external_family',
                    'compare' => 'EXISTS'
                ]
            ],
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        return $query->found_posts;
    } */

/*     private function check_api_status() {
        $client = new Marpico_Client();
        $test_result = $client->test_connection();
        
        if ( is_wp_error( $test_result ) ) {
            return 'Error';
        }
        
        return 'Activo';
    } */

    /* private function update_sync_stats( $sync_result ) {
        if ( isset( $sync_result['processed'] ) && $sync_result['processed'] > 0 ) {
            update_option( 'marpico_last_sync_time', current_time( 'Y-m-d H:i:s' ) );
        }

        if ( isset( $sync_result['errors'] ) && $sync_result['errors'] > 0 ) {
            $current_errors = get_option( 'marpico_sync_errors_count', 0 );
            update_option( 'marpico_sync_errors_count', $current_errors + $sync_result['errors'] );
        }
    } */

    /* private function update_individual_sync_stats() {
        update_option( 'marpico_last_sync_time', current_time( 'Y-m-d H:i:s' ) );
    } */

    private function log_sync_event( $message, $type = 'info' ) {
        $log = get_option( 'marpico_sync_log', [] );
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $log_entry = "[{$timestamp}] {$message}";
        
        array_unshift( $log, $log_entry );
        
        // Mantener solo los últimos 100 registros
        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, 0, 100 );
        }
        
        update_option( 'marpico_sync_log', $log );
    }
}
