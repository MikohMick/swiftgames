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

        status_header( 200 );
        $content = $this->render_reveal( $token );
        $this->render_standalone_page( $content );
        exit;
    }

    private function render_standalone_page( string $content ): void {
        $css_url   = WUPEX_PLUGIN_URL . 'assets/css/wupex-reveal.css?v=' . WUPEX_VERSION;
        $home_url  = home_url( '/' );
        $site_name = get_bloginfo( 'name' );
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html__( 'Your Gift Card Code', 'wupex-gift-cards' ) . ' — ' . esc_html( $site_name ); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d0d0d;
            color: #111827;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .wupex-standalone-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 48px 16px 24px;
        }
        .wupex-standalone-footer {
            text-align: center;
            padding: 20px 16px 48px;
        }
        .wupex-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,.65);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 8px;
            padding: 12px 28px;
            font-size: 15px;
            font-weight: 500;
            text-decoration: none;
            transition: color .18s, border-color .18s, background .18s;
        }
        .wupex-back-btn:hover {
            color: #fff;
            border-color: rgba(255,255,255,.45);
            background: rgba(255,255,255,.05);
        }
    </style>
</head>
<body>
    <div class="wupex-standalone-wrap">
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput ?>
    </div>
    <div class="wupex-standalone-footer">
        <a href="<?php echo esc_url( $home_url ); ?>" class="wupex-back-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
            <?php esc_html_e( 'Back to Home', 'wupex-gift-cards' ); ?>
        </a>
    </div>
</body>
</html>
        <?php
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
