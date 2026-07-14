# Despliegue en CloudPanel

## 1. Crear el sitio

Crea `naboo.cloud` como sitio PHP en CloudPanel, configura PHP 8.4 y emite un certificado Let's Encrypt válido.

El directorio público de Nginx debe apuntar al directorio `public` de Laravel. Una estructura compatible con los sitios existentes es:

```text
~/htdocs/.naboo-login-app          aplicación Laravel
~/htdocs/naboo.cloud               enlace a .naboo-login-app/public
```

## 2. Instalar

```bash
cd ~/htdocs
git clone https://github.com/sts19813/login-subdomains-vps.git .naboo-login-app
cd .naboo-login-app
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
php artisan key:generate
```

Configura al menos:

```dotenv
APP_NAME=Naboo
APP_ENV=production
APP_DEBUG=false
APP_URL=https://naboo.cloud

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null

SSO_CODE_TTL_SECONDS=60
```

`SESSION_DOMAIN=null` mantiene la cookie central limitada a `naboo.cloud`; no debe compartirse con los subdominios.

## 3. Preparar y activar

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache

cd ~/htdocs
mv naboo.cloud naboo.cloud-placeholder-$(date +%Y%m%d-%H%M%S)
ln -s /home/USUARIO/htdocs/.naboo-login-app/public naboo.cloud
```

Sustituye `USUARIO` por el usuario real del sitio.

Configura el cron de Laravel:

```cron
* * * * * cd /home/USUARIO/htdocs/.naboo-login-app && php artisan schedule:run >> /dev/null 2>&1
```

## 4. Actualizaciones

```bash
cd ~/htdocs/.naboo-login-app
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

## 5. Verificación

```bash
curl -I https://naboo.cloud/login
curl -I https://naboo.cloud/up
php artisan migrate:status
php artisan schedule:list
```
