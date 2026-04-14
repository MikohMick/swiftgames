<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_API {

    private string $base_url;
    private string $api_key;
    private string $merchant_code;

    public function __construct() {
        $env            = get_option( 'wupex_environment', 'sandbox' );
        $this->base_url = ( $env === 'production' )
            ? 'https://service.wupex.com'
            : 'https://sandbox-service.wupex.com';

        $this->api_key       = get_option( 'wupex_api_key', '' );
        $this->merchant_code = get_option( 'wupex_merchant_code', '' );
    }

    // -------------------------------------------------------------------------
    // Public API methods
    // -------------------------------------------------------------------------

    public function get_balance(): array {
        return $this->get( '/api/customer/balance' );
    }

    public function get_products( int $page = 1, int $page_size = 50, array $search = [] ): array {
        $body = array_merge( [
            'page'     => $page,
            'pageSize' => $page_size,
        ], $search );

        return $this->post( '/api/product/merchant/invited/list', $body );
    }

    public function pull_codes( string $sku, int $quantity, string $reference_id ): array {
        $query_args = [
            'referenceId'  => $reference_id,
            'allowTakeAll' => 'true',
        ];

        $body = [ [
            'merchant' => $this->merchant_code,
            'sku'      => $sku,
            'quantity' => $quantity,
        ] ];

        return $this->post( '/api/order/pull-codes', $body, $query_args );
    }

    public function get_order_detail( string $order_name ): array {
        return $this->get( '/api/order/detail', [ 'orderName' => $order_name ] );
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function get( string $path, array $query_args = [] ): array {
        $url = $this->base_url . $path;
        if ( ! empty( $query_args ) ) {
            $url = add_query_arg( $query_args, $url );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => $this->headers(),
        ] );

        return $this->parse_response( $response, 'GET ' . $path );
    }

    private function post( string $path, array $body = [], array $query_args = [] ): array {
        $url = $this->base_url . $path;
        if ( ! empty( $query_args ) ) {
            $url = add_query_arg( $query_args, $url );
        }

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => $this->headers(),
            'body'    => wp_json_encode( $body ),
        ] );

        return $this->parse_response( $response, 'POST ' . $path );
    }

    private function headers(): array {
        return [
            'x-api-key'       => $this->api_key,
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json, text/plain, */*',
            'Accept-Language' => 'en',
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];
    }

    private function parse_response( mixed $response, string $action ): array {
        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
            $this->log( $action, 'WP_Error: ' . $error );
            return [ 'success' => false, 'data' => [], 'error' => $error ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        // Detect WAF/firewall HTML pages masquerading as 200
        if ( str_starts_with( ltrim( $raw ), '<' ) ) {
            $error = 'API returned an HTML page instead of JSON — likely blocked by a firewall (403/WAF). Check API key and server IP whitelist.';
            $this->log( $action, "HTTP {$code} | " . $error );
            return [ 'success' => false, 'data' => [], 'error' => $error ];
        }

        $data = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $error = $data['message'] ?? "HTTP {$code}";
            $this->log( $action, "Error {$code}: {$error}" );
            return [ 'success' => false, 'data' => $data ?? [], 'error' => $error ];
        }

        return [ 'success' => true, 'data' => $data ?? [], 'error' => '' ];
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    public static function log( string $action, string $message, int $order_id = 0 ): void {
        $log_dir = wp_upload_dir()['basedir'] . '/wupex-logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            // Prevent directory listing
            file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' );
        }

        $log_file  = $log_dir . '/wupex-' . date( 'Y-m-d' ) . '.log';
        $timestamp = date( 'Y-m-d H:i:s' );
        $order_str = $order_id ? "[ORDER-{$order_id}]" : '[NO-ORDER]';
        $line      = "[{$timestamp}] {$order_str} [{$action}] {$message}" . PHP_EOL;

        file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
    }
}
