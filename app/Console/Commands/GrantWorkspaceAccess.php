<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GrantWorkspaceAccess extends Command
{
    protected $signature = 'sso:grant {email : Correo del usuario} {workspace : Slug del espacio}';

    protected $description = 'Autoriza a una identidad central para entrar a un espacio';

    public function handle(): int
    {
        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();
        $workspace = Workspace::query()->where('slug', (string) $this->argument('workspace'))->first();

        if (! $user || ! $workspace) {
            $this->error('No se encontró el usuario o el espacio.');

            return self::FAILURE;
        }

        $user->workspaces()->syncWithoutDetaching([
            $workspace->getKey() => ['is_active' => true],
        ]);

        $this->info("Acceso concedido: {$user->email} → {$workspace->slug}");

        return self::SUCCESS;
    }
}
