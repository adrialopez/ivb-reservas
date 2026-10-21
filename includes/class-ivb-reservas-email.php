<?php
defined( 'ABSPATH' ) || exit;

/**
 * Email al cliente: ya puede pagar su reserva (enlace de pago del pedido).
 * Editable en WooCommerce > Ajustes > Emails.
 */
class IVB_Reservas_Email extends WC_Email {

	public function __construct() {
		$this->id             = 'ivb_reserva_pago_abierto';
		$this->customer_email = true;
		$this->title          = 'Reserva: pago abierto';
		$this->description    = 'Se envía al cliente cuando se abre la venta, para que pague su reserva.';
		$this->template_html  = 'emails/ivb-reserva-pago-abierto.php';
		$this->template_plain = 'emails/plain/ivb-reserva-pago-abierto.php';
		$this->template_base  = IVB_RESERVAS_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{producto}'     => '',
		);
		parent::__construct();
	}

	public function get_default_subject() {
		return 'Ya puedes pagar tu reserva de {producto} (pedido {order_number})';
	}

	public function get_default_heading() {
		return 'Ya puedes pagar tu reserva';
	}

	public function get_default_additional_content() {
		return 'Gracias por tu confianza.';
	}

	public function trigger( $order_id ) {
		$this->setup_locale();
		$order = wc_get_order( $order_id );
		$sent  = false;

		if ( $order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{producto}']     = IVB_Reservas_Core::product_name();

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		}

		$this->restore_locale();
		return $sent;
	}

	private function args( $plain ) {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'pay_url'            => $this->object->get_checkout_payment_url(),
			'producto'           => IVB_Reservas_Core::product_name(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->args( true ), '', $this->template_base );
	}
}
