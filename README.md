# QuickPay para WooCommerce

Cobra en bolívares en tu tienda WooCommerce con los enlaces de pago de QuickPay. Tu cliente elige "Paga con QuickPay", va a la página segura de QuickPay, elige su banco y autoriza con la clave que le envía su banco. Tu tienda nunca ve datos bancarios.

## Cómo funciona

1. Tu cliente finaliza la compra con "Paga con QuickPay".
2. El plugin crea un enlace de pago para ese pedido y lleva a tu cliente a pagarlo.
3. Cuando QuickPay confirma el pago, el pedido pasa a **Procesando** y queda una nota con el monto y la referencia bancaria.
4. Tu cliente vuelve a la página de confirmación de tu tienda.

El pedido se marca pagado **sólo** cuando QuickPay lo confirma:

- por el aviso firmado que QuickPay le envía a tu tienda (webhook), o
- por una consulta a QuickPay: cada 10 minutos y cuando tu cliente vuelve a la página de confirmación.

Volver a tu tienda con `?estado=pagado` en la dirección **no** marca el pedido: cualquiera puede escribir esa dirección.

Cada pedido se marca pagado una sola vez, aunque el aviso llegue repetido.

## Requisitos

- WordPress 6.4 o superior y PHP 8.0 o superior.
- WooCommerce 8.3 o superior. Funciona con la caja por bloques y con la caja clásica, y con el almacenamiento de pedidos de alto rendimiento (HPOS).
- Tu sitio debe usar `https://`.
- Una cuenta de comercio en QuickPay con su llave de API.

## 1. Instala el plugin

1. Descarga `quickpay-woocommerce.zip`.
2. En WordPress, ve a **Plugins › Añadir nuevo › Subir plugin**, elige el archivo y pulsa **Instalar ahora**.
3. Pulsa **Activar**.

## 2. Configura el método de pago

Ve a **WooCommerce › Ajustes › Pagos › QuickPay**.

| Ajuste | Qué escribir |
|---|---|
| Activar | Márcalo cuando termines de configurar. |
| Título | Lo que ve tu cliente al elegir cómo pagar. Por omisión, "Paga con QuickPay". |
| Descripción | Texto que acompaña al método en la caja. |
| Llave de API | `tu-comercio:sk_test_…` para probar o `tu-comercio:sk_live_…` para cobrar de verdad. La sacas en tu portal, en **Integraciones › Llaves de API**. |
| Secreto del webhook | El secreto que te da QuickPay al registrar la dirección del paso 3. |
| Moneda de la tienda | "Mi tienda ya vende en bolívares" o "Tasa fija" (ver abajo). |
| Tasa fija | Bolívares por 1 unidad de la moneda de tu tienda. Sólo si elegiste tasa fija. |
| Vigencia del enlace | Horas que el enlace sigue abierto, de 1 a 720. Por omisión, 24. |
| Registro | Guarda un registro detallado en **WooCommerce › Estado › Registros** (fuente `quickpay`). La llave y el secreto nunca se escriben en el registro. |

El modo lo decide la llave: con `sk_test_` nunca se mueve dinero. La pantalla de ajustes te dice en qué modo estás.

## 3. Registra el webhook

La pantalla de ajustes te muestra la dirección de tu tienda para los avisos, por ejemplo:

```
https://tu-tienda.com/wp-json/quickpay/v1/webhook
```

1. En tu portal de QuickPay, ve a **Integraciones › Webhooks › Agregar URL**.
2. Pega esa dirección y elige el evento `link.pagado`.
3. Copia el **secreto** que te muestra. Se muestra una sola vez.
4. Pégalo en **Secreto del webhook** y guarda.

Para comprobarlo, usa **Integraciones › Webhooks › Probar** en tu portal.

## 4. Elige la moneda

QuickPay cobra sólo en bolívares.

- **Tu tienda vende en bolívares (VES):** elige "Mi tienda ya vende en bolívares". El enlace se crea por el total del pedido.
- **Tu tienda vende en otra moneda (por ejemplo, USD):** elige "Tasa fija" y escribe cuántos bolívares vale 1 unidad de tu moneda. Un pedido de 10 USD con tasa 40,00 se cobra Bs 400,00.

