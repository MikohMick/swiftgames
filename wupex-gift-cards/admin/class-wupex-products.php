<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wupex_Products {

    private const PER_PAGE = 30;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
        add_action( 'wp_ajax_wupex_update_product_price', [ $this, 'ajax_update_product_price' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Wupex Products', 'wupex-gift-cards' ),
            __( 'Wupex Products', 'wupex-gift-cards' ),
            'manage_woocommerce',
            'wupex-products',
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

        $search       = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $all_products = $this->get_imported_products( $search );
        $total        = count( $all_products );
        $total_pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $current_page = min( $current_page, $total_pages );
        $offset       = ( $current_page - 1 ) * self::PER_PAGE;
        $products     = array_slice( $all_products, $offset, self::PER_PAGE );
        $base_url     = admin_url( 'admin.php?page=wupex-products' . ( $search !== '' ? '&s=' . rawurlencode( $search ) : '' ) );
        ?>
        <div class="wrap wupex-products-wrap">
            <h1><?php esc_html_e( 'Wupex Imported Products', 'wupex-gift-cards' ); ?></h1>

            <div class="wupex-filter-bar" style="margin-bottom:16px;">
                <form method="get" action="" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                    <input type="hidden" name="page" value="wupex-products" />
                    <input type="search" name="s"
                           class="wupex-product-search"
                           value="<?php echo esc_attr( $search ); ?>"
                           placeholder="<?php esc_attr_e( 'Search by name or SKU…', 'wupex-gift-cards' ); ?>" />
                    <button type="submit" class="button"><?php esc_html_e( 'Search', 'wupex-gift-cards' ); ?></button>
                    <?php if ( $search !== '' ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wupex-products' ) ); ?>" class="button button-link">
                            <?php esc_html_e( 'Clear', 'wupex-gift-cards' ); ?>
                        </a>
                    <?php endif; ?>
                </form>
                <span style="font-size:13px; color:#50575e;">
                    <?php printf(
                        esc_html( _n( '%s product imported', '%s products imported', $total, 'wupex-gift-cards' ) ),
                        number_format_i18n( $total )
                    ); ?>
                </span>
            </div>

            <?php wp_nonce_field( 'wupex_admin_nonce', 'wupex_products_nonce' ); ?>

            <?php if ( empty( $all_products ) ) : ?>
                <div class="notice notice-info"><p>
                    <?php if ( $search !== '' ) : ?>
                        <?php esc_html_e( 'No products match your search.', 'wupex-gift-cards' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'No Wupex products have been imported yet. Use the Import Products page to get started.', 'wupex-gift-cards' ); ?>
                    <?php endif; ?>
                </p></div>

            <?php else : ?>

                <div class="tablenav top">
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

                <table class="wp-list-table widefat fixed striped wupex-products-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Product', 'wupex-gift-cards' ); ?></th>
                            <th style="width:150px;"><?php esc_html_e( 'SKU', 'wupex-gift-cards' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( 'Wupex Price', 'wupex-gift-cards' ); ?></th>
                            <th style="width:90px;" title="<?php esc_attr_e( 'Discount vs face/retail value', 'wupex-gift-cards' ); ?>"><?php esc_html_e( 'Discount', 'wupex-gift-cards' ); ?></th>
                            <th style="width:150px;"><?php esc_html_e( 'WC Price', 'wupex-gift-cards' ); ?></th>
                            <th style="width:85px;" title="<?php esc_attr_e( 'Profit margin over Wupex cost', 'wupex-gift-cards' ); ?>"><?php esc_html_e( '% Profit', 'wupex-gift-cards' ); ?></th>
                            <th style="width:130px;"><?php esc_html_e( 'Actions', 'wupex-gift-cards' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $products as $row ) :
                            $wupex_price  = $row['wupex_price'];
                            $wc_price     = $row['wc_price'];
                            $face_value   = $row['face_value'];
                            $discount_pct = ( $face_value > 0 && $wupex_price < $face_value )
                                            ? round( ( $face_value - $wupex_price ) / $face_value * 100, 1 )
                                            : null;
                            $profit_pct   = $wupex_price > 0
                                            ? round( ( $wc_price - $wupex_price ) / $wupex_price * 100, 1 )
                                            : 0;
                            $product_id   = $row['id'];
                        ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>">
                                        <?php echo esc_html( $row['name'] ); ?>
                                    </a>
                                </strong>
                            </td>
                            <td><code><?php echo esc_html( $row['sku'] ); ?></code></td>
                            <td><?php echo wp_kses_post( wc_price( $wupex_price ) ); ?></td>
                            <td>
                                <?php if ( $discount_pct !== null ) : ?>
                                    <span class="wupex-discount-badge"><?php echo esc_html( $discount_pct ); ?>% off</span>
                                <?php else : ?>
                                    <span class="wupex-na">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="number"
                                       class="wupex-product-price-input"
                                       data-product-id="<?php echo esc_attr( $product_id ); ?>"
                                       data-wupex-price="<?php echo esc_attr( $wupex_price ); ?>"
                                       value="<?php echo esc_attr( $wc_price ); ?>"
                                       min="0" step="0.01"
                                       style="width:95px;" />
                            </td>
                            <td class="wupex-product-profit-cell" data-product-id="<?php echo esc_attr( $product_id ); ?>">
                                <span class="<?php echo $profit_pct >= 0 ? 'wupex-profit-positive' : 'wupex-profit-negative'; ?>">
                                    <?php echo esc_html( $profit_pct ); ?>%
                                </span>
                            </td>
                            <td>
                                <button type="button"
                                        class="button button-small wupex-update-product-price"
                                        data-product-id="<?php echo esc_attr( $product_id ); ?>">
                                    <?php esc_html_e( 'Update', 'wupex-gift-cards' ); ?>
                                </button>
                                <span class="wupex-update-result" data-product-id="<?php echo esc_attr( $product_id ); ?>"></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th><?php esc_html_e( 'Product', 'wupex-gift-cards' ); ?></th>
                            <th><?php esc_html_e( 'SKU', 'wupex-gift-cards' ); ?></th>
                            <th><?php esc_html_e( 'Wupex Price', 'wupex-gift-cards' ); ?></th>
                            <th><?php esc_html_e( 'Discount', 'wupex-gift-cards' ); ?></th>
                            <th><?php esc_html_e( 'WC Price', 'wupex-gift-cards' ); ?></th>
                            <th><?php esc_html_e( '% Profit', 'wupex-gift-cards' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'wupex-gift-cards' ); ?></th>
                        </tr>
                    </tfoot>
                </table>

                <div class="tablenav bottom">
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

            <?php endif; ?>
        </div><!-- .wupex-products-wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // Data retrieval
    // -------------------------------------------------------------------------

    private function get_imported_products( string $search = '' ): array {
        global $wpdb;

        $sql = "SELECT DISTINCT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_wupex_sku'
                  AND p.post_type = 'product'
                  AND p.post_status != 'trash'
                ORDER BY p.post_title ASC";

        $product_ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $products = [];
        foreach ( $product_ids as $product_id ) {
            $wc_product = wc_get_product( $product_id );
            if ( ! $wc_product ) {
                continue;
            }

            $name        = $wc_product->get_name();
            $sku         = (string) get_post_meta( $product_id, '_wupex_sku', true );
            $wupex_price = (float) get_post_meta( $product_id, '_wupex_price', true );
            $wc_price    = (float) $wc_product->get_regular_price();

            // Try to extract face value from stored meta first, then from product name
            $face_value = (float) get_post_meta( $product_id, '_wupex_face_value', true );
            if ( $face_value <= 0 ) {
                if ( preg_match( '/\$\s*([\d,]+(?:\.\d+)?)/', $name, $m ) ) {
                    $face_value = (float) str_replace( ',', '', $m[1] );
                }
            }

            if ( $search !== '' ) {
                $lc = strtolower( $search );
                if ( ! str_contains( strtolower( $name ), $lc ) && ! str_contains( strtolower( $sku ), $lc ) ) {
                    continue;
                }
            }

            $products[] = [
                'id'          => (int) $product_id,
                'name'        => $name,
                'sku'         => $sku,
                'wupex_price' => $wupex_price,
                'wc_price'    => $wc_price,
                'face_value'  => $face_value,
            ];
        }

        return $products;
    }

    // -------------------------------------------------------------------------
    // Pagination
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
    // AJAX: Update product price
    // -------------------------------------------------------------------------

    public function ajax_update_product_price(): void {
        check_ajax_referer( 'wupex_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wupex-gift-cards' ) ] );
        }

        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $new_price  = isset( $_POST['price'] ) ? (float) $_POST['price'] : -1;

        if ( ! $product_id || $new_price < 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid data.', 'wupex-gift-cards' ) ] );
        }

        $wc_product = wc_get_product( $product_id );
        if ( ! $wc_product ) {
            wp_send_json_error( [ 'message' => __( 'Product not found.', 'wupex-gift-cards' ) ] );
        }

        // Verify it's actually a Wupex product
        if ( ! get_post_meta( $product_id, '_wupex_sku', true ) ) {
            wp_send_json_error( [ 'message' => __( 'Not a Wupex product.', 'wupex-gift-cards' ) ] );
        }

        $wc_product->set_regular_price( (string) round( $new_price, 2 ) );
        $wc_product->save();

        Wupex_API::log( 'PRODUCTS', "Updated price for product #{$product_id} to {$new_price}" );

        wp_send_json_success( [
            'message' => __( 'Price updated.', 'wupex-gift-cards' ),
            'price'   => $new_price,
        ] );
    }
}
