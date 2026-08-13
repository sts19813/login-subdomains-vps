# Facturación de Naboo con Stripe

## Modelo de cobro

Cada `workspace` representa una instancia independiente de Naboo. El portal recibe una medición autenticada, conserva un historial y calcula el importe en centavos:

```text
propiedades_sin_renta = propiedades_totales - propiedades_rentadas
total = (propiedades_sin_renta × tarifa_sin_renta)
      + (propiedades_rentadas × tarifa_rentada)
```

Por defecto las tarifas son `$20.00` y `$40.00 MXN`. Una propiedad rentada sustituye la tarifa de `$20`; no se suman ambas tarifas sobre la misma propiedad.

Una propiedad se considera rentada si tiene un inquilino asignado y al menos un concepto de cobranza abierto en estado `pending`, `partial` o `in_validation`.

## Variables de entorno

Usa primero las claves del sandbox de Stripe:

```dotenv
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Las claves `pk_live_` y `sk_live_` son de producción aunque alguien las describa como pruebas. Nunca deben guardarse en Git. Si una clave secreta se comparte en un chat, ticket o documento, rótala desde Stripe antes de usarla.

## Webhook

Crea un destino en Stripe apuntando a:

```text
https://naboo.cloud/api/stripe/webhook
```

Suscribe al menos estos eventos:

```text
checkout.session.completed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
invoice.paid
invoice.payment_failed
```

Guarda el secreto de firma del destino en `STRIPE_WEBHOOK_SECRET`. La ruta verifica la firma sobre el cuerpo HTTP original y registra el ID de cada evento para que los reintentos sean idempotentes.

## Configuración por instancia

```bash
php artisan billing:configure tipi \
  --vacant=20 \
  --rented=40 \
  --grace=5 \
  --email=facturacion@example.com \
  --enable
```

Cuando cambia una tarifa, el sistema crea precios inmutables nuevos en Stripe y reemplaza los precios de los renglones existentes sin prorratear el ciclo en curso. La cantidad más reciente se utiliza en la siguiente factura.

Para desactivar temporalmente la exigencia sin cancelar datos en Stripe:

```bash
php artisan billing:configure tipi --disable
```

## Usuarios responsables y excepciones

Un responsable puede abrir Checkout y el portal de cliente de Stripe:

```bash
php artisan billing:access admin@example.com tipi --manager
```

Una excepción permite entrar aunque la suscripción no dé acceso:

```bash
php artisan billing:access soporte@example.com tipi --override
```

Los permisos se pueden retirar con `--no-manager` y `--no-override`. La excepción es por usuario e instancia; no convierte al usuario en administrador dentro de Naboo.

## Estados y tolerancia

- `active` y `trialing`: acceso permitido.
- `invoice.payment_failed`: inicia la tolerancia configurada; los reintentos del mismo periodo no extienden la fecha.
- Durante la tolerancia: acceso permitido y la pantalla solicita corregir el pago.
- Tolerancia vencida, `unpaid`, `canceled` o `incomplete_expired`: acceso bloqueado, salvo excepciones.
- Una instancia que reporta cero propiedades tiene acceso sin costo y no necesita crear una suscripción.

Las instancias consultan `POST /api/billing/entitlement` para aplicar el estado a sesiones ya abiertas. Consulta la implementación de middleware en [NABOO_CLIENT_INTEGRATION.md](NABOO_CLIENT_INTEGRATION.md).

## Prueba local

Con Stripe CLI y claves de sandbox:

```bash
stripe listen --forward-to http://127.0.0.1:8000/api/stripe/webhook
php artisan serve
```

Copia el `whsec_...` mostrado por Stripe CLI al `.env`, limpia la caché de configuración y usa los números de tarjeta de prueba publicados por Stripe. No pruebes este flujo con claves `live`.

## Operación

El cron de Laravel debe ejecutar `schedule:run` cada minuto. Una tarea horaria reintenta sincronizaciones que fallaron, y una tarea diaria depura auditoría antigua. Antes de activar producción:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan billing:configure tipi --enable
php artisan schedule:list
```
