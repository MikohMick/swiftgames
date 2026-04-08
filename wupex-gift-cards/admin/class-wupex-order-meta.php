<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Order_Meta {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'wp_ajax_wupex_resend_email', [ $this, 'ajax_resend_email' ] );
    }

    public function add_meta_box(): void {
        add_meta_box(
            'wupex-order-details',
            __( 'Wupex Gift Card Details', 'wupex-gift-cards' ),
            [ $this, 'render_meta_box' ],
            'woocommerce_page_wc-orders', // HPOS
            'normal',
            'default'
        );

        // Legacy CPT-based orders
        add_meta_box(
            'wupex-order-details',
            __( 'Wupex Gift Card Details', 'wupex-gift-cards' ),
            [ $this, 'render_meta_box' ],
            'shop_order',
            'normal',
            'default'
        );
    }

    public function render_meta_box( $post_or_order ): void {
        $order_id = is_a( $post_or_order, 'WC_Order' )
            ? $post_or_order->get_id()
            : (int) $post_or_order->ID;

        $orders = Wupex_DB::get_orders_by_wc_order( $order_id );
        $codes  = Wupex_DB::get_codes_by_wc_order( $order_id );

        if ( empty( $orders ) && empty( $codes ) ) {
            echo '<p>' . esc_html__( 'No Wupex data for this order.', 'wupex-gift-cards' ) . '</p>';
            return;
        }

        echo '<div class="wupex-order-meta">';

        // Wupex order summaries
        foreach ( $orders as $wo ) {
            echo '<p><strong>' . esc_html__( 'Wupex Order Name:', 'wupex-gift-cards' ) . '</strong> ' . esc_html( $wo['wupex_order_name'] ) . '</p>';
        }

        if ( ! empty( $codes ) ) {
            echo '<table class="wp-list-table widefat striped" style="margin-top:10px;">';
            echo '<thead><tr>'
                . '<th>' . esc_html__( 'SKU', 'wupex-gift-cards' ) . '</th>'
                . '<th>' . esc_html__( 'Serial Number', 'wupex-gift-cards' ) . '</th>'
                . '<th>' . esc_html__( 'Status', 'wupex-gift-cards' ) . '</th>'
                . '<th>' . esc_html__( 'Revealed At', 'wupex-gift-cards' ) . '</th>'
                . '<th>' . esc_html__( 'Reveal Count', 'wupex-gift-cards' ) . '</th>'
                . '<th>' . esc_html__( 'Actions', 'wupex-gift-cards' ) . '</th>'
                . '</tr></thead><tbody>';

            foreach ( $codes as $code ) {
                $reveal_url = add_query_arg( 'token', $code['reveal_token'], home_url( '/reveal-code/' ) );

                echo '<tr>'
                    . '<td>' . esc_html( $code['sku'] ) . '</td>'
                    . '<td>' . esc_html( $code['serial_number'] ) . '</td>'
                    . '<td>' . esc_html( $code['status'] ) . '</td>'
                    . '<td>' . esc_html( $code['revealed_at'] ?? '—' ) . '</td>'
                    . '<td>' . esc_html( $code['reveal_count'] ) . '</td>'
                    . '<td><a href="' . esc_url( $reveal_url ) . '" target="_blank">' . esc_html__( 'View Reveal Page', 'wupex-gift-cards' ) . '</a></td>'
                    . '</tr>';
            }

            echo '</tbody></table>';
        }

        // Re-send email button
        echo '<p style="margin-top:12px;">';
        echo '<button type="button" class="button button-secondary wupex-resend-email" data-order-id="' . esc_attr( $order_id ) . '">'
            . esc_html__( 'Re-send Code Email', 'wupex-gift-cards' )
            . '</button>';
        echo ' <span class="wupex-resend-result"></span>';
        echo '</p>';

        echo '</div>';

        // Inline script for the re-send button (scoped to this meta box)
        ?>
        <script>
        (function($){
            $(document).on('click', '.wupex-resend-email', function(){
                var btn = $(this);
                var orderId = btn.data('order-id');
                var result  = btn.siblings('.wupex-resend-result');
                btn.prop('disabled', true);
                result.text('<?php echo esc_js( __( 'Sending…', 'wupex-gift-cards' ) ); ?>');
                $.post(wupexAdmin.ajax_url, {
                    action: 'wupex_resend_email',
                    nonce: wupexAdmin.nonce,
                    order_id: orderId
                }, function(resp){
                    btn.prop('disabled', false);
                    result.text(resp.success ? resp.data.message : resp.data.message);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: Re-send email
    // -------------------------------------------------------------------------

    public function ajax_resend_email(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'wupex-gift-cards' ) ] );
        }

        $email = new Wupex_Email();
        $email->send_code_ready( $order );

        Wupex_API::log( 'RESEND_EMAIL', 'Admin re-sent code email', $order_id );
        wp_send_json_success( [ 'message' => __( 'Email re-sent successfully.', 'wupex-gift-cards' ) ] );
    }
}
