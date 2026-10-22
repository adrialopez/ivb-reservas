<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > Reservas: listado por cliente (pedidos y unidades), exportación CSV,
 * apertura de la venta y envío del aviso de pago.
 */
class IVB_Reservas_Admin {

	const SLUG   = 'ivb-reservas';
	const NONCE  = 'ivb_reservas';
	const NOTICE = 'ivb_reservas_send_notice';
	const AVISADO = '_ivb_reserva_avisado';

	/** Abrir/cerrar la venta, avisar y cambiar ajustes: solo administradores (no gestores de la tienda). */
	public static function can_open_sales() {
		return current_user_can( 'manage_options' );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ivb_reservas_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_ivb_reservas_notify', array( __CLASS__, 'handle_notify' ) );
		add_action( 'admin_post_ivb_reservas_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( self::NOTICE, array( __CLASS__, 'send_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'snippet_warning' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Reservas', 'Reservas', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** Avisa si sigue activo el snippet antiguo (duplicaría las reglas). */
	public static function snippet_warning() {
		if ( function_exists( 'ivb_reserva_product_id' ) && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-warning"><p><strong>IVB Reservas:</strong> el snippet antiguo de reservas sigue activo. Desactívalo en Code Snippets: duplica las reglas y seguirá bloqueando el producto aunque abras la venta.</p></div>';
		}
	}

	/** Reservas (pedidos con la marca) agrupadas por cliente. */
	private static function get_rows() {
		$args = array(
			'limit'   => -1,
			'status'  => array( 'pending', 'on-hold', 'processing', 'completed' ),
			'orderby' => 'date',
			'order'   => 'ASC',
		);
		// HPOS usa meta_query; el almacenamiento antiguo (posts) solo admite meta_key/meta_value.
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args['meta_query'] = array( array( 'key' => IVB_Reservas_Core::META, 'value' => 'yes' ) );
		} else {
			$args['meta_key']   = IVB_Reservas_Core::META;
			$args['meta_value'] = 'yes';
		}
		$orders = wc_get_orders( $args );

		$rows = array();
		foreach ( $orders as $order ) {
			$email = strtolower( trim( $order->get_billing_email() ) );
			$key   = $order->get_customer_id() ? 'u' . $order->get_customer_id() : 'e' . $email;
			if ( ! isset( $rows[ $key ] ) ) {
				$name = $order->get_billing_company() ? $order->get_billing_company() : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$rows[ $key ] = array(
					'name' => $name, 'email' => $email, 'orders' => array(), 'units' => 0,
					'total' => 0.0, 'paid' => 0, 'pending' => 0, 'first' => $order->get_date_created(),
				);
			}
			$pending = $order->has_status( 'pending' );
			$rows[ $key ]['orders'][] = $order;
			$rows[ $key ]['units']   += IVB_Reservas_Core::units_in_order( $order );
			$rows[ $key ]['total']   += (float) $order->get_total();
			$pending ? $rows[ $key ]['pending']++ : $rows[ $key ]['paid']++;
		}
		return $rows;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$rows       = self::get_rows();
		$open       = IVB_Reservas_Core::is_open();
		$t_orders   = 0;
		$t_units    = 0;
		$t_pending  = 0;
		$t_sepa     = 0;
		$t_total    = 0.0;
		foreach ( $rows as $r ) {
			$t_orders  += count( $r['orders'] );
			$t_units   += $r['units'];
			$t_pending += $r['pending'];
			$t_total   += $r['total'];
			foreach ( $r['orders'] as $o ) {
				if ( $o->has_status( 'pending' ) && IVB_Reservas_Core::is_sepa( $o ) ) {
					$t_sepa++;
				}
			}
		}
		$is_admin = self::can_open_sales();
		$post     = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1>Reservas: <?php echo esc_html( IVB_Reservas_Core::product_name() ); ?></h1>

			<?php if ( isset( $_GET['ivb_msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['ivb_msg'] ) ); ?></p></div>
			<?php endif; ?>

			<p>
				Estado de la venta:
				<strong style="color:<?php echo $open ? '#2e7d32' : '#b26a00'; ?>"><?php echo $open ? 'ABIERTA (producto normal)' : 'CERRADA (solo reservas)'; ?></strong>
			</p>

			<p>
				<strong><?php echo (int) count( $rows ); ?></strong> clientes ·
				<strong><?php echo (int) $t_orders; ?></strong> pedidos (<?php echo (int) $t_pending; ?> pendientes de pago) ·
				<strong><?php echo (int) $t_units; ?></strong> unidades ·
				<strong><?php echo wp_kses_post( wc_price( $t_total ) ); ?></strong>
			</p>

			<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'ivb_reservas_csv', $post ), self::NONCE ) ); ?>">Exportar CSV</a>

				<?php if ( ! $is_admin ) : ?>
					<span class="description">Solo un administrador puede abrir la venta y enviar los avisos.</span>
				<?php elseif ( ! $open ) : ?>
					<form method="post" action="<?php echo esc_url( $post ); ?>" onsubmit="return confirm('¿Abrir la venta? El producto pasará a ser normal. De los <?php echo (int) $t_pending; ?> pedidos pendientes, <?php echo (int) $t_sepa; ?> con SEPA pasarán a «Procesando» (con el email normal de WooCommerce) y el resto (<?php echo (int) ( $t_pending - $t_sepa ); ?>) recibirán el email con el enlace de pago.');">
						<input type="hidden" name="action" value="ivb_reservas_toggle">
						<input type="hidden" name="mode" value="open">
						<?php wp_nonce_field( self::NONCE ); ?>
						<button class="button button-primary">Abrir venta y avisar</button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $post ); ?>" onsubmit="return confirm('¿Reenviar el aviso de pago a todos los pedidos que siguen pendientes?');">
						<input type="hidden" name="action" value="ivb_reservas_notify">
						<?php wp_nonce_field( self::NONCE ); ?>
						<button class="button">Reenviar aviso a los pendientes</button>
					</form>
					<form method="post" action="<?php echo esc_url( $post ); ?>" onsubmit="return confirm('¿Volver al modo reserva? El producto volverá a bloquearse y las reservas pendientes dejarán de poder pagarse.');">
						<input type="hidden" name="action" value="ivb_reservas_toggle">
						<input type="hidden" name="mode" value="close">
						<?php wp_nonce_field( self::NONCE ); ?>
						<button class="button">Volver al modo reserva</button>
					</form>
				<?php endif; ?>
			</p>

			<table class="widefat striped" style="max-width:1200px">
				<thead><tr>
					<th>Cliente</th><th>Email</th><th>Pedidos</th><th>Uds.</th><th>Total</th><th>Pagados</th><th>Pendientes</th><th>Aviso enviado</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8">Todavía no hay reservas.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td><?php echo esc_html( $r['email'] ); ?></td>
						<td>
							<?php
							$links = array();
							foreach ( $r['orders'] as $o ) {
								$links[] = '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>';
							}
							echo wp_kses_post( implode( ', ', $links ) );
							?>
						</td>
						<td><?php echo (int) $r['units']; ?></td>
						<td><?php echo wp_kses_post( wc_price( $r['total'] ) ); ?></td>
						<td><?php echo (int) $r['paid']; ?></td>
						<td><?php echo (int) $r['pending']; ?></td>
						<td>
							<?php
							$avisos = array();
							foreach ( $r['orders'] as $o ) {
								$ts = (int) $o->get_meta( self::AVISADO );
								if ( $ts ) {
									$avisos[] = esc_html( wp_date( 'd/m/Y H:i', $ts ) );
								}
							}
							echo $avisos ? wp_kses_post( implode( '<br>', $avisos ) ) : '—';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $is_admin ) : ?>
			<form method="post" action="<?php echo esc_url( $post ); ?>" style="margin-top:24px">
				<input type="hidden" name="action" value="ivb_reservas_toggle">
				<input type="hidden" name="mode" value="save">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<label>ID del producto de reserva:
						<input type="number" name="product_id" value="<?php echo (int) IVB_Reservas_Core::product_id(); ?>" style="width:110px">
					</label>
				</p>
				<p>
					<label>Pasarelas SEPA (IDs separados por coma):
						<input type="text" name="sepa_gateways" value="<?php echo esc_attr( implode( ',', IVB_Reservas_Core::sepa_gateways() ) ); ?>" style="width:260px">
					</label>
					<span class="description">Al abrir la venta, estos pedidos pasan a «Procesando» en vez de recibir el enlace de pago. También cuenta como SEPA si el título del método de pago contiene «SEPA».</span>
				</p>
				<button class="button">Guardar</button>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** $admin_only: acciones que cambian el estado de la venta o envían avisos exigen administrador. */
	private static function check_request( $admin_only = false ) {
		$allowed = $admin_only ? self::can_open_sales() : current_user_can( 'manage_woocommerce' );
		if ( ! $allowed ) {
			wp_die( 'Solo un administrador puede hacer esto.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'ivb_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Programa un email por pedido, escalonados para no saturar el correo. */
	private static function queue_notices( array $order_ids ) {
		$i = 0;
		foreach ( $order_ids as $order_id ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5 * $i, self::NOTICE, array( (int) $order_id ), 'ivb-reservas' );
			} else {
				self::send_notice( $order_id );
			}
			$i++;
		}
		return $i;
	}

	private static function pending_ids( $only_not_notified = false ) {
		$ids = array();
		foreach ( self::get_rows() as $r ) {
			foreach ( $r['orders'] as $o ) {
				if ( ! $o->has_status( 'pending' ) ) {
					continue;
				}
				if ( $only_not_notified && $o->get_meta( self::AVISADO ) ) {
					continue;
				}
				$ids[] = $o->get_id();
			}
		}
		return $ids;
	}

	public static function handle_toggle() {
		self::check_request( true );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';

		if ( 'save' === $mode ) {
			update_option( IVB_Reservas_Core::OPT_PRODUCT, absint( $_POST['product_id'] ?? 0 ) );
			if ( isset( $_POST['sepa_gateways'] ) ) {
				$ids = array_filter( array_map( 'sanitize_key', explode( ',', wp_unslash( $_POST['sepa_gateways'] ) ) ) );
				update_option( IVB_Reservas_Core::OPT_SEPA, implode( ',', $ids ) );
			}
			self::back( 'Ajustes guardados.' );
		}
		if ( 'close' === $mode ) {
			update_option( IVB_Reservas_Core::OPT_OPEN, 'no' );
			self::back( 'Venta cerrada: vuelve el modo reserva.' );
		}
		if ( 'open' === $mode ) {
			update_option( IVB_Reservas_Core::OPT_OPEN, 'yes' );
			$ids  = self::pending_ids( true );
			$sepa = 0;
			foreach ( $ids as $id ) {
				if ( IVB_Reservas_Core::is_sepa( wc_get_order( $id ) ) ) {
					$sepa++;
				}
			}
			self::queue_notices( $ids );
			self::back( sprintf(
				'Venta abierta. %d pedidos SEPA pasarán a «Procesando» y %d recibirán el aviso de pago (se envían escalonados).',
				$sepa,
				count( $ids ) - $sepa
			) );
		}
		self::back( '' );
	}

	public static function handle_notify() {
		self::check_request( true );
		if ( ! IVB_Reservas_Core::is_open() ) {
			self::back( 'Primero hay que abrir la venta.' );
		}
		$n = self::queue_notices( self::pending_ids() );
		self::back( sprintf( '%d avisos de pago programados.', $n ) );
	}

	/** Tarea programada: envía el email y deja constancia en el pedido. */
	public static function send_notice( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( 'pending' ) || ! IVB_Reservas_Core::is_open() ) {
			return;
		}
		// SEPA: no hay nada que pagar ahora (se cobra por domiciliación). Pasa a "Procesando"
		// y WooCommerce envía al cliente el email normal de pedido en proceso.
		if ( IVB_Reservas_Core::is_sepa( $order ) ) {
			$order->update_status( 'processing', 'Venta abierta: reserva con SEPA, pasa a Procesando.' );
			$order->update_meta_data( self::AVISADO, time() );
			$order->save();
			return;
		}
		$emails = WC()->mailer()->get_emails();
		if ( empty( $emails['IVB_Reservas_Email'] ) ) {
			return;
		}
		if ( $emails['IVB_Reservas_Email']->trigger( $order_id ) ) {
			$order->update_meta_data( self::AVISADO, time() );
			$order->add_order_note( 'Aviso de pago de la reserva enviado a ' . $order->get_billing_email() . '.' );
			$order->save();
		} else {
			$order->add_order_note( 'No se pudo enviar el aviso de pago de la reserva (email desactivado o sin destinatario).' );
		}
	}

	public static function handle_csv() {
		self::check_request();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reservas-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para que Excel lea bien los acentos.
		fputcsv( $out, array( 'Cliente', 'Email', 'Pedidos', 'Unidades', 'Total', 'Pagados', 'Pendientes', 'Primer pedido' ), ';' );
		foreach ( self::get_rows() as $r ) {
			$nums = array();
			foreach ( $r['orders'] as $o ) {
				$nums[] = '#' . $o->get_order_number();
			}
			fputcsv( $out, array(
				self::csv_safe( $r['name'] ), $r['email'], implode( ' ', $nums ), $r['units'],
				number_format( $r['total'], 2, ',', '' ), $r['paid'], $r['pending'],
				$r['first'] ? $r['first']->date( 'd/m/Y' ) : '',
			), ';' );
		}
		fclose( $out );
		exit;
	}

	/** Evita que Excel ejecute fórmulas si el nombre del cliente empieza por = + - @. */
	private static function csv_safe( $value ) {
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}
}
