<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_DB {

    /**
     * Create (or upgrade) both custom tables.
     * Safe to call multiple times — uses dbDelta().
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Table 1: wupex_orders
        $orders_table = $wpdb->prefix . 'wupex_orders';
        dbDelta( "CREATE TABLE {$orders_table} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_order_id       BIGINT UNSIGNED NOT NULL,
            wupex_order_name  VARCHAR(100)    NOT NULL DEFAULT '',
            wupex_request_id  VARCHAR(100)    NOT NULL DEFAULT '',
            wc_product_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku               VARCHAR(100)    NOT NULL DEFAULT '',
            quantity          INT             NOT NULL DEFAULT 0,
            total_amount      DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
            status            VARCHAR(50)     NOT NULL DEFAULT 'pending',
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY wc_order_id (wc_order_id)
        ) {$charset_collate};" );

        // Table 2: wupex_codes
        $codes_table = $wpdb->prefix . 'wupex_codes';
        dbDelta( "CREATE TABLE {$codes_table} (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_order_id            BIGINT UNSIGNED NOT NULL,
            wupex_order_name       VARCHAR(100)    NOT NULL DEFAULT '',
            wc_product_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_name           VARCHAR(255)    NOT NULL DEFAULT '',
            sku                    VARCHAR(100)    NOT NULL DEFAULT '',
            serial_number          VARCHAR(255)    NOT NULL DEFAULT '',
            serial_code_encrypted  TEXT            NOT NULL,
            expiry                 VARCHAR(100)    DEFAULT NULL,
            reveal_token           VARCHAR(100)    NOT NULL DEFAULT '',
            token_expiry           DATETIME        DEFAULT NULL,
            revealed_at            DATETIME        DEFAULT NULL,
            reveal_count           INT             NOT NULL DEFAULT 0,
            status                 VARCHAR(50)     NOT NULL DEFAULT 'unrevealed',
            created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY reveal_token (reveal_token),
            KEY wc_order_id (wc_order_id)
        ) {$charset_collate};" );
    }

    // -------------------------------------------------------------------------
    // wupex_orders helpers
    // -------------------------------------------------------------------------

    public static function insert_order( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'wupex_orders',
            [
                'wc_order_id'      => (int) $data['wc_order_id'],
                'wupex_order_name' => sanitize_text_field( $data['wupex_order_name'] ),
                'wupex_request_id' => sanitize_text_field( $data['wupex_request_id'] ?? '' ),
                'wc_product_id'    => (int) ( $data['wc_product_id'] ?? 0 ),
                'sku'              => sanitize_text_field( $data['sku'] ?? '' ),
                'quantity'         => (int) ( $data['quantity'] ?? 0 ),
                'total_amount'     => (float) ( $data['total_amount'] ?? 0 ),
                'status'           => sanitize_text_field( $data['status'] ?? 'pending' ),
                'created_at'       => current_time( 'mysql' ),
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%d', '%f', '%s', '%s', '%s' ]
        );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update_order_status( string $wupex_order_name, string $status ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'wupex_orders',
            [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'wupex_order_name' => $wupex_order_name ],
            [ '%s', '%s' ],
            [ '%s' ]
        );
    }

    public static function get_orders_by_wc_order( int $wc_order_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wupex_orders WHERE wc_order_id = %d",
                $wc_order_id
            ),
            ARRAY_A
        );
    }

    // -------------------------------------------------------------------------
    // wupex_codes helpers
    // -------------------------------------------------------------------------

    public static function insert_code( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'wupex_codes',
            [
                'wc_order_id'           => (int) $data['wc_order_id'],
                'wupex_order_name'      => sanitize_text_field( $data['wupex_order_name'] ),
                'wc_product_id'         => (int) ( $data['wc_product_id'] ?? 0 ),
                'product_name'          => sanitize_text_field( $data['product_name'] ?? '' ),
                'sku'                   => sanitize_text_field( $data['sku'] ?? '' ),
                'serial_number'         => sanitize_text_field( $data['serial_number'] ?? '' ),
                'serial_code_encrypted' => $data['serial_code_encrypted'],
                'expiry'                => isset( $data['expiry'] ) ? sanitize_text_field( $data['expiry'] ) : null,
                'reveal_token'          => sanitize_text_field( $data['reveal_token'] ),
                'token_expiry'          => $data['token_expiry'],
                'status'                => 'unrevealed',
                'created_at'            => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return $result ? $wpdb->insert_id : false;
    }

    public static function get_code_by_token( string $token ): array|null {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wupex_codes WHERE reveal_token = %s",
                $token
            ),
            ARRAY_A
        );
    }

    public static function get_codes_by_wc_order( int $wc_order_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wupex_codes WHERE wc_order_id = %d",
                $wc_order_id
            ),
            ARRAY_A
        );
    }

    public static function mark_revealed( int $code_id ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wupex_codes
                 SET status = 'revealed',
                     revealed_at = COALESCE(revealed_at, %s),
                     reveal_count = reveal_count + 1
                 WHERE id = %d",
                current_time( 'mysql' ),
                $code_id
            )
        );
    }

    public static function mark_refunded( int $wc_order_id ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wupex_codes
                 SET status = 'refunded', token_expiry = %s
                 WHERE wc_order_id = %d",
                current_time( 'mysql' ),
                $wc_order_id
            )
        );
    }

    public static function resend_email_data( int $wc_order_id ): array {
        return self::get_codes_by_wc_order( $wc_order_id );
    }
}
