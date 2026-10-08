=== QuickPay para WooCommerce ===
Contributors: quickpay
Tags: pagos, venezuela, bolivares, woocommerce, pasarela
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 8.3
WC tested up to: 11.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cobra en bolívares con enlaces de pago de QuickPay. El pedido se marca pagado sólo cuando QuickPay confirma el pago.

== Description ==

Con QuickPay tu cliente paga en bolívares desde su banco: elige su banco en la página segura de QuickPay y autoriza con la clave de 8 dígitos que le envía su banco. Tu tienda nunca ve datos bancarios.

Cómo funciona:

* Al finalizar la compra se crea un enlace de pago de QuickPay para el pedido y el cliente va a pagarlo.
* El pedido se marca pagado cuando llega el aviso firmado de QuickPay (webhook) o, si el aviso no llega, cuando la tienda consulta el cobro (cada 10 minutos y al volver el cliente a la página de confirmación).
* Volver a la tienda con "?estado=pagado" en la dirección no marca nada: eso no prueba el pago.
* Cada pedido se marca pagado una sola vez, aunque el aviso llegue repetido.
* Funciona con la caja por bloques y con la caja clásica, y con el almacenamiento de pedidos de alto rendimiento (HPOS).

Moneda: QuickPay cobra sólo en bolívares (VES). Si tu tienda vende en otra moneda, escribe una tasa fija en los ajustes; el plugin no consulta ninguna tasa por su cuenta.

Reembolsos: este plugin no hace reembolsos. Si necesitas devolver un pago, hazlo desde tu portal de QuickPay o por tu banco y anótalo en el pedido.

== Installation ==

1. Sube el archivo zip en Plugins › Añadir nuevo › Subir plugin, y actívalo. WooCommerce debe estar activo.
2. Ve a WooCommerce › Ajustes › Pagos › QuickPay.
3. Pega tu llave de API (comercio:sk_test_… para probar).
4. En tu portal de QuickPay, en Integraciones › Webhooks › Agregar URL, registra la dirección que muestra la pantalla de ajustes, con el evento link.pagado. Copia el secreto (se muestra una sola vez) y pégalo en "Secreto del webhook".
5. Elige la moneda: tienda en bolívares, o tasa fija.
6. Activa el método y guarda.

Tu sitio debe usar https://.

== Frequently Asked Questions ==

= ¿Cómo pruebo sin mover dinero? =

Usa una llave sk_test_. En la página de pago aparece "Modo prueba": elige cualquier banco, usa datos de ejemplo y cualquier clave de 8 dígitos. El pedido pasa a "Procesando" cuando llega el aviso.

= ¿Qué pasa si el banco del cliente tarda en confirmar? =

El pedido queda pendiente con una nota y la tienda lo sigue consultando. No le pidas al cliente que pague otra vez.

= ¿WooCommerce cancela el pedido si el cliente tarda en pagar? =

No mientras el enlace esté vigente (por omisión, 24 horas; lo cambias en los ajustes).

== Changelog ==

= 0.1.0 =
* Primera versión: enlace de pago por pedido, webhook con firma v2, revisión periódica y en la página de confirmación, tasa fija opcional.
