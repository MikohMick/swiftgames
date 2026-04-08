<?php
/**
 * Reveal page template.
 * Variables available: $code (array), $pin (string), $order (WC_Order)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wupex-reveal-wrap">
    <div class="wupex-reveal-card">
        <div class="wupex-reveal-header">
            <span class="wupex-reveal-icon">&#127873;</span>
            <h2><?php esc_html_e( 'Your Gift Card Code', 'wupex-gift-cards' ); ?></h2>
        </div>

        <div class="wupex-reveal-body">
            <p class="wupex-product-name"><?php echo esc_html( $code['product_name'] ); ?></p>

            <div class="wupex-code-box">
                <span id="wupex-pin-code" class="wupex-pin"><?php echo esc_html( $pin ); ?></span>
                <button type="button" id="wupex-copy-btn" class="wupex-copy-btn" data-code="<?php echo esc_attr( $pin ); ?>">
                    <?php esc_html_e( 'Copy', 'wupex-gift-cards' ); ?>
                </button>
            </div>

            <div class="wupex-meta-info">
                <?php if ( $order ) : ?>
                    <p>
                        <strong><?php esc_html_e( 'Order:', 'wupex-gift-cards' ); ?></strong>
                        #<?php echo esc_html( $order->get_order_number() ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Order Date:', 'wupex-gift-cards' ); ?></strong>
                        <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?>
                    </p>
                <?php endif; ?>

                <?php if ( ! empty( $code['expiry'] ) ) : ?>
                    <p>
                        <strong><?php esc_html_e( 'Code Expires:', 'wupex-gift-cards' ); ?></strong>
                        <?php echo esc_html( $code['expiry'] ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <p class="wupex-reveal-note">
                <?php esc_html_e( 'Keep this code safe. You can return to this page using your original email link at any time before it expires.', 'wupex-gift-cards' ); ?>
            </p>
        </div>
    </div>
</div>

<script>
(function() {
    var btn = document.getElementById('wupex-copy-btn');
    if (!btn) return;
    btn.addEventListener('click', function() {
        var code = this.getAttribute('data-code');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(function() {
                btn.textContent = '<?php echo esc_js( __( 'Copied!', 'wupex-gift-cards' ) ); ?>';
                setTimeout(function(){ btn.textContent = '<?php echo esc_js( __( 'Copy', 'wupex-gift-cards' ) ); ?>'; }, 2000);
            });
        } else {
            // Fallback
            var el = document.createElement('textarea');
            el.value = code;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            btn.textContent = '<?php echo esc_js( __( 'Copied!', 'wupex-gift-cards' ) ); ?>';
            setTimeout(function(){ btn.textContent = '<?php echo esc_js( __( 'Copy', 'wupex-gift-cards' ) ); ?>'; }, 2000);
        }
    });
})();
</script>
