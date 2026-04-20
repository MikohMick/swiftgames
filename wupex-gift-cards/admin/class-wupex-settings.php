<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_wupex_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_wupex_get_logs', [ $this, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_wupex_send_test_email', [ $this, 'ajax_send_test_email' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Wupex Gift Cards', 'wupex-gift-cards' ),
            __( 'Wupex Gift Cards', 'wupex-gift-cards' ),
            'manage_woocommerce',
            'wupex-gift-cards',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        $fields = [
            'wupex_api_key'           => 'string',
            'wupex_merchant_code'     => 'string',
            'wupex_environment'       => 'string',
            'wupex_encryption_key'    => 'string',
            'wupex_markup_percentage' => 'number',
            'wupex_auto_categories'   => 'string',
        ];

        foreach ( $fields as $key => $type ) {
            register_setting( 'wupex_settings_group', $key, [ 'type' => $type, 'sanitize_callback' => 'sanitize_text_field' ] );
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'wupex-gift-cards' ) );
        }
        ?>
        <div class="wrap wupex-settings-wrap">
            <h1><?php esc_html_e( 'Wupex Gift Cards', 'wupex-gift-cards' ); ?></h1>

            <?php settings_errors( 'wupex_settings_group' ); ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'wupex_settings_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="wupex_api_key"><?php esc_html_e( 'API Key', 'wupex-gift-cards' ); ?></label></th>
                        <td>
                            <input type="password" name="wupex_api_key" id="wupex_api_key"
                                   value="<?php echo esc_attr( get_option( 'wupex_api_key', '' ) ); ?>"
                                   class="regular-text" autocomplete="new-password" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wupex_merchant_code"><?php esc_html_e( 'Merchant Code', 'wupex-gift-cards' ); ?></label></th>
                        <td>
                            <input type="text" name="wupex_merchant_code" id="wupex_merchant_code"
                                   value="<?php echo esc_attr( get_option( 'wupex_merchant_code', '' ) ); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wupex_environment"><?php esc_html_e( 'API Environment', 'wupex-gift-cards' ); ?></label></th>
                        <td>
                            <select name="wupex_environment" id="wupex_environment">
                                <option value="sandbox" <?php selected( get_option( 'wupex_environment', 'sandbox' ), 'sandbox' ); ?>>
                                    <?php esc_html_e( 'Sandbox', 'wupex-gift-cards' ); ?>
                                </option>
                                <option value="production" <?php selected( get_option( 'wupex_environment', 'sandbox' ), 'production' ); ?>>
                                    <?php esc_html_e( 'Production', 'wupex-gift-cards' ); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wupex_encryption_key"><?php esc_html_e( 'Encryption Key', 'wupex-gift-cards' ); ?></label></th>
                        <td>
                            <input type="password" name="wupex_encryption_key" id="wupex_encryption_key"
                                   value="<?php echo esc_attr( get_option( 'wupex_encryption_key', '' ) ); ?>"
                                   class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e( 'Used to AES-256 encrypt PIN codes. Do not change after codes are stored.', 'wupex-gift-cards' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wupex_markup_percentage"><?php esc_html_e( 'Price Markup (%)', 'wupex-gift-cards' ); ?></label></th>
                        <td>
                            <input type="number" name="wupex_markup_percentage" id="wupex_markup_percentage"
                                   value="<?php echo esc_attr( get_option( 'wupex_markup_percentage', 0 ) ); ?>"
                                   min="0" step="0.01" class="small-text" />
                            <p class="description"><?php esc_html_e( 'e.g. 20 = 20% markup over Wupex price. Applied on product import.', 'wupex-gift-cards' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Auto-create Categories', 'wupex-gift-cards' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wupex_auto_categories" value="1"
                                    <?php checked( get_option( 'wupex_auto_categories', '1' ), '1' ); ?> />
                                <?php esc_html_e( 'Automatically create WooCommerce categories from product type on import', 'wupex-gift-cards' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'wupex-gift-cards' ) ); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Tools', 'wupex-gift-cards' ); ?></h2>
            <p>
                <button type="button" id="wupex-test-connection" class="button button-secondary">
                    <?php esc_html_e( 'Test Connection', 'wupex-gift-cards' ); ?>
                </button>
                <span id="wupex-connection-result" style="margin-left:10px;"></span>
            </p>
            <p>
                <label for="wupex-test-email-address" style="font-weight:600;">
                    <?php esc_html_e( 'Send Test Email:', 'wupex-gift-cards' ); ?>
                </label>
                <input type="email" id="wupex-test-email-address"
                       value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                       placeholder="email@example.com"
                       style="width:260px; margin: 0 8px;" />
                <button type="button" id="wupex-send-test-email" class="button button-secondary">
                    <?php esc_html_e( 'Send Test Email', 'wupex-gift-cards' ); ?>
                </button>
                <span id="wupex-test-email-result" style="margin-left:10px;"></span>
                <br /><span class="description" style="margin-left:0;">
                    <?php esc_html_e( 'Sends a sample code-ready email with dummy data so you can check layout and delivery.', 'wupex-gift-cards' ); ?>
                </span>
            </p>

            <hr />

            <h2><?php esc_html_e( 'Recent Log Entries', 'wupex-gift-cards' ); ?></h2>
            <p>
                <button type="button" id="wupex-view-logs" class="button button-secondary">
                    <?php esc_html_e( 'View Logs', 'wupex-gift-cards' ); ?>
                </button>
            </p>
            <div id="wupex-log-output" style="display:none;">
                <pre id="wupex-log-content" class="wupex-log-pre"></pre>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: Test Connection
    // -------------------------------------------------------------------------

    public function ajax_test_connection(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        // Detect the outbound IP WordPress actually uses for external HTTP requests
        $ip_response = wp_remote_get( 'https://api.ipify.org', [ 'timeout' => 10 ] );
        $outbound_ip = ( ! is_wp_error( $ip_response ) )
            ? wp_remote_retrieve_body( $ip_response )
            : 'unknown';

        $api    = new Wupex_API();
        $result = $api->get_balance();

        if ( $result['success'] ) {
            $data    = $result['data'];
            $balance = $data['data']['balance'] ?? $data['balance'] ?? 'N/A';
            $credit  = $data['data']['credit']  ?? $data['credit']  ?? 'N/A';
            wp_send_json_success( [
                'message' => sprintf(
                    __( 'Connection successful! Balance: %s | Credit: %s | Server outbound IP: %s', 'wupex-gift-cards' ),
                    $balance,
                    $credit,
                    esc_html( $outbound_ip )
                ),
            ] );
        } else {
            wp_send_json_error( [
                'message' => $result['error'] . sprintf( __( ' | Server outbound IP: %s', 'wupex-gift-cards' ), esc_html( $outbound_ip ) ),
            ] );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: View Logs
    // -------------------------------------------------------------------------

    public function ajax_get_logs(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        $log_dir  = wp_upload_dir()['basedir'] . '/wupex-logs';
        $log_file = $log_dir . '/wupex-' . date( 'Y-m-d' ) . '.log';

        if ( ! file_exists( $log_file ) ) {
            wp_send_json_success( [ 'lines' => __( 'No log entries for today.', 'wupex-gift-cards' ) ] );
            return;
        }

        $lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $last  = array_slice( $lines, -100 );
        wp_send_json_success( [ 'lines' => implode( "\n", array_reverse( $last ) ) ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Send Test Email
    // -------------------------------------------------------------------------

    public function ajax_send_test_email(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        $to = sanitize_email( $_POST['email'] ?? '' );
        if ( ! is_email( $to ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'wupex-gift-cards' ) ] );
        }

        // Build a dummy reveal URL
        $dummy_token    = 'TEST' . strtoupper( bin2hex( random_bytes( 14 ) ) );
        $base_reveal    = get_option( 'wupex_reveal_page_url', home_url( '/reveal-code/' ) );
        $reveal_url     = add_query_arg( 'token', $dummy_token, $base_reveal );

        // Dummy order object (stdClass acting as a minimal WC_Order stand-in for the template)
        $dummy_order = new class {
            public function get_order_number(): string  { return 'TEST-001'; }
            public function get_billing_first_name(): string { return 'Test'; }
            public function get_billing_email(): string { return ''; }
            public function get_id(): int { return 0; }
            public function get_date_created(): object {
                return new class {
                    public function date_i18n( string $format ): string {
                        return date_i18n( $format );
                    }
                };
            }
        };

        // Dummy code row
        $dummy_code = [
            'reveal_token' => $dummy_token,
            'product_name' => 'Apple Gift Card $10 (USA)',
        ];

        // Render the email template
        $codes      = [ $dummy_code ];
        $order      = $dummy_order;
        ob_start();
        include WUPEX_PLUGIN_DIR . 'templates/email-code-ready.php';
        $content = ob_get_clean();

        $subject = sprintf(
            __( '[TEST] Your PSN Gift Card is Ready — Order #%s', 'wupex-gift-cards' ),
            $dummy_order->get_order_number()
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
        ];

        $sent = wp_mail( $to, $subject, $content, $headers );

        if ( $sent ) {
            Wupex_API::log( 'TEST_EMAIL', "Test email sent to {$to}" );
            wp_send_json_success( [
                'message' => sprintf( __( 'Test email sent to %s — check your inbox.', 'wupex-gift-cards' ), $to ),
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'wp_mail() failed. Check your WordPress mail configuration.', 'wupex-gift-cards' ) ] );
        }
    }
}
