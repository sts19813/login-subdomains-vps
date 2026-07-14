@extends('layouts.auth')

@section('title', 'Recuperar contraseña | '.config('app.name', 'Naboo'))

@section('content')
    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf
        <div class="auth-heading auth-heading-compact">
            <h1>Recuperar contraseña</h1>
            <p>Escribe tu correo y te enviaremos las instrucciones de acceso.</p>
        </div>

        @include('auth.partials.messages')

        <div class="field">
            <label for="email">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" placeholder="Correo electrónico" required autofocus>
        </div>

        <button type="submit" class="btn btn-primary">Enviar enlace</button>
        <a href="{{ route('login') }}" class="back-link">Volver a iniciar sesión</a>
    </form>
@endsection
