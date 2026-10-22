# IVB Reservas

Plugin WooCommerce para IVB (profesional.ivbwellness.com). Sustituye al snippet 235 «Reserva Pack Trimestral Navidad».

- Con la venta **cerrada**: el producto de reserva (por defecto 123762) solo se compra solo; el pedido se crea sin pasar por la pasarela y queda en *Pendiente de pago* con el método elegido; en Mi cuenta aparece «Pagar» desactivado. Las reservas pendientes no se cancelan solas.
- **WooCommerce > Reservas**: listado por cliente (pedidos, unidades, total, pagados/pendientes, aviso enviado), exportación CSV, **Abrir venta y avisar**, reenviar aviso y volver al modo reserva.
- Al abrir la venta el producto es normal y se envía, escalonado (Action Scheduler), el email «Reserva: pago abierto» con el enlace de pago de cada pedido pendiente. Editable en WooCommerce > Ajustes > Emails.
- **SEPA:** los pedidos cuya pasarela es SEPA (por defecto solo `cod`, que en IVB es «Domiciliación SEPA») no reciben enlace de pago: al abrir la venta pasan a **Procesando** y WooCommerce envía el email normal de pedido en proceso. Las pasarelas se configuran en WooCommerce > Reservas.
- **Permisos:** ver el listado y exportar el CSV requiere gestionar WooCommerce; **abrir la venta, avisar, volver al modo reserva y cambiar ajustes solo pueden hacerlo administradores**.

## Instalación
1. Instalar y activar el plugin.
2. **Desactivar el snippet 235** en Code Snippets (si sigue activo, el plugin avisa).

Ver `CHANGELOG` en la descripción de cada versión del repo.
