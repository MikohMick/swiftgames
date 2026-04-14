<?php
/**
 * Reveal page template.
 * Variables available: $code (array), $pin (string), $order (WC_Order)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$copy_label   = esc_js( __( 'Copy Code', 'wupex-gift-cards' ) );
$copied_label = esc_js( __( 'Copied!', 'wupex-gift-cards' ) );
?>
<div class="wupex-reveal-page">
    <div class="wupex-reveal-card">

        <div class="wupex-reveal-header">
            <span class="wupex-reveal-icon">&#127873;</span>
            <h2><?php esc_html_e( 'Your Gift Card Code', 'wupex-gift-cards' ); ?></h2>
            <p class="wupex-reveal-tagline"><?php esc_html_e( 'Ready to redeem', 'wupex-gift-cards' ); ?></p>
        </div>

        <div class="wupex-reveal-body">

            <p class="wupex-product-name"><?php echo esc_html( $code['product_name'] ); ?></p>

            <div class="wupex-code-wrap">
                <span class="wupex-code-label"><?php esc_html_e( 'Your Code', 'wupex-gift-cards' ); ?></span>
                <span id="wupex-pin-code" class="wupex-pin"><?php echo esc_html( $pin ); ?></span>
                <?php if ( ! empty( $code['serial_number'] ) ) : ?>
                    <span class="wupex-serial-number">
                        <?php
                        printf(
                            /* translators: %s: serial number */
                            esc_html__( 'Serial: %s', 'wupex-gift-cards' ),
                            esc_html( $code['serial_number'] )
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </div>

            <button type="button" id="wupex-copy-btn" class="wupex-copy-btn" data-code="<?php echo esc_attr( $pin ); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                </svg>
                <span class="wupex-copy-label"><?php esc_html_e( 'Copy Code', 'wupex-gift-cards' ); ?></span>
            </button>

            <?php if ( $order || ! empty( $code['expiry'] ) ) : ?>
            <div class="wupex-details-grid">

                <?php if ( $order ) : ?>
                <div class="wupex-detail-item">
                    <div class="wupex-detail-label"><?php esc_html_e( 'Order', 'wupex-gift-cards' ); ?></div>
                    <div class="wupex-detail-value">#<?php echo esc_html( $order->get_order_number() ); ?></div>
                </div>
                <div class="wupex-detail-item">
                    <div class="wupex-detail-label"><?php esc_html_e( 'Order Date', 'wupex-gift-cards' ); ?></div>
                    <div class="wupex-detail-value"><?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?></div>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $code['expiry'] ) ) : ?>
                <div class="wupex-detail-item wupex-detail-full">
                    <div class="wupex-detail-label"><?php esc_html_e( 'Code Expires', 'wupex-gift-cards' ); ?></div>
                    <div class="wupex-detail-value"><?php echo esc_html( $code['expiry'] ); ?></div>
                </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

            <p class="wupex-reveal-note">
                <?php esc_html_e( 'Keep this code safe. You can return to this page anytime using the link in your order confirmation email.', 'wupex-gift-cards' ); ?>
            </p>

        </div><!-- .wupex-reveal-body -->
    </div><!-- .wupex-reveal-card -->
</div><!-- .wupex-reveal-page -->

<script>
(function () {
    var btn       = document.getElementById('wupex-copy-btn');
    var labelEl   = btn ? btn.querySelector('.wupex-copy-label') : null;
    var svgCopy   = btn ? btn.querySelector('svg') : null;
    if (!btn) return;

    var svgCheck = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    var svgOrig  = svgCopy ? svgCopy.outerHTML : '';
    var timer;

    btn.addEventListener('click', function () {
        var code = btn.getAttribute('data-code');
        clearTimeout(timer);

        function onCopied() {
            btn.classList.add('wupex-copied');
            btn.innerHTML = svgCheck + '<span class="wupex-copy-label"><?php echo $copied_label; ?></span>';
            timer = setTimeout(function () {
                btn.classList.remove('wupex-copied');
                btn.innerHTML = svgOrig + '<span class="wupex-copy-label"><?php echo $copy_label; ?></span>';
            }, 2200);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(onCopied).catch(function () {
                fallbackCopy(code);
                onCopied();
            });
        } else {
            fallbackCopy(code);
            onCopied();
        }
    });

    function fallbackCopy(text) {
        var el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.opacity  = '0';
        document.body.appendChild(el);
        el.focus();
        el.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(el);
    }
})();
</script>
