<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Import {

    private const PER_PAGE     = 20;
    private const CACHE_KEY    = 'wupex_product_cache';
    private const CACHE_EXPIRY = 300; // 5 minutes

    private string $last_fetch_error = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
        add_action( 'wp_ajax_wupex_import_products', [ $this, 'ajax_import_products' ] );
        add_action( 'wp_ajax_wupex_sync_stock', [ $this, 'ajax_sync_stock' ] );
        add_action( 'wp_ajax_wupex_refresh_products', [ $this, 'ajax_refresh_products' ] );
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

    // -------------------------------------------------------------------------
    // Render page
    // -------------------------------------------------------------------------

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'wupex-gift-cards' ) );
        }

        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $all_products = $this->get_cached_products();
        $total        = count( $all_products );
        $total_pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $current_page = min( $current_page, $total_pages );
        $offset       = ( $current_page - 1 ) * self::PER_PAGE;
        $products     = array_slice( $all_products, $offset, self::PER_PAGE );
        $markup       = (float) get_option( 'wupex_markup_percentage', 0 );

        // Build type → imageUrl fallback from ALL cached products (not just current page)
        $type_image_map = [];
        foreach ( $all_products as $p ) {
            $type = $p['productType'] ?? '';
            if ( ! empty( $type ) && ! empty( $p['imageUrl'] ) && ! isset( $type_image_map[ $type ] ) ) {
                $type_image_map[ $type ] = $p['imageUrl'];
            }
        }

        $base_url = admin_url( 'admin.php?page=wupex-import' );
        ?>
        <div class="wrap wupex-import-wrap">
            <h1><?php esc_html_e( 'Import Wupex Products', 'wupex-gift-cards' ); ?></h1>

            <div class="tablenav top" style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                <button type="button" id="wupex-sync-stock" class="button button-secondary">
                    <?php esc_html_e( 'Sync Stock', 'wupex-gift-cards' ); ?>
                </button>
                <button type="button" id="wupex-refresh-products" class="button button-secondary">
                    <?php esc_html_e( 'Refresh Product List', 'wupex-gift-cards' ); ?>
                </button>
                <span id="wupex-sync-result"></span>
            </div>

            <?php if ( ! empty( $this->last_fetch_error ) && empty( $products ) ) : ?>
                <div class="notice notice-warning"><p>
                    <strong><?php esc_html_e( 'Could not load products:', 'wupex-gift-cards' ); ?></strong>
                    <?php echo esc_html( $this->last_fetch_error ); ?>
                </p></div>

            <?php elseif ( ! empty( $products ) ) : ?>

                <form id="wupex-import-form">
                    <?php wp_nonce_field( 'wupex_admin_nonce', 'wupex_nonce' ); ?>

                    <!-- Top tablenav -->
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <button type="button" id="wupex-import-selected" class="button button-primary">
                                <?php esc_html_e( 'Import Selected', 'wupex-gift-cards' ); ?>
                            </button>
                        </div>
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(
                                    esc_html( _n( '%s item', '%s items', $total, 'wupex-gift-cards' ) ),
                                    number_format_i18n( $total )
                                ); ?>
                            </span>
                            <?php $this->render_pagination( $current_page, $total_pages, $base_url ); ?>
                        </div>
                        <br class="clear" />
                    </div>

                    <table class="wp-list-table widefat fixed striped wupex-product-table">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column">
                                    <input type="checkbox" id="wupex-select-all" />
                                </td>
                                <th style="width:64px;"><?php esc_html_e( 'Image', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Product Name', 'wupex-gift-cards' ); ?></th>
                                <th style="width:155px;"><?php esc_html_e( 'SKU', 'wupex-gift-cards' ); ?></th>
                                <th style="width:100px;"><?php esc_html_e( 'Wupex Price', 'wupex-gift-cards' ); ?></th>
                                <th style="width:120px;"><?php esc_html_e( 'WC Price (+markup)', 'wupex-gift-cards' ); ?></th>
                                <th style="width:65px;"><?php esc_html_e( 'Stock', 'wupex-gift-cards' ); ?></th>
                                <th style="width:115px;"><?php esc_html_e( 'Type', 'wupex-gift-cards' ); ?></th>
                                <th style="width:105px;"><?php esc_html_e( 'Status', 'wupex-gift-cards' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $products as $product ) :
                                $wupex_price  = (float) ( $product['retailPrice'] ?? $product['price'] ?? 0 );
                                $wc_price     = round( $wupex_price + ( $wupex_price * $markup / 100 ), 2 );
                                $sku          = $product['productCode'] ?? '';
                                $imported     = $this->is_already_imported( $sku );
                                $type         = $product['productType'] ?? '';
                                $image_url    = $product['imageUrl'] ?? '';
                                $fallback_url = ( empty( $image_url ) && isset( $type_image_map[ $type ] ) )
                                                    ? $type_image_map[ $type ] : '';
                                $display_url  = ! empty( $image_url ) ? $image_url : $fallback_url;
                                $is_fallback  = empty( $image_url ) && ! empty( $fallback_url );
                            ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="products[]"
                                           value="<?php echo esc_attr( wp_json_encode( $product ) ); ?>" />
                                </th>
                                <td>
                                    <?php if ( ! empty( $display_url ) ) : ?>
                                        <img src="<?php echo esc_url( $display_url ); ?>"
                                             width="50" height="50"
                                             style="object-fit:cover; border-radius:3px; <?php echo $is_fallback ? 'opacity:0.75;' : ''; ?>"
                                             title="<?php echo $is_fallback ? esc_attr__( 'Shared image from same product type', 'wupex-gift-cards' ) : esc_attr( $product['productName'] ?? '' ); ?>" />
                                    <?php else : ?>
                                        <div class="wupex-no-image" title="<?php esc_attr_e( 'No image available', 'wupex-gift-cards' ); ?>">
                                            <span>&#128247;</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $product['productName'] ?? '' ); ?></td>
                                <td><code><?php echo esc_html( $sku ); ?></code></td>
                                <td><?php echo wp_kses_post( wc_price( $wupex_price ) ); ?></td>
                                <td><?php echo wp_kses_post( wc_price( $wc_price ) ); ?></td>
                                <td><?php echo esc_html( $product['available'] ?? 0 ); ?></td>
                                <td><?php echo esc_html( $type ); ?></td>
                                <td>
                                    <?php if ( $imported ) : ?>
                                        <span class="wupex-badge wupex-badge-imported">
                                            <?php esc_html_e( 'Imported', 'wupex-gift-cards' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="wupex-badge wupex-badge-new">
                                            <?php esc_html_e( 'Not imported', 'wupex-gift-cards' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="manage-column column-cb check-column">
                                    <input type="checkbox" />
                                </td>
                                <th style="width:64px;"><?php esc_html_e( 'Image', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Product Name', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'SKU', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Wupex Price', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'WC Price (+markup)', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Stock', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'wupex-gift-cards' ); ?></th>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- Bottom tablenav -->
                    <div class="tablenav bottom">
                        <div class="alignleft actions">
                            <button type="button" class="button button-primary" onclick="document.getElementById('wupex-import-selected').click()">
                                <?php esc_html_e( 'Import Selected', 'wupex-gift-cards' ); ?>
                            </button>
                        </div>
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(
                                    esc_html( _n( '%s item', '%s items', $total, 'wupex-gift-cards' ) ),
                                    number_format_i18n( $total )
                                ); ?>
                            </span>
                            <?php $this->render_pagination( $current_page, $total_pages, $base_url ); ?>
                        </div>
                        <br class="clear" />
                    </div>

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
                    <?php esc_html_e( 'No in-stock products found. Try clicking "Refresh Product List" or check your API settings.', 'wupex-gift-cards' ); ?>
                </p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // WooCommerce-style pagination using paginate_links()
    // -------------------------------------------------------------------------

    private function render_pagination( int $current, int $total, string $base_url ): void {
        if ( $total <= 1 ) {
            return;
        }

        $links = paginate_links( [
            'base'      => add_query_arg( 'paged', '%#%', $base_url ),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'current'   => $current,
            'total'     => $total,
            'type'      => 'array',
        ] );

        if ( ! $links ) {
            return;
        }

        echo '<span class="pagination-links">';
        foreach ( $links as $link ) {
            echo $link; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</span>';
    }

    // -------------------------------------------------------------------------
    // Product cache — fetch all in-stock products once, cache for 5 min
    // -------------------------------------------------------------------------

    private function get_cached_products(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) {
            return $cached;
        }
        return $this->fetch_and_cache_all_products();
    }

    private function fetch_and_cache_all_products(): array {
        $api      = new Wupex_API();
        $results  = [];
        $api_page = 1;

        do {
            $response = $api->get_products( $api_page, 100 );

            if ( ! $response['success'] ) {
                $this->last_fetch_error = $response['error'] ?? 'Unknown API error';
                Wupex_API::log( 'IMPORT_FETCH', 'API error: ' . $this->last_fetch_error );
                break;
            }

            $items = $response['data']['items']
                ?? $response['data']['products']
                ?? $response['data']['data']
                ?? $response['data']['result']
                ?? $response['data']['list']
                ?? ( isset( $response['data'][0] ) ? $response['data'] : [] );

            if ( $api_page === 1 && empty( $items ) ) {
                $raw_preview = substr( wp_json_encode( $response['data'] ), 0, 600 );
                Wupex_API::log( 'IMPORT_FETCH', 'No items found. Raw response: ' . $raw_preview );
            }

            foreach ( $items as $item ) {
                if ( (int) ( $item['available'] ?? 0 ) > 0 ) {
                    $results[] = $item;
                }
            }

            $total_api_pages = (int) ( $response['data']['totalPage'] ?? $response['data']['pages'] ?? $response['data']['totalPages'] ?? 1 );
            $api_page++;
        } while ( $api_page <= $total_api_pages );

        if ( ! empty( $results ) ) {
            set_transient( self::CACHE_KEY, $results, self::CACHE_EXPIRY );
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // AJAX: Refresh product list (busts cache)
    // -------------------------------------------------------------------------

    public function ajax_refresh_products(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        delete_transient( self::CACHE_KEY );
        wp_send_json_success( [ 'message' => __( 'Product list refreshed. Reloading…', 'wupex-gift-cards' ) ] );
    }

    private function is_already_imported( string $sku ): bool {
        if ( empty( $sku ) ) {
            return false;
        }
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wupex_sku'
               AND pm.meta_value = %s
               AND p.post_type = 'product'
               AND p.post_status != 'trash'
             LIMIT 1",
            $sku
        ) );
        return ! empty( $result );
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
        $imported = $skipped = $failed = 0;

        // Build type → imageUrl fallback map from the batch
        $type_image_map = [];
        foreach ( $raw_products as $raw ) {
            $p = json_decode( wp_unslash( $raw ), true );
            if ( $p && ! empty( $p['productType'] ) && ! empty( $p['imageUrl'] ) ) {
                $type_image_map[ $p['productType'] ] ??= $p['imageUrl'];
            }
        }

        foreach ( $raw_products as $raw ) {
            $product = json_decode( wp_unslash( $raw ), true );
            if ( ! $product ) {
                $failed++;
                continue;
            }

            // Fill missing imageUrl from same-type fallback
            if ( empty( $product['imageUrl'] ) && ! empty( $product['productType'] ) ) {
                $product['imageUrl'] = $type_image_map[ $product['productType'] ] ?? '';
            }

            $result = $this->import_single_product( $product );
            match ( $result ) {
                'imported' => $imported++,
                'skipped'  => $skipped++,
                default    => $failed++,
            };
        }

        // Bust cache so refreshed status shows on next page load
        delete_transient( self::CACHE_KEY );

        Wupex_API::log( 'IMPORT', "imported={$imported} skipped={$skipped} failed={$failed}" );
        wp_send_json_success( compact( 'imported', 'skipped', 'failed' ) );
    }

    private function import_single_product( array $data ): string {
        $sku          = $data['productCode'] ?? '';
        $product_name = $data['productName'] ?? '';

        if ( empty( $sku ) || empty( $product_name ) ) {
            return 'failed';
        }

        if ( $this->is_already_imported( $sku ) ) {
            return 'skipped';
        }

        $markup      = (float) get_option( 'wupex_markup_percentage', 0 );
        $wupex_price = (float) ( $data['retailPrice'] ?? $data['price'] ?? 0 );
        $wc_price    = round( $wupex_price + ( $wupex_price * $markup / 100 ), 2 );
        $available   = (int) ( $data['available'] ?? 0 );

        $product = new WC_Product_Simple();
        $product->set_name( $product_name );
        $product->set_sku( $sku );
        $product->set_regular_price( (string) $wc_price );
        $product->set_virtual( true );
        $product->set_downloadable( false );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $available );
        $product->set_stock_status( $available > 0 ? 'instock' : 'outofstock' );

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
        return is_wp_error( $result ) ? 0 : $result['term_id'];
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
    // Stock sync — called by button and WP-Cron
    // -------------------------------------------------------------------------

    public static function run_stock_sync(): int {
        $api   = new Wupex_API();
        $page  = 1;
        $total = 0;

        Wupex_API::log( 'STOCK_SYNC', 'Starting stock sync' );

        do {
            $response = $api->get_products( $page, 100 );
            if ( ! $response['success'] ) {
                Wupex_API::log( 'STOCK_SYNC', 'API error: ' . $response['error'] );
                break;
            }

            $items = $response['data']['items']
                ?? $response['data']['products']
                ?? $response['data']['data']
                ?? $response['data']['result']
                ?? $response['data']['list']
                ?? [];

            if ( $page === 1 && empty( $items ) ) {
                Wupex_API::log( 'STOCK_SYNC', 'No items found. Raw: ' . substr( wp_json_encode( $response['data'] ), 0, 600 ) );
            }

            foreach ( $items as $item ) {
                $sku       = $item['productCode'] ?? '';
                $available = (int) ( $item['available'] ?? 0 );

                if ( empty( $sku ) ) {
                    continue;
                }

                global $wpdb;
                $product_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pm.post_id
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = '_wupex_sku'
                       AND pm.meta_value = %s
                       AND p.post_type = 'product'
                       AND p.post_status != 'trash'
                     LIMIT 1",
                    $sku
                ) );

                if ( ! $product_id ) {
                    continue;
                }

                $wc_product = wc_get_product( $product_id );
                if ( ! $wc_product ) {
                    continue;
                }

                $wc_product->set_stock_quantity( $available );
                $wc_product->set_stock_status( $available > 0 ? 'instock' : 'outofstock' );
                $wc_product->save();
                $total++;
            }

            $total_pages = (int) ( $response['data']['totalPage'] ?? $response['data']['pages'] ?? $response['data']['totalPages'] ?? 1 );
            $page++;
        } while ( $page <= $total_pages );

        Wupex_API::log( 'STOCK_SYNC', "Completed. Updated {$total} products." );
        return $total;
    }
}
