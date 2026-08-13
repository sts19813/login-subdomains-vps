# Naboo Login Central

Portal de autenticación central para `naboo.cloud`. Autentica una sola vez y entrega al usuario a su espacio autorizado (`tipi.naboo.cloud`, `tayde.naboo.cloud`, etc.) mediante un código temporal de un solo uso.

## Seguridad del flujo

- Cada subdominio conserva su propia base, `APP_KEY`, cookie y sesión.
- El navegador recibe únicamente un código aleatorio de 80 caracteres.
- La base central guarda solo el SHA-256 del código.
- El código expira en 60 segundos y se consume dentro de una transacción.
- Cada espacio tiene un `client_id` y un secreto almacenado con hash.
- El callback está registrado y no se aceptan destinos enviados por el navegador.
- El intercambio responde con `Cache-Control: no-store`.

## Desarrollo local

Requisitos: PHP 8.2+, Composer y SQLite o MySQL.

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

La aplicación quedará disponible en `http://127.0.0.1:8000/login`.

## Crear la configuración inicial

Crear o actualizar una identidad central. Si omites `--password`, la consola la solicitará sin mostrarla:

```bash
php artisan sso:user sts19813@gmail.com --name="Santos"
```

Registrar Tipi:

```bash
php artisan sso:workspace tipi "Tipi" \
  https://tipi.naboo.cloud \
  https://tipi.naboo.cloud/sso/callback
```

Registrar Tayde:

```bash
php artisan sso:workspace tayde "Tayde" \
  https://tayde.naboo.cloud \
  https://tayde.naboo.cloud/sso/callback
```

Cada comando muestra una sola vez `SSO_CLIENT_ID` y `SSO_CLIENT_SECRET`. Deben guardarse en el `.env` del subdominio correspondiente.

Conceder accesos:

```bash
php artisan sso:grant sts19813@gmail.com tipi
php artisan sso:grant sts19813@gmail.com tayde
```

Si el usuario solo tiene un espacio, entra automáticamente. Si tiene varios, se muestra el selector.

## Google OAuth

Configura en el `.env` central:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_CALLBACK=https://naboo.cloud/auth/google/callback
GOOGLE_PROMPT=select_account
```

La URI de redirección debe registrarse exactamente en Google Cloud Console. Un usuario nuevo de Google se crea sin espacios; un administrador debe concederle acceso con `sso:grant`.

## API de intercambio

El subdominio intercambia el código desde su servidor, nunca desde JavaScript:

```http
POST /api/sso/exchange
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

code=CODIGO_DE_80_CARACTERES
```

Respuesta:

```json
{
  "token_type": "sso_identity",
  "user": {
    "sub": "1",
    "email": "usuario@example.com",
    "name": "Usuario",
    "avatar_url": null,
    "email_verified": true,
    "workspace": "tipi"
  }
}
```

Consulta [la guía de integración de Naboo](docs/NABOO_CLIENT_INTEGRATION.md) y [la guía de despliegue](docs/DEPLOYMENT.md).

## Suscripciones por instancia

El portal central también controla la suscripción mensual de cada espacio:

- Propiedad sin renta activa: `$20.00 MXN` al mes por defecto.
- Propiedad rentada: `$40.00 MXN` al mes por defecto.
- Una propiedad es rentada únicamente cuando tiene `tenant_id` y al menos un cargo abierto (`pending`, `partial` o `in_validation`).
- Stripe Checkout registra la tarjeta y crea una suscripción con cobro automático mensual.
- Los webhooks habilitan, ponen en tolerancia o suspenden el acceso; el navegador nunca decide el estado.
- Los precios, días de tolerancia, responsables de pago y excepciones son configurables por espacio.

Configura un espacio existente y asigna a su responsable:

```bash
php artisan billing:configure tipi --vacant=20 --rented=40 --grace=5 --email=facturacion@example.com --enable
php artisan billing:access admin@example.com tipi --manager
```

Permite que un administrador específico conserve acceso aunque falle el pago:

```bash
php artisan billing:access soporte@example.com tipi --override
```

Consulta [la guía de facturación con Stripe](docs/STRIPE_BILLING.md) para el webhook y el paso de sandbox a producción.

## Validación

```bash
vendor/bin/pint --test
php artisan test
```
