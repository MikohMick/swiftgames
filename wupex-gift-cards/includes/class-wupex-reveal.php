<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Reveal {

    public function __construct() {
        add_shortcode( 'wupex_reveal_code', [ $this, 'render_shortcode' ] );
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_endpoint' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    public function enqueue_styles(): void {
        // Always register on frontend — used by the shortcode path
        wp_enqueue_style(
            'wupex-reveal',
            WUPEX_PLUGIN_URL . 'assets/css/wupex-reveal.css',
            [],
            WUPEX_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Endpoint registration
    // -------------------------------------------------------------------------

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'reveal-code', EP_ROOT );
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = 'token';
        return $vars;
    }

    public function handle_endpoint(): void {
        if ( ! get_query_var( 'reveal-code', false ) && ! isset( $_GET['token'] ) ) {
            return;
        }

        $token = sanitize_text_field( $_GET['token'] ?? get_query_var( 'token', '' ) );
        if ( empty( $token ) ) {
            return;
        }

        // Load inside the full theme (calls wp_head → wp_enqueue_scripts → our CSS).
        status_header( 200 );
        get_header();
        echo $this->render_reveal( $token ); // phpcs:ignore WordPress.Security.EscapeOutput
        get_footer();
        exit;
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public function render_shortcode( array $atts ): string {
        $token = sanitize_text_field( $_GET['token'] ?? '' );
        if ( empty( $token ) ) {
            return '<p>' . esc_html__( 'No reveal token provided.', 'wupex-gift-cards' ) . '</p>';
        }
        return $this->render_reveal( $token );
    }

    // -------------------------------------------------------------------------
    // Core reveal logic
    // -------------------------------------------------------------------------

    private function render_reveal( string $token ): string {
        $code = Wupex_DB::get_code_by_token( $token );

        // Token does not exist
        if ( ! $code ) {
            return $this->error_message( __( 'Invalid reveal link. Please check your email or contact support.', 'wupex-gift-cards' ) );
        }

        // Refunded
        if ( $code['status'] === 'refunded' ) {
            return $this->error_message( __( 'This code has been refunded and is no longer available.', 'wupex-gift-cards' ) );
        }

        $order = wc_get_order( (int) $code['wc_order_id'] );
        if ( ! $order ) {
            return $this->error_message( __( 'Invalid reveal link. Please check your email or contact support.', 'wupex-gift-cards' ) );
        }

        // If a customer is logged in, verify the order belongs to them
        if ( is_user_logged_in() && $order->get_customer_id() > 0 && (int) $order->get_customer_id() !== (int) get_current_user_id() ) {
            return $this->error_message( __( 'This reveal link does not belong to your account.', 'wupex-gift-cards' ) );
        }

        // Decrypt the code
        try {
            $pin = Wupex_Crypto::decrypt( $code['serial_code_encrypted'] );
        } catch ( Exception $e ) {
            Wupex_API::log( 'REVEAL_ERROR', $e->getMessage(), (int) $code['wc_order_id'] );
            return $this->error_message( __( 'Unable to decrypt your code. Please contact support.', 'wupex-gift-cards' ) );
        }

        // Record the reveal
        Wupex_DB::mark_revealed( (int) $code['id'] );
        Wupex_API::log( 'REVEALED', "token={$token} code_id={$code['id']}", (int) $code['wc_order_id'] );

        // Render the reveal page
        ob_start();
        include WUPEX_PLUGIN_DIR . 'templates/reveal-page.php';
        return ob_get_clean();
    }

    private function error_message( string $message ): string {
        $support_url = get_option( 'wupex_support_url', get_option( 'admin_email' ) );
        return '<div class="wupex-reveal-error">'
            . '<p>' . esc_html( $message ) . '</p>'
            . '<p><a href="' . esc_url( home_url( '/contact/' ) ) . '">' . esc_html__( 'Contact Support', 'wupex-gift-cards' ) . '</a></p>'
            . '</div>';
    }
}
