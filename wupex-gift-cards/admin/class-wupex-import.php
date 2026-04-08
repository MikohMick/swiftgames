<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Import {

    private const PER_PAGE = 20; // Products shown per admin page

    private string $last_fetch_error  = '';
    private array  $last_raw_response = [];
    private int    $total_api_pages   = 1;

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

        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $products     = $this->fetch_products_page( $current_page );
        $total_pages  = $this->total_api_pages;
        $markup       = (float) get_option( 'wupex_markup_percentage', 0 );

        // Build a type → imageUrl fallback map from products that have images
        $type_image_map = [];
        foreach ( $products as $p ) {
            $type = $p['productType'] ?? '';
            if ( ! empty( $type ) && ! empty( $p['imageUrl'] ) && ! isset( $type_image_map[ $type ] ) ) {
                $type_image_map[ $type ] = $p['imageUrl'];
            }
        }

        $base_url = admin_url( 'admin.php?page=wupex-import' );
        ?>
        <div class="wrap wupex-import-wrap">
            <h1><?php esc_html_e( 'Import Wupex Products', 'wupex-gift-cards' ); ?></h1>

            <p>
                <button type="button" id="wupex-sync-stock" class="button button-secondary">
                    <?php esc_html_e( 'Sync Stock', 'wupex-gift-cards' ); ?>
                </button>
                <span id="wupex-sync-result" style="margin-left:10px;"></span>
            </p>

            <?php if ( ! empty( $products ) ) : ?>

                <form id="wupex-import-form">
                    <?php wp_nonce_field( 'wupex_admin_nonce', 'wupex_nonce' ); ?>

                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                        <div>
                            <button type="button" id="wupex-import-selected" class="button button-primary">
                                <?php esc_html_e( 'Import Selected', 'wupex-gift-cards' ); ?>
                            </button>
                            <span style="margin-left:8px; color:#666; font-size:13px;">
                                <?php
                                printf(
                                    esc_html__( 'Page %1$d of %2$d', 'wupex-gift-cards' ),
                                    $current_page,
                                    $total_pages
                                );
                                ?>
                            </span>
                        </div>

                        <?php $this->render_pagination( $current_page, $total_pages, $base_url ); ?>
                    </div>

                    <table class="wp-list-table widefat fixed striped wupex-product-table">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="wupex-select-all" /></th>
                                <th style="width:70px;"><?php esc_html_e( 'Image', 'wupex-gift-cards' ); ?></th>
                                <th><?php esc_html_e( 'Product Name', 'wupex-gift-cards' ); ?></th>
                                <th style="width:160px;"><?php esc_html_e( 'SKU', 'wupex-gift-cards' ); ?></th>
                                <th style="width:110px;"><?php esc_html_e( 'Wupex Price', 'wupex-gift-cards' ); ?></th>
                                <th style="width:130px;"><?php esc_html_e( 'WC Price (+markup)', 'wupex-gift-cards' ); ?></th>
                                <th style="width:70px;"><?php esc_html_e( 'Stock', 'wupex-gift-cards' ); ?></th>
                                <th style="width:120px;"><?php esc_html_e( 'Type', 'wupex-gift-cards' ); ?></th>
                                <th style="width:110px;"><?php esc_html_e( 'Status', 'wupex-gift-cards' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $products as $product ) :
                                $wupex_price = (float) ( $product['retailPrice'] ?? $product['price'] ?? 0 );
                                $wc_price    = round( $wupex_price + ( $wupex_price * $markup / 100 ), 2 );
                                $sku          = $product['productCode'] ?? '';
                                $imported     = $this->is_already_imported( $sku );
                                $image_url    = $product['imageUrl'] ?? '';
                                $type         = $product['productType'] ?? '';
                                $fallback_url = ( empty( $image_url ) && isset( $type_image_map[ $type ] ) )
                                    ? $type_image_map[ $type ]
                                    : '';
                                $display_url  = ! empty( $image_url ) ? $image_url : $fallback_url;
                                $is_fallback  = empty( $image_url ) && ! empty( $fallback_url );
                            ?>
                            <tr>
                                <td class="check-column">
                                    <input type="checkbox" name="products[]"
                                           value="<?php echo esc_attr( wp_json_encode( $product ) ); ?>" />
                                </td>
                                <td>
                                    <?php if ( ! empty( $display_url ) ) : ?>
                                        <img src="<?php echo esc_url( $display_url ); ?>"
                                             width="50" height="50"
                                             style="object-fit:cover; border-radius:3px; <?php echo $is_fallback ? 'opacity:0.7;' : ''; ?>"
                                             title="<?php echo $is_fallback ? esc_attr__( 'Shared image from same product type', 'wupex-gift-cards' ) : ''; ?>" />
                                    <?php else : ?>
                                        <div class="wupex-no-image">
                                            <span>&#128247;</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $product['productName'] ?? '' ); ?></td>
                                <td><code><?php echo esc_html( $sku ); ?></code></td>
                                <td><?php echo wp_kses_post( wc_price( $wupex_price ) ); ?></td>
                                <td><?php echo wp_kses_post( wc_price( $wc_price ) ); ?></td>
                                <td><?php echo esc_html( $product['available'] ?? 0 ); ?></td>
                                <td><?php echo esc_html( $product['productType'] ?? '' ); ?></td>
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
                    </table>

                    <div style="margin-top:12px; display:flex; justify-content:flex-end;">
                        <?php $this->render_pagination( $current_page, $total_pages, $base_url ); ?>
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

    private function render_pagination( int $current, int $total, string $base_url ): void {
        if ( $total <= 1 ) {
            return;
        }
        echo '<div class="tablenav-pages" style="display:flex; align-items:center; gap:6px;">';

        if ( $current > 1 ) {
            printf(
                '<a href="%s" class="button button-secondary">&laquo; %s</a>',
                esc_url( add_query_arg( 'paged', $current - 1, $base_url ) ),
                esc_html__( 'Previous', 'wupex-gift-cards' )
            );
        }

        // Show a window of pages around current
        $start = max( 1, $current - 2 );
        $end   = min( $total, $current + 2 );

        if ( $start > 1 ) {
            printf( '<a href="%s" class="button">1</a>', esc_url( add_query_arg( 'paged', 1, $base_url ) ) );
            if ( $start > 2 ) {
                echo '<span style="padding:0 4px;">…</span>';
            }
        }

        for ( $i = $start; $i <= $end; $i++ ) {
            if ( $i === $current ) {
                echo '<span class="button button-primary" style="cursor:default;">' . esc_html( $i ) . '</span>';
            } else {
                printf(
                    '<a href="%s" class="button">%d</a>',
                    esc_url( add_query_arg( 'paged', $i, $base_url ) ),
                    esc_html( $i )
                );
            }
        }

        if ( $end < $total ) {
            if ( $end < $total - 1 ) {
                echo '<span style="padding:0 4px;">…</span>';
            }
            printf( '<a href="%s" class="button">%d</a>', esc_url( add_query_arg( 'paged', $total, $base_url ) ), esc_html( $total ) );
        }

        if ( $current < $total ) {
            printf(
                '<a href="%s" class="button button-secondary">%s &raquo;</a>',
                esc_url( add_query_arg( 'paged', $current + 1, $base_url ) ),
                esc_html__( 'Next', 'wupex-gift-cards' )
            );
        }

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Product fetch — one API page at a time
    // -------------------------------------------------------------------------

    private function fetch_products_page( int $admin_page ): array {
        $api      = new Wupex_API();
        $results  = [];

        // Fetch in batches of 100 from Wupex until we have PER_PAGE in-stock items
        // for the requested admin page. API page may differ from admin page.
        $skip    = ( $admin_page - 1 ) * self::PER_PAGE; // in-stock items to skip
        $api_page = 1;
        $found    = 0;

        // We need to figure out total pages first, so run one call and track
        do {
            $response = $api->get_products( $api_page, 100 );
            $this->last_raw_response = $response['data'] ?? [];

            if ( ! $response['success'] ) {
                $this->last_fetch_error = $response['error'] ?? 'Unknown API error';
                Wupex_API::log( 'IMPORT_FETCH', 'API error: ' . $this->last_fetch_error );
                return [];
            }

            $items = $response['data']['items']
                ?? $response['data']['products']
                ?? $response['data']['data']
                ?? $response['data']['result']
                ?? $response['data']['list']
                ?? ( is_array( $response['data'] ) && isset( $response['data'][0] ) ? $response['data'] : [] );

            if ( $api_page === 1 && empty( $items ) ) {
                Wupex_API::log( 'IMPORT_FETCH', 'No items. Response keys: ' . implode( ', ', array_keys( $response['data'] ) ) );
            }

            $total_api_pages = (int) ( $response['data']['totalPage'] ?? $response['data']['pages'] ?? $response['data']['totalPages'] ?? 1 );

            foreach ( $items as $item ) {
                if ( (int) ( $item['available'] ?? 0 ) <= 0 ) {
                    continue;
                }

                // Skip items before our page window
                if ( $found < $skip ) {
                    $found++;
                    continue;
                }

                $results[] = $item;
                $found++;

                if ( count( $results ) >= self::PER_PAGE ) {
                    // Estimate total admin pages based on remaining API pages
                    $in_stock_so_far  = $found;
                    $api_pages_left   = $total_api_pages - $api_page;
                    // Rough estimate: use current in-stock rate to project total
                    $rate             = $found > 0 ? ( count( $items ) > 0 ? ( $found / ( ( $api_page * 100 ) ) ) : 0.5 ) : 0.5;
                    $est_total        = (int) ceil( $total_api_pages * 100 * $rate );
                    $this->total_api_pages = max( $admin_page, (int) ceil( $est_total / self::PER_PAGE ) );
                    return $results;
                }
            }

            $api_page++;
        } while ( $api_page <= $total_api_pages );

        // Reached the end — calculate total admin pages from what we found
        $total_in_stock        = $found;
        $this->total_api_pages = max( 1, (int) ceil( $total_in_stock / self::PER_PAGE ) );

        if ( empty( $results ) && $found === 0 ) {
            $this->last_fetch_error = 'No in-stock products found across all API pages.';
        }

        return $results;
    }

    private function is_already_imported( string $sku ): bool {
        if ( empty( $sku ) ) {
            return false;
        }
        $query = new WC_Product_Query( [
            'post_type'  => 'product',
            'meta_query' => [ [ 'key' => '_wupex_sku', 'value' => $sku, 'compare' => '=' ] ],
            'fields'     => 'ids',
            'limit'      => 1,
        ] );
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
        $imported = $skipped = $failed = 0;

        // Build type → imageUrl map from the batch so we can fill gaps
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

            $total_pages = (int) ( $response['data']['totalPage'] ?? $response['data']['pages'] ?? $response['data']['totalPages'] ?? 1 );
            $page++;
        } while ( $page <= $total_pages );

        Wupex_API::log( 'STOCK_SYNC', "Completed. Updated {$total} products." );
        return $total;
    }
}