El plugin **no consulta ninguna tasa por su cuenta**: la tasa la decides tú y la mantienes al día. Cada pedido guarda en una nota la tasa con la que se cobró.

Si eliges tasa fija y la dejas vacía, QuickPay no aparece en la caja.

## 5. Prueba sin mover dinero

1. Usa una llave `sk_test_`.
2. Haz una compra en tu tienda y elige "Paga con QuickPay".
3. En la página de pago verás **"Modo prueba"**. Elige cualquier banco, escribe un teléfono y una cédula de ejemplo, y cualquier clave de 8 dígitos (por ejemplo, `12345678`).
4. El pago se aprueba y el pedido pasa a **Procesando**. La nota del pedido dice `[PRUEBA: no se movió dinero]`.

En modo prueba todo pago se aprueba: todavía no se puede simular un rechazo ni un vencimiento.

## 6. Pasa a producción

1. Cuando tu cuenta esté aprobada, crea tu llave `sk_live_` en el portal.
2. Pégala en **Llave de API** y guarda.
3. Haz una compra real de monto pequeño para confirmar todo de punta a punta.

Si después de cambiar a `sk_live_` alguien paga un enlace de prueba viejo, el pedido **no** se marca pagado: queda una nota que lo explica.

## Qué verás en tus pedidos

| Situación | Estado del pedido | Nota |
|---|---|---|
| Se creó el enlace | Pendiente de pago | "Enlace de pago QuickPay creado por Bs …" |
| El banco está confirmando | Pendiente de pago | "El banco está confirmando el pago…". No le pidas al cliente que pague otra vez. |
| QuickPay confirmó el pago | Procesando | "Pago confirmado por QuickPay: Bs … Referencia bancaria: …" |
| El monto pagado no coincide con el del pedido | En espera | Explica la diferencia. Revísalo a mano. |
| El cliente pagó dos veces el mismo pedido | Sin cambio | "ATENCIÓN: QuickPay confirmó OTRO pago…". Devuelve el excedente. |
| El enlace venció sin pagarse | Pendiente de pago | "El enlace de pago QuickPay venció sin pagarse." |

Mientras el enlace esté vigente, WooCommerce no cancela el pedido por falta de pago. Si el pago llega con el pedido ya cancelado, el pedido se reactiva como pagado y queda una nota para que revises el inventario.

Si tu cliente vuelve a pagar un pedido pendiente, se reusa el mismo enlace. Si el monto cambió o el enlace venció, se crea uno nuevo.

## Limitaciones

- **Reembolsos:** el plugin no hace reembolsos. Devuelve el pago por tu cuenta y anótalo en el pedido.
- **Moneda:** sólo bolívares. Con otra moneda necesitas una tasa fija escrita por ti.
- **Revisión periódica:** usa WP-Cron, que corre cuando tu sitio recibe visitas. En sitios con poco tráfico, configura un cron real del servidor que llame a `wp-cron.php`. El webhook no depende de esto.
- **Rechazos:** si el banco rechaza, tu cliente se queda en la página de pago y puede intentarlo otra vez; no vuelve a tu tienda.
- **El aviso no dice si el pago era de prueba:** por eso cada pedido guarda con qué tipo de llave se creó su enlace.
- **Probado** con WordPress 7.1, WooCommerce 11.2 y HPOS, con la caja por bloques. La caja clásica usa el mismo proceso de pago, pero no se probó de punta a punta.

## Solución de problemas

| Problema | Qué revisar |
|---|---|
| QuickPay no aparece en la caja | Que esté activado, que la llave tenga el formato `comercio:sk_…` y que la moneda cuadre (tienda en VES, o tasa fija escrita). |
| "No pudimos iniciar el pago con QuickPay" | Activa el registro y revisa **WooCommerce › Estado › Registros** (fuente `quickpay`). |
| El pedido no pasa a Procesando | En tu portal, revisa el historial de entregas del webhook. Si responde `401`, el secreto no coincide. Si el aviso no llega, la revisión periódica lo marcará en unos minutos. |
| El pedido quedó "En espera" | Lee la nota del pedido: explica la diferencia de monto. |
