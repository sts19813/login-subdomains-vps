# Integración del cliente Naboo

Esta guía se aplica por separado en Tipi y Tayde. Cada sitio usa credenciales distintas.

## Variables de entorno

```dotenv
CENTRAL_SSO_URL=https://naboo.cloud
CENTRAL_SSO_WORKSPACE=tipi
CENTRAL_SSO_CLIENT_ID=
CENTRAL_SSO_CLIENT_SECRET=
```

Para Tayde cambia `CENTRAL_SSO_WORKSPACE=tayde` y utiliza las credenciales emitidas para Tayde.

## Configuración Laravel

Agrega en `config/services.php`:

```php
'central_sso' => [
    'url' => env('CENTRAL_SSO_URL', 'https://naboo.cloud'),
    'workspace' => env('CENTRAL_SSO_WORKSPACE'),
    'client_id' => env('CENTRAL_SSO_CLIENT_ID'),
    'client_secret' => env('CENTRAL_SSO_CLIENT_SECRET'),
],
```

## Identificador central

Crea una migración en cada instalación:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('central_sso_id')->nullable()->unique()->after('id');
});
```

Agrega `central_sso_id` a `$fillable` en `App\Models\User`.

## Rutas

```php
Route::middleware('guest')->group(function () {
    Route::get('/sso/login', [CentralSsoController::class, 'redirect'])->name('sso.login');
    Route::get('/sso/callback', [CentralSsoController::class, 'callback'])->name('sso.callback');
});
```

## Controlador receptor

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class CentralSsoController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $url = rtrim(config('services.central_sso.url'), '/').'/login?'.http_build_query([
            'workspace' => config('services.central_sso.workspace'),
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:80'],
            'workspace' => ['required', 'in:'.config('services.central_sso.workspace')],
        ]);

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth(
                config('services.central_sso.client_id'),
                config('services.central_sso.client_secret'),
            )
            ->timeout(10)
            ->post(rtrim(config('services.central_sso.url'), '/').'/api/sso/exchange', [
                'code' => $validated['code'],
            ]);

        if (! $response->successful()) {
            return redirect()->route('login')->withErrors([
                'email' => 'El acceso central expiró o no pudo validarse.',
            ]);
        }

        $identity = $response->json('user');
        if (($identity['workspace'] ?? null) !== config('services.central_sso.workspace')) {
            abort(403);
        }

        $user = User::query()
            ->where('central_sso_id', (string) $identity['sub'])
            ->orWhere('email', strtolower((string) $identity['email']))
            ->first();

        if (! $user || ! $user->is_active || ! $user->hasSystemAccess()) {
            return redirect()->route('login')->withErrors([
                'email' => 'Tu identidad es válida, pero aún no tienes un rol activo en este espacio.',
            ]);
        }

        $user->forceFill([
            'central_sso_id' => (string) $identity['sub'],
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
```

El cliente no crea usuarios ni roles automáticamente. La identidad debe existir localmente y conservar sus permisos específicos de Tipi o Tayde.

## Cambio en el login

El botón principal del login local puede apuntar a `route('sso.login')`. Conserva temporalmente el formulario local como acceso de emergencia hasta validar el despliegue central.

## Cierre de sesión

El cierre local invalida únicamente la sesión del subdominio. La sesión central permanece activa para permitir entrar nuevamente sin escribir contraseña. Se puede añadir cierre global en una segunda fase.
