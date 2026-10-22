<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reglas de la reserva. Mientras la venta está cerrada:
 * - el producto solo se compra solo;
 * - el pedido se crea sin pasar por la pasarela y queda en "Pendiente de pago";
 * - no se puede pagar desde Mi cuenta (botón "Pagar" desactivado).
 * Con la venta abierta, el producto es normal y las reservas pendientes se pueden pagar.
 */
class IVB_Reservas_Core {

	const OPT_PRODUCT = 'ivb_reservas_product_id';
	const OPT_OPEN    = 'ivb_reservas_abierta';
	const META        = '_ivb_reserva';
	const OPT_SEPA    = 'ivb_reservas_sepa_gateways';

	public static function init() {
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_reservation' ), 10, 3 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'needs_payment' ), 10, 2 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_unpaid' ), 10, 2 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'thankyou_text' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_actions' ), 20, 2 );
		add_action( 'wp_head', array( __CLASS__, 'css' ) );
	}

	public static function product_id() {
		return (int) get_option( self::OPT_PRODUCT, 123762 );
	}

	public static function is_open() {
		return 'yes' === get_option( self::OPT_OPEN, 'no' );
	}

	public static function product_name() {
		$product = wc_get_product( self::product_id() );
		return $product ? $product->get_name() : 'producto de reserva';
	}

	/** IDs de pasarela que cuentan como SEPA (en IVB, "cod" se llama «Domiciliación SEPA»). */
	public static function sepa_gateways() {
		$raw = get_option( self::OPT_SEPA, 'cod,stripe_sepa_debit' );
		return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $raw ) ) ) ) );
	}

	/** Un pedido es SEPA si su pasarela está en la lista, o su título de método de pago dice "SEPA". */
	public static function is_sepa( $order ) {
		if ( ! $order ) {
			return false;
		}
		if ( in_array( strtolower( (string) $order->get_payment_method() ), self::sepa_gateways(), true ) ) {
			return true;
		}
		return false !== stripos( (string) $order->get_payment_method_title(), 'sepa' );
	}

	public static function is_reservation( $order ) {
		return $order && 'yes' === $order->get_meta( self::META );
	}

	private static function item_matches( $product_id, $variation_id = 0 ) {
		$id = self::product_id();
		return (int) $product_id === $id || (int) $variation_id === $id;
	}

	/** Unidades del producto de reserva en un pedido. */
	public static function units_in_order( $order ) {
		$units = 0;
		foreach ( $order->get_items() as $item ) {
			if ( self::item_matches( $item->get_product_id(), $item->get_variation_id() ) ) {
				$units += (int) $item->get_quantity();
			}
		}
		return $units;
	}

	public static function check_cart() {
		if ( self::is_open() || ! WC()->cart ) {
			return;
		}
		$has_reserva = false;
		$has_other   = false;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::item_matches( $item['product_id'], $item['variation_id'] ?? 0 ) ) {
				$has_reserva = true;
			} else {
				$has_other = true;
			}
		}
		if ( $has_reserva && $has_other ) {
			wc_add_notice(
				sprintf(
					'El producto «%s» es de reserva y solo se puede comprar por separado. Elimina el resto de productos del carrito para finalizar la reserva.',
					self::product_name()
				),
				'error'
			);
		}
	}

	public static function process_reservation( $order_id, $posted_data, $order ) {
		if ( self::is_open() ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || ! self::units_in_order( $order ) ) {
			return;
		}

		$order->update_meta_data( self::META, 'yes' );
		$order->set_status( 'pending' );
		$order->add_order_note( sprintf(
			'Reserva recibida de «%s». Método de pago elegido: %s. Pago no procesado.',
			self::product_name(),
			$order->get_payment_method_title()
		) );
		$order->save();

		WC()->cart->empty_cart();

		// Cortamos aquí para que WooCommerce NO llame a la pasarela.
		$redirect = $order->get_checkout_order_received_url();
		if ( wp_doing_ajax() || ! empty( $_REQUEST['wc-ajax'] ) ) {
			wp_send_json( array( 'result' => 'success', 'redirect' => $redirect ) );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function needs_payment( $needs, $order ) {
		if ( ! self::is_open() && self::is_reservation( $order ) ) {
			return false;
		}
		return $needs;
	}

	/** Las reservas pendientes no se cancelan solas por el límite de stock; se gestionan a mano. */
	public static function keep_unpaid( $cancel, $order ) {
		return self::is_reservation( $order ) ? false : $cancel;
	}

	public static function thankyou_text( $text, $order ) {
		if ( ! self::is_open() && self::is_reservation( $order ) && $order->has_status( 'pending' ) ) {
			return sprintf(
				'Hemos recibido tu reserva de «%s». Nos pondremos en contacto contigo para el pago.',
				self::product_name()
			);
		}
		return $text;
	}

	public static function account_actions( $actions, $order ) {
		if ( ! self::is_open() && self::is_reservation( $order ) && $order->has_status( 'pending' ) ) {
			$actions = array(
				'ivb-pay-disabled' => array(
					'url'  => '#',
					'name' => __( 'Pagar', 'woocommerce' ),
				),
			) + $actions;
		}
		return $actions;
	}

	public static function css() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		echo '<style id="ivb-reservas-css">'
			. '.woocommerce-orders-table__cell-order-actions{text-align:right}'
			. '.woocommerce-orders-table__cell-order-actions .button{display:inline-block;margin:0 0 8px 8px}'
			. '.woocommerce-orders-table__cell-order-actions .button.ivb-pay-disabled{opacity:.4;cursor:not-allowed;pointer-events:none;filter:grayscale(1)}'
			. '</style>';
	}
}
