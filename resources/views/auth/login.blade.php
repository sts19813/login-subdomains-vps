@extends('layouts.auth')

@section('title', 'Iniciar sesión | '.config('app.name', 'Naboo'))

@section('content')
    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <div class="auth-heading">
            <h1>Iniciar sesión</h1>
            <img src="{{ asset('assets/img/naboo-logo.svg') }}" alt="Naboo" class="naboo-login-logo">
            <p>Accede a tu cuenta de {{ config('app.name', 'Naboo') }}</p>
        </div>

        @include('auth.partials.messages')

        @if (filled(config('services.google.client_id')))
            <a href="{{ route('auth.google.redirect') }}" class="btn btn-google">
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                    <path fill="#4285F4" d="M17.64 9.205c0-.638-.057-1.252-.164-1.841H9v3.482h4.844a4.14 4.14 0 0 1-1.797 2.716v2.258h2.909c1.702-1.567 2.684-3.874 2.684-6.615Z"/>
                    <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.91-2.258c-.806.54-1.835.859-3.046.859-2.344 0-4.328-1.585-5.037-3.714H.956v2.333A9 9 0 0 0 9 18Z"/>
                    <path fill="#FBBC05" d="M3.963 10.707A5.41 5.41 0 0 1 3.682 9c0-.592.102-1.168.281-1.707V4.96H.956A9 9 0 0 0 0 9c0 1.452.347 2.827.956 4.04l3.007-2.333Z"/>
                    <path fill="#EA4335" d="M9 3.58c1.321 0 2.507.454 3.441 1.346l2.581-2.58C13.463.892 11.426 0 9 0A9 9 0 0 0 .956 4.96l3.007 2.333C4.672 5.165 6.656 3.58 9 3.58Z"/>
                </svg>
                Continuar con Google
            </a>
            <div class="separator"><span>o con correo</span></div>
        @endif

        <div class="field">
            <label for="email">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" placeholder="Correo electrónico" required autofocus>
        </div>

        <div class="field field-password">
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Contraseña" required>
        </div>

        <div class="login-options">
            <label class="checkbox-label">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>Recordarme</span>
            </label>
            <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
        </div>

        <button type="submit" class="btn btn-primary">Entrar</button>
    </form>
@endsection
