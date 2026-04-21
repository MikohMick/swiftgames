<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Import {

    private const PER_PAGE          = 20;
    private const CACHE_KEY         = 'wupex_product_cache';
    private const CACHE_EXPIRY      = 300;           // 5 minutes (filtered view)
    private const ALL_CACHE_KEY     = 'wupex_all_product_cache';
    private const ALL_CACHE_EXPIRY  = DAY_IN_SECONDS; // 24 hours (full catalog)
    private const TYPE_CACHE_KEY    = 'wupex_type_cache';
    private const TYPE_CACHE_EXPIRY = DAY_IN_SECONDS; // 24 hours

    private string $last_fetch_error = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
        add_action( 'wp_ajax_wupex_import_products',   [ $this, 'ajax_import_products' ] );
        add_action( 'wp_ajax_wupex_sync_stock',        [ $this, 'ajax_sync_stock' ] );
        add_action( 'wp_ajax_wupex_refresh_products',  [ $this, 'ajax_refresh_products' ] );
        add_action( 'wp_ajax_wupex_fetch_types',       [ $this, 'ajax_fetch_types' ] );
        add_action( 'wp_ajax_wupex_save_type_filter',  [ $this, 'ajax_save_type_filter' ] );
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

        $cached_types = get_transient( self::TYPE_CACHE_KEY );
        $saved_types  = (array) get_option( 'wupex_allowed_types', [] );
        $filter_ready = ! empty( $saved_types ); // only show products when a filter is chosen
        ?>
        <div class="wrap wupex-import-wrap">
            <h1><?php esc_html_e( 'Import Wupex Products', 'wupex-gift-cards' ); ?></h1>

            <?php if ( ! $cached_types ) : ?>
            <!-- ── STEP 1: No types loaded yet ── -->
            <div class="wupex-setup-card">
                <div class="wupex-setup-body">
                    <h2><?php esc_html_e( 'Load Product Categories', 'wupex-gift-cards' ); ?></h2>
                    <p><?php esc_html_e( 'First, fetch all available product categories from Wupex. This takes about 30 seconds and is cached for 24 hours.', 'wupex-gift-cards' ); ?></p>
                    <button type="button" id="wupex-load-types" class="button button-primary button-large">
                        <?php esc_html_e( 'Load Categories', 'wupex-gift-cards' ); ?>
                    </button>
                    <span id="wupex-types-loading" style="display:none; margin-left:12px;">
                        <span class="spinner is-active" style="float:none; margin:0; vertical-align:middle;"></span>
                        <em><?php esc_html_e( 'Fetching… please wait up to 30 seconds.', 'wupex-gift-cards' ); ?></em>
                    </span>
                </div>
            </div>

            <?php elseif ( ! $filter_ready ) : ?>
            <!-- ── STEP 2: Types loaded, pick categories ── -->
            <div class="wupex-setup-card">
                <div class="wupex-setup-body">
                    <h2><?php esc_html_e( 'Select Categories to Import', 'wupex-gift-cards' ); ?></h2>
                    <p><?php printf(
                        esc_html__( '%d categories found. Tick the ones you want, then click Save.', 'wupex-gift-cards' ),
                        count( $cached_types )
                    ); ?></p>

                    <input type="text" id="wupex-type-search"
                           class="wupex-type-search"
                           placeholder="<?php esc_attr_e( 'Search categories…', 'wupex-gift-cards' ); ?>" />

                    <div class="wupex-type-grid">
                        <label class="wupex-type-select-all">
                            <input type="checkbox" id="wupex-toggle-all-types" />
                            <strong><?php esc_html_e( 'Select All / None', 'wupex-gift-cards' ); ?></strong>
                        </label>
                        <?php foreach ( $cached_types as $type => $count ) : ?>
                        <label class="wupex-type-item">
                            <input type="checkbox" class="wupex-type-check"
                                   value="<?php echo esc_attr( $type ); ?>" />
                            <?php echo esc_html( $type ); ?>
                            <span class="wupex-type-count">(<?php echo number_format_i18n( $count ); ?>)</span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <p style="margin-top:14px;">
                        <button type="button" id="wupex-save-types" class="button button-primary button-large">
                            <?php esc_html_e( 'Save & Show Products', 'wupex-gift-cards' ); ?>
                        </button>
                        <span id="wupex-save-types-result" style="margin-left:12px;"></span>
                    </p>
                    <p style="margin-top:6px;">
                        <button type="button" id="wupex-load-types" class="button button-link">
                            <?php esc_html_e( 'Refresh category list', 'wupex-gift-cards' ); ?>
                        </button>
                        <span id="wupex-types-loading" style="display:none; margin-left:8px;">
                            <span class="spinner is-active" style="float:none; margin:0; vertical-align:middle;"></span>
                        </span>
                    </p>
                </div>
            </div>

            <?php else :
                // ── STEP 3: Filter saved — load and show products ──
                $current_page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
                $all_products   = $this->get_cached_products();
                $total          = count( $all_products );
                $total_pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
                $current_page   = min( $current_page, $total_pages );
                $offset         = ( $current_page - 1 ) * self::PER_PAGE;
                $products       = array_slice( $all_products, $offset, self::PER_PAGE );
                $markup         = (float) get_option( 'wupex_markup_percentage', 0 );
                $base_url       = admin_url( 'admin.php?page=wupex-import' );

                $type_image_map = [];
                foreach ( $all_products as $p ) {
                    $type = $p['productType'] ?? '';
                    if ( ! empty( $type ) && ! empty( $p['imageUrl'] ) && ! isset( $type_image_map[ $type ] ) ) {
                        $type_image_map[ $type ] = $p['imageUrl'];
                    }
                }
            ?>

            <!-- ── Active filter bar ── -->
            <div class="wupex-filter-bar">
                <span class="wupex-filter-bar-label">
                    <?php esc_html_e( 'Active filter:', 'wupex-gift-cards' ); ?>
                    <strong><?php echo esc_html( implode( ', ', $saved_types ) ); ?></strong>
                </span>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <button type="button" id="wupex-change-filter" class="button button-secondary button-small">
                        <?php esc_html_e( 'Change Categories', 'wupex-gift-cards' ); ?>
                    </button>
                    <button type="button" id="wupex-sync-stock" class="button button-secondary button-small">
                        <?php esc_html_e( 'Sync Stock', 'wupex-gift-cards' ); ?>
                    </button>
                    <button type="button" id="wupex-refresh-products" class="button button-secondary button-small">
                        <?php esc_html_e( 'Refresh List', 'wupex-gift-cards' ); ?>
                    </button>
                    <span id="wupex-sync-result"></span>
                </div>
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
            <?php endif; // inner: products if/elseif/else ?>
        <?php endif; // outer: step 1 / step 2 / step 3 ?>
        </div><!-- .wupex-import-wrap -->
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
    // Response helpers
    // -------------------------------------------------------------------------

    private function extract_items( array $body ): array {
        // Production: {"pageInfo":{...},"data":[...],"status":true}
        if ( isset( $body['data'] ) && is_array( $body['data'] ) && isset( $body['data'][0] ) ) {
            return $body['data'];
        }
        // Sandbox / nested: {"data":{"items":[...],...},"status":true}
        return $body['data']['items']
            ?? $body['data']['products']
            ?? $body['data']['list']
            ?? [];
    }

    private function extract_total_pages( array $body ): int {
        return (int) (
            $body['pageInfo']['totalPage']
            ?? $body['data']['totalPage']
            ?? $body['data']['pages']
            ?? $body['data']['totalPages']
            ?? 1
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: Fetch all product types
    // -------------------------------------------------------------------------

    public function ajax_fetch_types(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        @set_time_limit( 0 );

        // Fetch the full catalog using the working code path (pageSize=100, no filter)
        delete_transient( self::ALL_CACHE_KEY );
        $all = $this->fetch_all_products( false );

        if ( empty( $all ) ) {
            $err = $this->last_fetch_error ?: __( 'No products returned from API.', 'wupex-gift-cards' );
            wp_send_json_error( [ 'message' => $err ] );
            return;
        }

        // Derive types from the fetched products
        $types = [];
        foreach ( $all as $item ) {
            $type = trim( $item['productType'] ?? '' );
            if ( $type !== '' ) {
                $types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
            }
        }
        ksort( $types );

        set_transient( self::TYPE_CACHE_KEY, $types, self::TYPE_CACHE_EXPIRY );
        Wupex_API::log( 'TYPE_FETCH', 'Discovered ' . count( $types ) . ' types from ' . count( $all ) . ' products.' );

        wp_send_json_success( [ 'count' => count( $types ) ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Save type filter
    // -------------------------------------------------------------------------

    public function ajax_save_type_filter(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        $selected = isset( $_POST['types'] )
            ? array_values( array_filter( array_map( 'sanitize_text_field', (array) $_POST['types'] ) ) )
            : [];

        update_option( 'wupex_allowed_types', $selected );
        delete_transient( self::CACHE_KEY );

        wp_send_json_success( [
            'message' => count( $selected ) > 0
                ? sprintf( __( '%d type(s) saved.', 'wupex-gift-cards' ), count( $selected ) )
                : __( 'Filter cleared.', 'wupex-gift-cards' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Product cache — fetch filtered products, cache for 5 min
    // -------------------------------------------------------------------------

    private function get_cached_products(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) {
            return $cached;
        }

        $allowed_types = (array) get_option( 'wupex_allowed_types', [] );

        // Prefer filtering from the full catalog cache (no extra API call)
        $all = get_transient( self::ALL_CACHE_KEY );
        if ( $all !== false ) {
            $filtered = empty( $allowed_types )
                ? $all
                : array_values( array_filter( $all, function ( $item ) use ( $allowed_types ) {
                    return in_array( trim( $item['productType'] ?? '' ), $allowed_types, true );
                } ) );
            set_transient( self::CACHE_KEY, $filtered, self::CACHE_EXPIRY );
            return $filtered;
        }

        // Fallback: fetch from API with filter applied
        return $this->fetch_all_products( true );
    }

    /**
     * Fetch all products from the API.
     * $apply_type_filter=false  → full catalog, cached in ALL_CACHE_KEY (24 h)
     * $apply_type_filter=true   → filtered by wupex_allowed_types, cached in CACHE_KEY (5 min)
     */
    private function fetch_all_products( bool $apply_type_filter = true ): array {
        $cache_key    = $apply_type_filter ? self::CACHE_KEY    : self::ALL_CACHE_KEY;
        $cache_expiry = $apply_type_filter ? self::CACHE_EXPIRY : self::ALL_CACHE_EXPIRY;

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $api           = new Wupex_API();
        $results       = [];
        $page          = 1;
        $allowed_types = $apply_type_filter ? (array) get_option( 'wupex_allowed_types', [] ) : [];

        do {
            $response = $api->get_products( $page, 100 );

            if ( ! $response['success'] ) {
                $this->last_fetch_error = $response['error'] ?? 'Unknown API error';
                Wupex_API::log( 'IMPORT_FETCH', 'API error page ' . $page . ': ' . $this->last_fetch_error );
                break;
            }

            $body  = $response['data'];
            $items = $this->extract_items( $body );

            if ( $page === 1 && empty( $items ) ) {
                Wupex_API::log( 'IMPORT_FETCH', 'Page 1 empty. Body: ' . substr( wp_json_encode( $body ), 0, 400 ) );
            }

            foreach ( $items as $item ) {
                if ( ! ( $item['enabled'] ?? true ) ) {
                    continue;
                }
                if ( ! empty( $allowed_types ) && ! in_array( trim( $item['productType'] ?? '' ), $allowed_types, true ) ) {
                    continue;
                }
                $results[] = $item;
            }

            $total_pages = $this->extract_total_pages( $body );
            $page++;
        } while ( $page <= $total_pages );

        if ( ! empty( $results ) ) {
            set_transient( $cache_key, $results, $cache_expiry );
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
        delete_transient( self::ALL_CACHE_KEY );
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
        $product->set_manage_stock( false );  // pull-on-demand — always in stock
        $product->set_stock_status( 'instock' );

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

            $body  = $response['data'];
            $items = ( new self() )->extract_items( $body );

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

                $wc_product->set_manage_stock( false );
                $wc_product->set_stock_status( 'instock' );
                $wc_product->save();
                $total++;
            }

            $total_pages = ( new self() )->extract_total_pages( $body );
            $page++;
        } while ( $page <= $total_pages );

        Wupex_API::log( 'STOCK_SYNC', "Completed. Updated {$total} products." );
        return $total;
    }
}
