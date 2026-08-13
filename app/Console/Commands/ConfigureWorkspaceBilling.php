<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;

class ConfigureWorkspaceBilling extends Command
{
    protected $signature = 'billing:configure
        {workspace : Slug del espacio}
        {--vacant= : Precio mensual MXN por propiedad sin renta activa}
        {--rented= : Precio mensual MXN por propiedad rentada}
        {--grace= : Días de tolerancia después de un pago fallido}
        {--email= : Correo de facturación}
        {--enable : Exigir suscripción para entrar}
        {--disable : Dejar de exigir suscripción}';

    protected $description = 'Configura precios, tolerancia y exigencia de suscripción de una instancia';

    public function handle(): int
    {
        $workspace = Workspace::query()->where('slug', (string) $this->argument('workspace'))->first();
        if (! $workspace) {
            $this->error('No se encontró el espacio.');

            return self::FAILURE;
        }

        if ($this->option('enable') && $this->option('disable')) {
            $this->error('Usa solo --enable o --disable.');

            return self::FAILURE;
        }

        $vacant = $this->moneyOption('vacant');
        $rented = $this->moneyOption('rented');
        if ($vacant === false || $rented === false) {
            return self::FAILURE;
        }

        $grace = $this->option('grace');
        if ($grace !== null && (! ctype_digit((string) $grace) || (int) $grace > 90)) {
            $this->error('La tolerancia debe ser un entero entre 0 y 90 días.');

            return self::FAILURE;
        }

        $priceChanged = ($vacant !== null && $vacant !== $workspace->vacant_property_unit_amount)
            || ($rented !== null && $rented !== $workspace->rented_property_unit_amount);

        $workspace->forceFill(array_filter([
            'vacant_property_unit_amount' => $vacant,
            'rented_property_unit_amount' => $rented,
            'billing_grace_days' => $grace === null ? null : (int) $grace,
            'billing_email' => $this->option('email'),
        ], fn ($value) => $value !== null));

        if ($this->option('enable')) {
            $workspace->billing_enforced = true;
        }
        if ($this->option('disable')) {
            $workspace->billing_enforced = false;
        }

        if ($priceChanged) {
            $workspace->stripe_vacant_price_id = null;
            $workspace->stripe_rented_price_id = null;
            $workspace->stripe_sync_pending = (bool) $workspace->stripe_subscription_id;
        }

        $workspace->save();

        $this->info('Facturación actualizada para '.$workspace->name.'.');
        $this->table(['Concepto', 'Valor'], [
            ['Propiedad sin renta activa', $this->formatMoney($workspace->vacant_property_unit_amount)],
            ['Propiedad rentada', $this->formatMoney($workspace->rented_property_unit_amount)],
            ['Tolerancia', $workspace->billing_grace_days.' días'],
            ['Suscripción exigida', $workspace->billing_enforced ? 'Sí' : 'No'],
        ]);

        return self::SUCCESS;
    }

    private function moneyOption(string $name): int|false|null
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1000000) {
            $this->error("--{$name} debe ser un importe válido entre 0 y 1,000,000 MXN.");

            return false;
        }

        return (int) round((float) $value * 100);
    }

    private function formatMoney(int $amount): string
    {
        return '$'.number_format($amount / 100, 2).' MXN';
    }
}
