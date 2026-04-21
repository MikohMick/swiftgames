<?php
/**
 * Plugin Name: Wupex Gift Cards
 * Plugin URI:  https://swiftovertimeenterprise.com
 * Description: Connects WooCommerce to the Wupex API for automated gift card (PSN, etc.) delivery.
 * Version:     1.1.0
 * Author:      Swift Overtime Enterprise
 * Text Domain: wupex-gift-cards
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WUPEX_VERSION', '1.1.0' );
define( 'WUPEX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WUPEX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WUPEX_PLUGIN_FILE', __FILE__ );

// Activation hook
register_activation_hook( __FILE__, 'wupex_activate' );
function wupex_activate(): void {
    require_once WUPEX_PLUGIN_DIR . 'includes/class-wupex-db.php';
    Wupex_DB::create_tables();
}

// Load plugin on plugins_loaded (also handles DB updates)
add_action( 'plugins_loaded', 'wupex_init' );
function wupex_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p>' . esc_html__( 'Wupex Gift Cards requires WooCommerce to be active.', 'wupex-gift-cards' ) . '</p></div>';
        } );
        return;
    }

    // Ensure DB tables exist (handles plugin updates)
    require_once WUPEX_PLUGIN_DIR . 'includes/class-wupex-db.php';
    Wupex_DB::create_tables();

    // Core classes
    require_once WUPEX_PLUGIN_DIR . 'includes/class-wupex-api.php';
    require_once WUPEX_PLUGIN_DIR . 'includes/class-wupex-crypto.php';
    require_once WUPEX_PLUGIN_DIR . 'includes/class-wupex-order.php';
    require_once WUPEX_PLUGIN_DIR . 'includes/class-wupex-email.php';
    require_once WUPEX_PLUGIN_DIR . 'includes/class-wupex-reveal.php';

    // Admin classes
    if ( is_admin() ) {
        require_once WUPEX_PLUGIN_DIR . 'admin/class-wupex-settings.php';
        require_once WUPEX_PLUGIN_DIR . 'admin/class-wupex-import.php';
        require_once WUPEX_PLUGIN_DIR . 'admin/class-wupex-order-meta.php';
        new Wupex_Settings();
        new Wupex_Import();
        new Wupex_Order_Meta();
    }

    // Initialise hooks
    new Wupex_Order();
    new Wupex_Reveal();

    // Register WP-Cron events
    add_action( 'wupex_daily_stock_sync', [ 'Wupex_Import', 'run_stock_sync' ] );
    if ( ! wp_next_scheduled( 'wupex_daily_stock_sync' ) ) {
        wp_schedule_event( strtotime( 'midnight' ), 'daily', 'wupex_daily_stock_sync' );
    }

    // Enqueue admin assets
    add_action( 'admin_enqueue_scripts', 'wupex_enqueue_admin_assets' );
}

function wupex_enqueue_admin_assets( string $hook ): void {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'wupex' ) === false ) {
        return;
    }
    wp_enqueue_style( 'wupex-admin', WUPEX_PLUGIN_URL . 'assets/css/wupex-admin.css', [], WUPEX_VERSION );
    wp_enqueue_script( 'wupex-admin', WUPEX_PLUGIN_URL . 'assets/js/wupex-admin.js', [ 'jquery' ], WUPEX_VERSION, true );
    wp_localize_script( 'wupex-admin', 'wupexAdmin', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'wupex_admin_nonce' ),
    ] );
}
