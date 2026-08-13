<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ConfigureBillingAccess extends Command
{
    protected $signature = 'billing:access
        {email : Correo del usuario}
        {workspace : Slug del espacio}
        {--manager : Permite gestionar el método de pago}
        {--no-manager : Retira la gestión del método de pago}
        {--override : Permite entrar sin suscripción activa}
        {--no-override : Retira la excepción de acceso}';

    protected $description = 'Configura responsables de pago y excepciones de acceso por instancia';

    public function handle(): int
    {
        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();
        $workspace = Workspace::query()->where('slug', (string) $this->argument('workspace'))->first();
        if (! $user || ! $workspace || ! $user->workspaces()->whereKey($workspace->getKey())->exists()) {
            $this->error('El usuario y el espacio deben existir, y el usuario debe tener acceso al espacio.');

            return self::FAILURE;
        }

        if (($this->option('manager') && $this->option('no-manager'))
            || ($this->option('override') && $this->option('no-override'))) {
            $this->error('Las opciones positivas y negativas no pueden usarse juntas.');

            return self::FAILURE;
        }

        $changes = [];
        if ($this->option('manager') || $this->option('no-manager')) {
            $changes['is_billing_manager'] = (bool) $this->option('manager');
        }
        if ($this->option('override') || $this->option('no-override')) {
            $changes['billing_access_override'] = (bool) $this->option('override');
        }

        if ($changes === []) {
            $this->error('Indica al menos una opción para modificar.');

            return self::FAILURE;
        }

        $user->workspaces()->updateExistingPivot($workspace->getKey(), $changes);
        $membership = $user->workspaces()->whereKey($workspace->getKey())->firstOrFail()->pivot;

        $this->info('Permisos de facturación actualizados.');
        $this->line('Responsable de pago: '.($membership->is_billing_manager ? 'sí' : 'no'));
        $this->line('Excepción de acceso: '.($membership->billing_access_override ? 'sí' : 'no'));

        return self::SUCCESS;
    }
}
