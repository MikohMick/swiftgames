<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Import {

    private const DISPLAY_LIMIT = 50;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
        add_action( 'wp_ajax_wupex_import_products', [ $this, 'ajax_import_products' ] );
        add_action( 'wp_ajax_wupex_sync_stock', [ $this, 'ajax_sync_stock' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Wupex Import Products', 'wupex-gift-cards' ),
            __( 'Wupex Import Products', 'wupex-gift-cards' ),
            'manage_woocommerce',
            'wupex-import',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'wupex-gift-cards' ) );
        }

        $products = $this->fetch_all_products();
        ?>
        <div class="wrap wupex-import-wrap">
            <h1><?php esc_html_e( 'Import Wupex Products', 'wupex-gift-cards' ); ?></h1>

            <div class="notice notice-info"><p>
                <?php esc_html_e( 'Showing 50 in-stock products. Increase limit once ready for full catalog.', 'wupex-gift-cards' ); ?>
            </p></div>

            <p>
                <button type="button" id="wupex-sync-stock" class="button button-secondary">
                    <?php esc_html_e( 'Sync Stock', 'wupex-gift-cards' ); ?>
                </button>
                <span id="wupex-sync-result" style="margin-left:10px;"></span>
            </p>

            <?php if ( ! empty( $products ) ) : ?>
                <form id="wupex-import-form">
                    <?php wp_nonce_field( 'wupex_admin_nonce', 'wupex_nonce' ); ?>
                    <table class="wp-list-table widefat fixed striped wupex-product-table">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="wupex-select-all" /></th>
                                <th><?php esc_html_e( 'Image', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Product Name', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'SKU', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Wupex Price', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'WC Price (after markup)', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Stock', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'wupex-gift-cards' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $products as $product ) :
                                $wupex_price = (float) ( $product['retailPrice'] ?? $product['price'] ?? 0 );
                                $markup      = (float) get_option( 'wupex_markup_percentage', 0 );
                                $wc_price    = round( $wupex_price + ( $wupex_price * $markup / 100 ), 2 );
                                $sku         = $product['productCode'] ?? '';
                                $imported    = $this->is_already_imported( $sku );
                            ?>
                            <tr>
                                <td class="check-column">
                                    <input type="checkbox" name="products[]" value="<?php echo esc_attr( wp_json_encode( $product ) ); ?>" />
                                </td>
                                <td>
                                    <?php if ( ! empty( $product['imageUrl'] ) ) : ?>
                                        <img src="<?php echo esc_url( $product['imageUrl'] ); ?>" width="50" height="50" style="object-fit:cover;" />
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $product['productName'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $sku ); ?></td>
                                <td><?php echo esc_html( wc_price( $wupex_price ) ); ?></td>
                                <td><?php echo esc_html( wc_price( $wc_price ) ); ?></td>
                                <td><?php echo esc_html( $product['available'] ?? 0 ); ?></td>
                                <td><?php echo esc_html( $product['productType'] ?? '' ); ?></td>
                                <td>
                                    <?php if ( $imported ) : ?>
                                        <span class="wupex-badge wupex-badge-imported"><?php esc_html_e( 'Imported', 'wupex-gift-cards' ); ?></span>
                                    <?php else : ?>
                                        <span class="wupex-badge wupex-badge-new"><?php esc_html_e( 'Not imported', 'wupex-gift-cards' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p style="margin-top:15px;">
                        <button type="button" id="wupex-import-selected" class="button button-primary">
                            <?php esc_html_e( 'Import Selected', 'wupex-gift-cards' ); ?>
                        </button>
                    </p>
                </form>

                <div id="wupex-import-progress" style="display:none; margin-top:15px;">
                    <div class="wupex-progress-bar-wrap">
                        <div class="wupex-progress-bar" id="wupex-progress-fill"></div>
                    </div>
                    <p id="wupex-progress-text"></p>
                </div>

                <div id="wupex-import-summary" style="display:none; margin-top:15px;"></div>

            <?php else : ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e( 'No in-stock products found.', 'wupex-gift-cards' ); ?>
                    <?php if ( $this->last_fetch_error ) : ?>
                        <br /><strong><?php esc_html_e( 'Reason:', 'wupex-gift-cards' ); ?></strong>
                        <?php echo esc_html( $this->last_fetch_error ); ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $this->last_raw_response ) ) : ?>
                        <br /><strong><?php esc_html_e( 'API response keys:', 'wupex-gift-cards' ); ?></strong>
                        <code><?php echo esc_html( implode( ', ', array_keys( $this->last_raw_response ) ) ); ?></code>
                    <?php endif; ?>
                </p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Product fetch (paginated)
    // -------------------------------------------------------------------------

    private string $last_fetch_error = '';
    private array  $last_raw_response = [];

    private function fetch_all_products(): array {
        $api      = new Wupex_API();
        $results  = [];
        $page     = 1;
        $per_page = 100;

        do {
            $response = $api->get_products( $page, $per_page );

            // Store raw response for diagnostics
            $this->last_raw_response = $response['data'] ?? [];

            if ( ! $response['success'] ) {
                $this->last_fetch_error = $response['error'] ?? 'Unknown API error';
                Wupex_API::log( 'IMPORT_FETCH', 'API error: ' . $this->last_fetch_error );
                break;
            }

            // Try every likely key the Wupex API might use
            $items = $response['data']['items']
                ?? $response['data']['products']
                ?? $response['data']['data']
                ?? $response['data']['result']
                ?? $response['data']['list']
                ?? ( is_array( $response['data'] ) && isset( $response['data'][0] ) ? $response['data'] : [] );

            if ( empty( $items ) ) {
                // Log the actual keys returned so we can adapt
                Wupex_API::log( 'IMPORT_FETCH', 'No items found. Response keys: ' . implode( ', ', array_keys( $response['data'] ) ) );
            }

            $all_count = 0;
            foreach ( $items as $item ) {
                $all_count++;
                if ( (int) ( $item['available'] ?? 0 ) > 0 ) {
                    $results[] = $item;
                }
                if ( count( $results ) >= self::DISPLAY_LIMIT ) {
                    break 2;
                }
            }

            if ( $all_count > 0 && empty( $results ) ) {
                $this->last_fetch_error = "API returned {$all_count} product(s) but none have available > 0 (all out of stock in sandbox).";
            }

            $total_pages = (int) ( $response['data']['totalPage'] ?? $response['data']['pages'] ?? $response['data']['totalPages'] ?? 1 );
            $page++;
        } while ( $page <= $total_pages && count( $results ) < self::DISPLAY_LIMIT );

        return $results;
    }

    private function is_already_imported( string $sku ): bool {
        if ( empty( $sku ) ) {
            return false;
        }
        $args = [
            'post_type'  => 'product',
            'meta_query' => [ [ 'key' => '_wupex_sku', 'value' => $sku, 'compare' => '=' ] ],
            'fields'     => 'ids',
            'limit'      => 1,
        ];
        $query = new WC_Product_Query( $args );
        return ! empty( $query->get_products() );
    }

    // -------------------------------------------------------------------------
    // AJAX: Import selected
    // -------------------------------------------------------------------------

    public function ajax_import_products(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        $raw_products = isset( $_POST['products'] ) ? (array) $_POST['products'] : [];
        $imported     = 0;
        $skipped      = 0;
        $failed       = 0;

        foreach ( $raw_products as $raw ) {
            $product = json_decode( wp_unslash( $raw ), true );
            if ( ! $product ) {
                $failed++;
                continue;
            }

            $result = $this->import_single_product( $product );
            if ( $result === 'imported' ) {
                $imported++;
            } elseif ( $result === 'skipped' ) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        Wupex_API::log( 'IMPORT', "imported={$imported} skipped={$skipped} failed={$failed}" );
        wp_send_json_success( [
            'imported' => $imported,
            'skipped'  => $skipped,
            'failed'   => $failed,
        ] );
    }

    private function import_single_product( array $data ): string {
        $sku          = $data['productCode'] ?? '';
        $product_name = $data['productName'] ?? '';

        if ( empty( $sku ) || empty( $product_name ) ) {
            return 'failed';
        }

        // Skip already imported
        if ( $this->is_already_imported( $sku ) ) {
            return 'skipped';
        }

        $markup     = (float) get_option( 'wupex_markup_percentage', 0 );
        $wupex_price = (float) ( $data['retailPrice'] ?? $data['price'] ?? 0 );
        $wc_price   = round( $wupex_price + ( $wupex_price * $markup / 100 ), 2 );
        $available  = (int) ( $data['available'] ?? 0 );

        $product = new WC_Product_Simple();
        $product->set_name( $product_name );
        $product->set_sku( $sku );
        $product->set_regular_price( (string) $wc_price );
        $product->set_virtual( true );
        $product->set_downloadable( false );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $available );
        $product->set_stock_status( $available > 0 ? 'instock' : 'outofstock' );

        // Auto-create category
        if ( get_option( 'wupex_auto_categories', '1' ) === '1' && ! empty( $data['productType'] ) ) {
            $cat_id = $this->get_or_create_category( $data['productType'] );
            if ( $cat_id ) {
                $product->set_category_ids( [ $cat_id ] );
            }
        }

        $product_id = $product->save();

        if ( ! $product_id ) {
            return 'failed';
        }

        update_post_meta( $product_id, '_wupex_sku', $sku );
        update_post_meta( $product_id, '_wupex_price', $wupex_price );

        // Download and attach product image
        if ( ! empty( $data['imageUrl'] ) ) {
            $this->attach_product_image( $product_id, $data['imageUrl'], $product_name );
        }

        return 'imported';
    }

    private function get_or_create_category( string $name ): int {
        $term = get_term_by( 'name', $name, 'product_cat' );
        if ( $term ) {
            return $term->term_id;
        }

        $result = wp_insert_term( $name, 'product_cat' );
        if ( is_wp_error( $result ) ) {
            return 0;
        }

        return $result['term_id'];
    }

    private function attach_product_image( int $product_id, string $image_url, string $product_name ): void {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            return;
        }

        $file_array = [
            'name'     => sanitize_file_name( basename( $image_url ) ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $product_id, $product_name );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return;
        }

        set_post_thumbnail( $product_id, $attachment_id );
    }

    // -------------------------------------------------------------------------
    // AJAX: Sync stock
    // -------------------------------------------------------------------------

    public function ajax_sync_stock(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        $updated = self::run_stock_sync();
        wp_send_json_success( [
            'message' => sprintf( __( 'Stock synced for %d products.', 'wupex-gift-cards' ), $updated ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Stock sync (also called by WP-Cron)
    // -------------------------------------------------------------------------

    public static function run_stock_sync(): int {
        $api   = new Wupex_API();
        $page  = 1;
        $size  = 100;
        $total = 0;

        Wupex_API::log( 'STOCK_SYNC', 'Starting stock sync' );

        do {
            $response = $api->get_products( $page, $size );
            if ( ! $response['success'] ) {
                Wupex_API::log( 'STOCK_SYNC', 'API error: ' . $response['error'] );
                break;
            }

            $items = $response['data']['items'] ?? $response['data']['products'] ?? [];

            foreach ( $items as $item ) {
                $sku       = $item['productCode'] ?? '';
                $available = (int) ( $item['available'] ?? 0 );

                if ( empty( $sku ) ) {
                    continue;
                }

                $query = new WC_Product_Query( [
                    'post_type'  => 'product',
                    'meta_query' => [ [ 'key' => '_wupex_sku', 'value' => $sku, 'compare' => '=' ] ],
                    'fields'     => 'ids',
                    'limit'      => 1,
                ] );

                $ids = $query->get_products();
                if ( empty( $ids ) ) {
                    continue;
                }

                $wc_product = wc_get_product( $ids[0] );
                if ( ! $wc_product ) {
                    continue;
                }

                $wc_product->set_stock_quantity( $available );
                $wc_product->set_stock_status( $available > 0 ? 'instock' : 'outofstock' );
                $wc_product->save();
                $total++;
            }

            $total_pages = (int) ( $response['data']['totalPage'] ?? $response['data']['pages'] ?? 1 );
            $page++;
        } while ( $page <= $total_pages );

        Wupex_API::log( 'STOCK_SYNC', "Completed. Updated {$total} products." );
        return $total;
    }
}
