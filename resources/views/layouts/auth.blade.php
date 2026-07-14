<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Naboo').' | Acceso')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/naboo-mark.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}">
</head>
<body class="naboo-auth">
    <div class="naboo-auth-shell">
        <aside class="naboo-auth-brand">
            <a href="{{ url('/') }}" class="naboo-auth-logo" aria-label="Ir al inicio de Naboo">
                <img alt="Naboo" src="{{ asset('assets/img/naboo-logo-white.svg') }}">
            </a>

            <div class="naboo-auth-brand-copy">
                <span class="naboo-auth-kicker">Administración inmobiliaria</span>
                <h2>Todo tu portafolio.<br>Un solo lugar.</h2>
                <p>Propiedades, expedientes, cobranza, inventarios y mantenimiento con una experiencia clara y ordenada.</p>
            </div>

            <div class="naboo-auth-brand-footer">
                <span class="naboo-auth-brand-dot"></span>
                <span>Control simple. Decisiones claras.</span>
            </div>
        </aside>

        <main class="naboo-auth-main">
            <div class="naboo-auth-mobile-brand">
                <a href="{{ url('/') }}" aria-label="Ir al inicio de Naboo">
                    <img alt="Naboo" src="{{ asset('assets/img/naboo-logo.svg') }}">
                </a>
            </div>

            <div class="naboo-auth-form-wrap">
                <div class="naboo-auth-card">
                    @yield('content')
                </div>
            </div>

            <div class="naboo-auth-copyright">
                &copy; {{ now()->year }} {{ config('app.name', 'Naboo') }}
            </div>
        </main>
    </div>
</body>
</html>
