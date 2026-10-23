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
	const OPT_LIMITS  = 'ivb_reservas_limits';   // array( id_producto => máximo de unidades reservadas; 0 = sin límite )
	const OPT_CLOSED  = 'ivb_reservas_cerrado_por_limite'; // array( id_producto => timestamp en que se cerró por llegar al máximo )

	public static function init() {
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_reservation' ), 10, 3 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'needs_payment' ), 10, 2 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_unpaid' ), 10, 2 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'thankyou_text' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_actions' ), 20, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( __CLASS__, 'valid_cancel_statuses' ), 10, 2 );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'view_order_note' ), 1 );
		add_action( 'woocommerce_after_account_orders', array( __CLASS__, 'orders_note' ) );
		add_action( 'wp_head', array( __CLASS__, 'css' ) );

		// Límite de reservas por producto
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 10, 2 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'invalidate_cache' ) );
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

	/** IDs de pasarela que cuentan como SEPA. En IVB solo "cod" (se llama «Domiciliación SEPA»). */
	public static function sepa_gateways() {
		$raw = get_option( self::OPT_SEPA, 'cod' );
		return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $raw ) ) ) ) );
	}

	/** Un pedido es SEPA solo si su pasarela está en la lista (no se mira el título). */
	public static function is_sepa( $order ) {
		return $order && in_array( strtolower( (string) $order->get_payment_method() ), self::sepa_gateways(), true );
	}

	/** Máximo de unidades reservables de un producto (0 = sin límite). */
	public static function limit_for( $product_id = null ) {
		$product_id = $product_id ? (int) $product_id : self::product_id();
		$limits     = (array) get_option( self::OPT_LIMITS, array() );
		return isset( $limits[ $product_id ] ) ? max( 0, (int) $limits[ $product_id ] ) : 0;
	}

	public static function set_limit( $product_id, $max ) {
		$limits                    = (array) get_option( self::OPT_LIMITS, array() );
		$limits[ (int) $product_id ] = max( 0, (int) $max );
		update_option( self::OPT_LIMITS, $limits );
		self::invalidate_cache();
	}

	public static function invalidate_cache() {
		delete_transient( 'ivb_reservas_units_' . self::product_id() );
	}

	/** Argumentos de wc_get_orders para los pedidos con la marca de reserva (HPOS usa meta_query; el almacenamiento antiguo, meta_key). */
	private static function reservation_orders_args( array $args ) {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args['meta_query'] = array( array( 'key' => self::META, 'value' => 'yes' ) );
		} else {
			$args['meta_key']   = self::META;
			$args['meta_value'] = 'yes';
		}
		return $args;
	}

	/**
	 * Unidades ya reservadas del producto: pedidos de reserva vivos (pendiente, en espera, procesando, completado).
	 * Los cancelados, fallidos y reembolsados liberan plaza. $force = recontar sin usar la caché.
	 */
	public static function reserved_units( $force = false ) {
		$key = 'ivb_reservas_units_' . self::product_id();
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}
		$ids = wc_get_orders( self::reservation_orders_args( array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => array( 'pending', 'on-hold', 'processing', 'completed' ),
		) ) );
		$sum = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$sum += self::units_in_order( $order );
			}
		}
		set_transient( $key, $sum, 5 * MINUTE_IN_SECONDS );
		return $sum;
	}

	/** Plazas que quedan (null si no hay límite o la venta ya está abierta). */
	public static function remaining( $force = false ) {
		$limit = self::limit_for();
		if ( self::is_open() || $limit <= 0 ) {
			return null;
		}
		return max( 0, $limit - self::reserved_units( $force ) );
	}

	public static function is_sold_out( $force = false ) {
		$rem = self::remaining( $force );
		return null !== $rem && $rem <= 0;
	}

	/** Pasa el producto a borrador al agotarse. Los carritos que lo tenían lo pierden con el aviso estándar de WooCommerce. */
	public static function close_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( 'publish' === get_post_status( $product_id ) ) {
			wp_update_post( array( 'ID' => $product_id, 'post_status' => 'draft' ) );
		}
		$closed                = (array) get_option( self::OPT_CLOSED, array() );
		$closed[ $product_id ] = time();
		update_option( self::OPT_CLOSED, $closed );
		do_action( 'ivb_reservas_limit_reached', $product_id );
	}

	private static function lock() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$got = $wpdb->get_var( "SELECT GET_LOCK('ivb_reservas_limit', 10)" );
		$wpdb->suppress_errors( false );
		return (bool) $got;
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->get_var( "SELECT RELEASE_LOCK('ivb_reservas_limit')" );
		$wpdb->suppress_errors( false );
	}

	/** Mientras la venta está cerrada y quedan 0 plazas, el producto no se puede comprar. */
	public static function purchasable( $purchasable, $product ) {
		if ( $purchasable && $product && (int) $product->get_id() === self::product_id() && self::is_sold_out() ) {
			return false;
		}
		return $purchasable;
	}

	public static function validate_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! $passed || ! self::item_matches( $product_id ) ) {
			return $passed;
		}
		$rem = self::remaining();
		if ( null === $rem ) {
			return $passed;
		}
		$in_cart = 0;
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::item_matches( $item['product_id'], $item['variation_id'] ?? 0 ) ) {
					$in_cart += (int) $item['quantity'];
				}
			}
		}
		if ( $rem <= 0 ) {
			wc_add_notice( self::sold_out_text(), 'error' );
			return false;
		}
		if ( $in_cart + (int) $quantity > $rem ) {
			wc_add_notice( self::remaining_text( $rem, $in_cart + (int) $quantity ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function sold_out_text() {
		return sprintf( 'Las reservas de «%s» se han agotado. Ya no se puede finalizar este pedido.', self::product_name() );
	}

	public static function remaining_text( $remaining, $wanted ) {
		return sprintf(
			'Solo quedan %d reservas de «%s» y has pedido %d. Reduce la cantidad para poder finalizar el pedido.',
			(int) $remaining,
			self::product_name(),
			(int) $wanted
		);
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
		// Límite de reservas
		if ( $has_reserva ) {
			$rem = self::remaining();
			if ( null !== $rem ) {
				$qty = 0;
				foreach ( WC()->cart->get_cart() as $item ) {
					if ( self::item_matches( $item['product_id'], $item['variation_id'] ?? 0 ) ) {
						$qty += (int) $item['quantity'];
					}
				}
				if ( $rem <= 0 ) {
					wc_add_notice( self::sold_out_text(), 'error' );
				} elseif ( $qty > $rem ) {
					wc_add_notice( self::remaining_text( $rem, $qty ), 'error' );
				}
			}
		}
	}

	/**
	 * Decide y registra una reserva. Con límite, cuenta bajo un bloqueo para que dos pedidos simultáneos no pasen del máximo:
	 * si no cabe, el pedido se cancela en silencio. Devuelve array( 'ok' => bool, 'remaining' => int|null ).
	 */
	public static function reserve( $order ) {
		$units = self::units_in_order( $order );
		$limit = self::limit_for();
		$locked = false;
		if ( $limit > 0 ) {
			$locked = self::lock();
			$others = self::reserved_units( true ); // este pedido aún no lleva la marca, no se cuenta
			if ( $others + $units > $limit ) {
				$remaining = max( 0, $limit - $others );
				add_filter( 'woocommerce_email_enabled_cancelled_order', '__return_false' ); // sin correo de «pedido cancelado» al administrador
				$order->update_status( 'cancelled', sprintf( 'Reserva rechazada: no caben %d unidades (límite %d, quedaban %d).', $units, $limit, $remaining ) );
				remove_filter( 'woocommerce_email_enabled_cancelled_order', '__return_false' );
				if ( $locked ) {
					self::unlock();
				}
				return array( 'ok' => false, 'remaining' => $remaining );
			}
		}

		$order->update_meta_data( self::META, 'yes' );
		$order->set_status( 'pending' );
		$order->add_order_note( sprintf(
			'Reserva recibida de «%s». Método de pago elegido: %s. Pago no procesado.',
			self::product_name(),
			$order->get_payment_method_title()
		) );
		$order->save();
		self::invalidate_cache();

		$remaining = null;
		if ( $limit > 0 ) {
			$remaining = max( 0, $limit - self::reserved_units( true ) );
			if ( $remaining <= 0 ) {
				self::close_product( self::product_id() );
			}
			if ( $locked ) {
				self::unlock();
			}
		}
		return array( 'ok' => true, 'remaining' => $remaining );
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

		$result = self::reserve( $order );
		if ( ! $result['ok'] ) {
			$msg = $result['remaining'] > 0
				? self::remaining_text( $result['remaining'], self::units_in_order( $order ) )
				: self::sold_out_text();
			if ( wp_doing_ajax() || ! empty( $_REQUEST['wc-ajax'] ) ) {
				wp_send_json( array(
					'result'   => 'failure',
					'messages' => '<ul class="woocommerce-error" role="alert"><li>' . esc_html( $msg ) . '</li></ul>',
					'refresh'  => true,
					'reload'   => false,
				) );
			}
			wc_add_notice( $msg, 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

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
		if ( ! self::is_reservation( $order ) || ! $order->has_status( 'pending' ) ) {
			return $actions;
		}
		if ( ! self::is_open() ) {
			$actions = array(
				'ivb-pay-disabled' => array(
					'url'  => '#',
					'name' => __( 'Pagar', 'woocommerce' ),
				),
			) + $actions;
		}
		// El cliente no puede cancelar una reserva: botón visible pero desactivado.
		$actions['ivb-cancel-disabled'] = array(
			'url'  => '#',
			'name' => __( 'Cancel', 'woocommerce' ),
		);
		return $actions;
	}

	/** Mensaje que se muestra al cliente cuando no puede cancelar una reserva. */
	public static function cancel_text() {
		return 'Las reservas no se pueden cancelar desde la web. Si quieres cancelar tu pedido, ponte en contacto con tu comercial.';
	}

	/** Las reservas no se pueden cancelar desde Mi cuenta: sin estados válidos, WooCommerce ni ofrece ni acepta la cancelación del cliente. */
	public static function valid_cancel_statuses( $statuses, $order = null ) {
		if ( $order && self::is_reservation( $order ) && $order->has_status( 'pending' ) ) {
			return array();
		}
		return $statuses;
	}

	/** Aviso en la página del pedido (Mi cuenta > Pedidos > Ver). */
	public static function view_order_note( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && self::is_reservation( $order ) && $order->has_status( 'pending' ) ) {
			echo '<p class="woocommerce-info ivb-reserva-cancel-note">' . esc_html( self::cancel_text() ) . '</p>';
		}
	}

	/** Aviso bajo la lista de pedidos si el cliente tiene alguna reserva pendiente. */
	public static function orders_note() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$orders = wc_get_orders( array(
			'customer_id' => get_current_user_id(),
			'status'      => 'pending',
			'limit'       => 50,
		) );
		foreach ( $orders as $order ) {
			if ( self::is_reservation( $order ) ) {
				echo '<p class="woocommerce-info ivb-reserva-cancel-note">' . esc_html( self::cancel_text() ) . '</p>';
				return;
			}
		}
	}

	public static function css() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		echo '<style id="ivb-reservas-css">'
			. '.woocommerce-orders-table__cell-order-actions{text-align:right}'
			. '.woocommerce-orders-table__cell-order-actions .button{display:inline-block;margin:0 0 8px 8px}'
			. '.woocommerce-orders-table__cell-order-actions .button.ivb-pay-disabled,.woocommerce-orders-table__cell-order-actions .button.ivb-cancel-disabled{opacity:.4;cursor:not-allowed;pointer-events:none;filter:grayscale(1)}'
			. '</style>';
	}
}
