<?php
/**
 * Email template: Code Ready Notification
 * Variables available: $order (WC_Order), $codes (array), $reveal_url (string)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$site_name     = get_bloginfo( 'name' );
$logo_url      = get_option( 'woocommerce_email_header_image', get_site_icon_url( 60 ) );
$first_name    = $order->get_billing_first_name();
$primary_color = get_option( 'woocommerce_email_base_color', '#7c3aed' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo esc_html( $site_name ); ?></title>
    <style>
        body { margin: 0; padding: 0; background: #f7f7f7; font-family: Arial, sans-serif; color: #333; }
        .email-wrap { max-width: 600px; margin: 40px auto; background: #fff; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; }
        .email-header { background: <?php echo esc_attr( $primary_color ); ?>; padding: 30px 40px; text-align: center; }
        .email-header h1 { color: #fff; margin: 0; font-size: 24px; }
        .email-body { padding: 40px; }
        .email-body p { line-height: 1.6; margin: 0 0 16px; }
        .btn-wrap { text-align: center; margin: 30px 0; }
        .btn-reveal { display: inline-block; background: <?php echo esc_attr( $primary_color ); ?>; color: #fff !important; text-decoration: none; padding: 14px 36px; border-radius: 4px; font-size: 16px; font-weight: bold; }
        .order-info { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; padding: 16px; margin: 20px 0; font-size: 14px; }
        .email-footer { background: #f7f7f7; border-top: 1px solid #e0e0e0; padding: 20px 40px; text-align: center; font-size: 12px; color: #888; }
    </style>
</head>
<body>
<div class="email-wrap">
    <div class="email-header">
        <?php if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" style="max-width:200px; height:auto; display:block; margin:0 auto 12px;" />
        <?php endif; ?>
        <h1><?php echo esc_html( $site_name ); ?></h1>
    </div>

    <div class="email-body">
        <p><?php
            printf(
                /* translators: %s: customer first name */
                esc_html__( 'Hi %s,', 'wupex-gift-cards' ),
                esc_html( $first_name )
            );
        ?></p>

        <p><?php esc_html_e( 'Thank you for your order! Your gift card code is ready to be revealed.', 'wupex-gift-cards' ); ?></p>

        <p><?php esc_html_e( 'Click the button below to securely reveal your unique PIN code. Your link is valid for a limited time, so please reveal it soon.', 'wupex-gift-cards' ); ?></p>

        <div class="btn-wrap">
            <a href="<?php echo esc_url( $reveal_url ); ?>" class="btn-reveal">
                <?php esc_html_e( 'Reveal My Key', 'wupex-gift-cards' ); ?>
            </a>
        </div>

        <div class="order-info">
            <strong><?php esc_html_e( 'Order:', 'wupex-gift-cards' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?><br />
            <strong><?php esc_html_e( 'Date:', 'wupex-gift-cards' ); ?></strong> <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?><br />
        </div>

        <p style="font-size:13px; color:#888;">
            <?php esc_html_e( 'If the button does not work, copy and paste this link into your browser:', 'wupex-gift-cards' ); ?><br />
            <a href="<?php echo esc_url( $reveal_url ); ?>" style="word-break:break-all;"><?php echo esc_url( $reveal_url ); ?></a>
        </p>

        <p><?php esc_html_e( 'If you have any questions or need help, please contact our support team.', 'wupex-gift-cards' ); ?></p>

        <p><?php esc_html_e( 'Thank you for shopping with us!', 'wupex-gift-cards' ); ?><br />
        <strong><?php echo esc_html( $site_name ); ?></strong></p>
    </div>

    <div class="email-footer">
        <p><?php echo esc_html( $site_name ); ?> &mdash; <?php echo esc_url( home_url() ); ?></p>
        <p><?php esc_html_e( 'This email was sent because you placed an order on our store.', 'wupex-gift-cards' ); ?></p>
    </div>
</div>
</body>
</html>
