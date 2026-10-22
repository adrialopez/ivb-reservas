<?php
/**
 * Plugin Name: IVB Reservas
 * Description: Reserva de un producto en WooCommerce (compra solo, sin pago, pedido pendiente), listado de reservas y aviso por email al abrir la venta.
 * Version: 1.1.0
 * Author: Adrià López
 * Author URI: https://adria-lopez.com
 * Requires Plugins: woocommerce
 * Text Domain: ivb-reservas
 */

defined( 'ABSPATH' ) || exit;

define( 'IVB_RESERVAS_VERSION', '1.1.0' );
define( 'IVB_RESERVAS_DIR', plugin_dir_path( __FILE__ ) );

// Compatibilidad con almacenamiento de pedidos de alto rendimiento (HPOS).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	require_once IVB_RESERVAS_DIR . 'includes/class-ivb-reservas-core.php';
	require_once IVB_RESERVAS_DIR . 'includes/class-ivb-reservas-admin.php';
	IVB_Reservas_Core::init();
	IVB_Reservas_Admin::init();
} );

// Registro del email (la clase se carga cuando WooCommerce construye sus emails).
add_filter( 'woocommerce_email_classes', function ( $emails ) {
	require_once IVB_RESERVAS_DIR . 'includes/class-ivb-reservas-email.php';
	$emails['IVB_Reservas_Email'] = new IVB_Reservas_Email();
	return $emails;
} );
