<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Order {

    public function __construct() {
        add_action( 'woocommerce_order_status_completed', [ $this, 'handle_order_completed' ], 10, 1 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'handle_refund' ], 10, 1 );
        add_action( 'woocommerce_order_refunded', [ $this, 'handle_refund' ], 10, 1 );
    }

    // -------------------------------------------------------------------------
    // Order completed
    // -------------------------------------------------------------------------

    public function handle_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only act when payment is confirmed
        if ( ! $order->is_paid() ) {
            return;
        }

        $has_wupex_items = false;

        foreach ( $order->get_items() as $item_id => $item ) {
            $product_id = $item->get_product_id();
            $sku        = get_post_meta( $product_id, '_wupex_sku', true );

            if ( empty( $sku ) ) {
                continue; // Not a Wupex product — skip silently
            }

            $has_wupex_items = true;
            $quantity        = (int) $item->get_quantity();
            $reference_id    = 'WC-' . $order_id . '-' . $item_id . '-' . time();

            $this->process_wupex_item( $order, $item, $product_id, $sku, $quantity, $reference_id );
        }

        if ( $has_wupex_items ) {
            // Notify customer codes are being prepared (thank-you page already shown)
        }
    }

    private function process_wupex_item(
        WC_Order $order,
        WC_Order_Item $item,
        int $product_id,
        string $sku,
        int $quantity,
        string $reference_id
    ): void {
        $order_id = $order->get_id();
        $api      = new Wupex_API();

        // Step 1: Pull codes
        $pull = $api->pull_codes( $sku, $quantity, $reference_id );

        if ( ! $pull['success'] ) {
            $this->flag_failure( $order, $sku, 'pull_codes failed: ' . $pull['error'] );
            return;
        }

        // pull-codes response envelope: {"data":{requestId,orderName,totalAmount},"status":true}
        $pull_data    = $pull['data'];
        $first_result = isset( $pull_data['data'] ) ? $pull_data['data'] : $pull_data;

        $wupex_order_name = $first_result['orderName'] ?? '';
        $request_id       = $first_result['requestId'] ?? '';

        if ( empty( $wupex_order_name ) ) {
            $this->flag_failure( $order, $sku, 'pull_codes returned no orderName.' );
            return;
        }

        // Step 2: Persist Wupex order record
        Wupex_DB::insert_order( [
            'wc_order_id'      => $order_id,
            'wupex_order_name' => $wupex_order_name,
            'wupex_request_id' => $request_id,
            'wc_product_id'    => $product_id,
            'sku'              => $sku,
            'quantity'         => $quantity,
            'total_amount'     => $first_result['totalAmount'] ?? 0,
            'status'           => 'pending',
        ] );

        Wupex_API::log( 'PULL_CODES', "orderName={$wupex_order_name} sku={$sku} qty={$quantity}", $order_id );

        // Step 3: Get order detail
        $detail = $api->get_order_detail( $wupex_order_name );

        if ( ! $detail['success'] ) {
            $this->flag_failure( $order, $sku, 'get_order_detail failed: ' . $detail['error'] );
            return;
        }

        // Unwrap envelope: {"data":{id,orderName,orderData:[...]},"status":true}
        $detail_body = $detail['data'];
        if ( isset( $detail_body['data'] ) && is_array( $detail_body['data'] ) ) {
            $detail_body = $detail_body['data'];
        }

        $order_data = $detail_body['orderData'] ?? [];
        $codes_inserted = 0;

        foreach ( $order_data as $order_entry ) {
            $serials = $order_entry['serials'] ?? [];
            foreach ( $serials as $serial ) {
                $serial_code   = $serial['serialCode'] ?? '';
                $serial_number = $serial['serialNumber'] ?? '';

                if ( empty( $serial_code ) ) {
                    continue;
                }

                try {
                    $encrypted = Wupex_Crypto::encrypt( $serial_code );
                    $token     = Wupex_Crypto::generate_token();

                    Wupex_DB::insert_code( [
                        'wc_order_id'           => $order_id,
                        'wupex_order_name'      => $wupex_order_name,
                        'wc_product_id'         => $product_id,
                        'product_name'          => $serial['productName'] ?? $item->get_name(),
                        'sku'                   => $sku,
                        'serial_number'         => $serial_number,
                        'serial_code_encrypted' => $encrypted,
                        'expiry'                => $serial['expiry'] ?? null,
                        'reveal_token'          => $token,
                        'token_expiry'          => null,
                    ] );

                    $codes_inserted++;
                } catch ( Exception $e ) {
                    $this->flag_failure( $order, $sku, 'Encryption error: ' . $e->getMessage() );
                }
            }
        }

        Wupex_DB::update_order_status( $wupex_order_name, 'fulfilled' );
        Wupex_API::log( 'CODES_STORED', "Inserted {$codes_inserted} codes for orderName={$wupex_order_name}", $order_id );

        // Step 4: Send email
        if ( $codes_inserted > 0 ) {
            $email = new Wupex_Email();
            $email->send_code_ready( $order );
        }
    }

    private function flag_failure( WC_Order $order, string $sku, string $message ): void {
        $order_id = $order->get_id();
        $order->add_order_note( sprintf(
            __( '[Wupex] Fulfilment error for SKU %s: %s — flagged for manual review.', 'wupex-gift-cards' ),
            $sku,
            $message
        ) );
        update_post_meta( $order_id, '_wupex_failed', 1 );
        Wupex_API::log( 'FAILURE', "sku={$sku} {$message}", $order_id );
    }

    // -------------------------------------------------------------------------
    // Refund handling
    // -------------------------------------------------------------------------

    public function handle_refund( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $codes = Wupex_DB::get_codes_by_wc_order( $order_id );
        if ( empty( $codes ) ) {
            return; // No Wupex codes — nothing to do
        }

        foreach ( $codes as $code ) {
            if ( ! empty( $code['revealed_at'] ) ) {
                // Code was already revealed — block refund
                $order->add_order_note( sprintf(
                    __( '[Wupex] Refund blocked: code (serial %s) was already revealed on %s.', 'wupex-gift-cards' ),
                    esc_html( $code['serial_number'] ),
                    esc_html( $code['revealed_at'] )
                ) );

                // Revert order status back to completed to block the refund
                remove_action( 'woocommerce_order_status_refunded', [ $this, 'handle_refund' ] );
                $order->update_status( 'completed', __( '[Wupex] Refund blocked — code was revealed.', 'wupex-gift-cards' ) );
                add_action( 'woocommerce_order_status_refunded', [ $this, 'handle_refund' ] );

                Wupex_API::log( 'REFUND_BLOCKED', "code id={$code['id']} revealed_at={$code['revealed_at']}", $order_id );
                return;
            }
        }

        // No codes revealed — allow refund
        Wupex_DB::mark_refunded( $order_id );
        $order->add_order_note( __( '[Wupex] Refund allowed. All codes marked as refunded and reveal tokens invalidated.', 'wupex-gift-cards' ) );
        Wupex_API::log( 'REFUND_ALLOWED', 'codes marked refunded', $order_id );
    }
}
