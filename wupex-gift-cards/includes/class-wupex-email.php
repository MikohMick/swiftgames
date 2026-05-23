<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Email {

    /**
     * Send the "Code Ready" email to the customer.
     */
    public function send_code_ready( WC_Order $order ): void {
        $to      = $order->get_billing_email();
        $subject = sprintf(
            __( 'Your PSN Gift Card is Ready — Order #%s', 'wupex-gift-cards' ),
            $order->get_order_number()
        );

        $content = $this->get_email_html( $order );

        $from_name  = get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];

        // Force sender via PHPMailer at priority 99 to override any host-level SMTP defaults (e.g. EasyWP)
        $force_sender = static function ( $phpmailer ) use ( $from_name, $from_email ) {
            $phpmailer->FromName = $from_name;
            $phpmailer->From     = $from_email;
        };
        add_action( 'phpmailer_init', $force_sender, 99 );
        $sent = wp_mail( $to, $subject, $content, $headers );
        remove_action( 'phpmailer_init', $force_sender, 99 );

        Wupex_API::log(
            'EMAIL_SENT',
            $sent ? "Code ready email sent to {$to}" : "Failed to send email to {$to}",
            $order->get_id()
        );
    }

    private function get_email_html( WC_Order $order ): string {
        $codes      = Wupex_DB::get_codes_by_wc_order( $order->get_id() );
        $first_code = $codes[0] ?? null;

        // Build reveal URL — supports both endpoint and page shortcode
        $reveal_url = '';
        if ( $first_code ) {
            $base = get_option( 'wupex_reveal_page_url', home_url( '/reveal-code/' ) );
            $reveal_url = add_query_arg( 'token', $first_code['reveal_token'], $base );
        }

        ob_start();
        include WUPEX_PLUGIN_DIR . 'templates/email-code-ready.php';
        return ob_get_clean();
    }

    /**
     * Hook: thank-you page notice.
     */
    public static function thankyou_notice( int $order_id ): void {
        echo '<div class="woocommerce-message woocommerce-message--info">'
            . esc_html__( 'Thank you for your order! Your gift card code is being prepared. You will receive an email shortly with instructions to reveal your code.', 'wupex-gift-cards' )
            . '</div>';
    }
}

// Hook the thank-you page notice
add_action( 'woocommerce_thankyou', [ 'Wupex_Email', 'thankyou_notice' ], 5 );
