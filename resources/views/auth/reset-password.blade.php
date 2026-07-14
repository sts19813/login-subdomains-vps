@extends('layouts.auth')

@section('title', 'Nueva contraseña | '.config('app.name', 'Naboo'))

@section('content')
    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="auth-heading auth-heading-compact">
            <h1>Nueva contraseña</h1>
            <p>Protege tu cuenta con una contraseña nueva.</p>
        </div>

        @include('auth.partials.messages')

        <div class="field">
            <label for="email">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        </div>
        <div class="field">
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>
        </div>
        <div class="field">
            <label for="password_confirmation">Confirmar contraseña</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary">Guardar contraseña</button>
    </form>
@endsection
