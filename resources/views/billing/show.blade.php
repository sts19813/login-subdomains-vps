@extends('layouts.auth')

@section('title', 'Suscripción de '.$workspace->name.' | '.config('app.name', 'Naboo'))

@section('content')
    @php
        $statusLabels = [
            'active' => 'Activa',
            'trialing' => 'En prueba',
            'past_due' => 'Pago pendiente',
            'unpaid' => 'Sin pagar',
            'incomplete' => 'Pago incompleto',
            'incomplete_expired' => 'Registro vencido',
            'canceled' => 'Cancelada',
            'paused' => 'Pausada',
        ];
        $status = $workspace->subscription_status;
    @endphp

    <div class="auth-heading auth-heading-compact">
        <span class="eyebrow">Suscripción · {{ $workspace->name }}</span>
        <h1>{{ $canAccess ? 'Tu acceso está disponible' : 'Regulariza tu suscripción' }}</h1>
        <p>El importe se calcula con la información más reciente reportada por esta instancia.</p>
    </div>

    @include('auth.partials.messages')

    @if (request('checkout') === 'success')
        <div class="alert alert-success">Stripe recibió el registro. El estado se actualizará en cuanto llegue la confirmación segura.</div>
    @elseif (request('checkout') === 'cancelled')
        <div class="alert alert-danger">El proceso de pago fue cancelado; no se realizó ningún cargo.</div>
    @endif

    <div class="billing-status-row">
        <span>Estado</span>
        <strong class="status-pill {{ $canAccess ? 'status-pill-ok' : 'status-pill-warning' }}">
            @if (! $workspace->billing_enforced)
                Cobro no exigido
            @elseif ($workspace->metrics_reported_at && $workspace->property_count === 0)
                Sin costo
            @else
                {{ $statusLabels[$status] ?? 'Sin suscripción' }}
            @endif
        </strong>
    </div>

    <div class="billing-breakdown">
        <div class="billing-line">
            <span>
                <strong>{{ $workspace->vacantPropertyCount() }}</strong> propiedades sin renta activa
                <small>${{ number_format($workspace->vacant_property_unit_amount / 100, 2) }} c/u</small>
            </span>
            <strong>${{ number_format(($workspace->vacantPropertyCount() * $workspace->vacant_property_unit_amount) / 100, 2) }}</strong>
        </div>
        <div class="billing-line">
            <span>
                <strong>{{ $workspace->rented_property_count }}</strong> propiedades rentadas
                <small>Con inquilino y cobranza pendiente · ${{ number_format($workspace->rented_property_unit_amount / 100, 2) }} c/u</small>
            </span>
            <strong>${{ number_format(($workspace->rented_property_count * $workspace->rented_property_unit_amount) / 100, 2) }}</strong>
        </div>
        <div class="billing-total">
            <span>Mensual estimado</span>
            <strong>${{ number_format($workspace->calculatedMonthlyAmount() / 100, 2) }} MXN</strong>
        </div>
    </div>

    <p class="billing-meta">
        @if ($workspace->metrics_reported_at)
            Medición recibida {{ $workspace->metrics_reported_at->diffForHumans() }}.
        @else
            La instancia aún no ha enviado su primera medición.
        @endif
        @if ($workspace->billing_grace_ends_at?->isFuture())
            Acceso en tolerancia hasta {{ $workspace->billing_grace_ends_at->translatedFormat('d M Y, H:i') }}.
        @endif
    </p>

    @if ($canManage)
        @if ($workspace->stripe_customer_id && $workspace->stripe_subscription_id && ! in_array($status, ['canceled', 'incomplete_expired'], true))
            <form method="POST" action="{{ route('billing.portal', $workspace) }}">
                @csrf
                <button type="submit" class="btn btn-primary">Administrar pago en Stripe</button>
            </form>
        @elseif ($workspace->property_count > 0)
            <form method="POST" action="{{ route('billing.checkout', $workspace) }}">
                @csrf
                <button type="submit" class="btn btn-primary">Domiciliar pago mensual</button>
            </form>
        @else
            <button type="button" class="btn btn-disabled" disabled>Esperando propiedades por facturar</button>
        @endif
    @else
        <div class="empty-state billing-contact">
            <strong>Se requiere un responsable de facturación</strong>
            <p>Pide al administrador asignado a esta instancia que registre o actualice el método de pago.</p>
        </div>
    @endif

    @if ($canAccess)
        <form method="POST" action="{{ route('workspaces.launch', $workspace) }}" class="billing-secondary-action">
            @csrf
            <button type="submit" class="btn btn-google">Entrar a {{ $workspace->name }}</button>
        </form>
    @endif

    <a class="back-link" href="{{ route('workspaces.index') }}">← Volver a mis espacios</a>
@endsection
