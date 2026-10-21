<?php
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>Hola,</p>
<p>Ya está abierta la venta de <strong><?php echo esc_html( $producto ); ?></strong>. Puedes pagar tu reserva (pedido <?php echo esc_html( $order->get_order_number() ); ?>) desde este enlace:</p>

<p style="margin:24px 0;">
	<a href="<?php echo esc_url( $pay_url ); ?>" style="background:#4d738a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:4px;display:inline-block;font-weight:bold;">Pagar mi reserva</a>
</p>

<p>Si el botón no funciona, copia esta dirección en el navegador: <?php echo esc_url( $pay_url ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
