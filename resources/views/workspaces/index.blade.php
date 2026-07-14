@extends('layouts.auth')

@section('title', 'Selecciona tu espacio | '.config('app.name', 'Naboo'))

@section('content')
    <div class="auth-heading auth-heading-compact">
        <span class="eyebrow">Sesión iniciada</span>
        <h1>¿A dónde quieres entrar?</h1>
        <p>Selecciona el espacio de trabajo asignado a tu cuenta.</p>
    </div>

    @include('auth.partials.messages')

    @if ($workspaces->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">!</div>
            <strong>Aún no tienes espacios asignados</strong>
            <p>Solicita a un administrador que habilite el acceso para {{ auth()->user()->email }}.</p>
        </div>
    @else
        <div class="workspace-list">
            @foreach ($workspaces as $workspace)
                <form method="POST" action="{{ route('workspaces.launch', $workspace) }}">
                    @csrf
                    <button type="submit" class="workspace-card">
                        <span class="workspace-mark">{{ mb_strtoupper(mb_substr($workspace->name, 0, 1)) }}</span>
                        <span class="workspace-copy">
                            <strong>{{ $workspace->name }}</strong>
                            <small>{{ parse_url($workspace->base_url, PHP_URL_HOST) }}</small>
                        </span>
                        <span class="workspace-arrow" aria-hidden="true">→</span>
                    </button>
                </form>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="logout-form">
        @csrf
        <button type="submit" class="link-button">Cerrar sesión</button>
    </form>
@endsection
