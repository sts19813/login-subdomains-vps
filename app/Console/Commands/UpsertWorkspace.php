<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UpsertWorkspace extends Command
{
    protected $signature = 'sso:workspace
        {slug : Identificador corto, por ejemplo tipi}
        {name : Nombre visible}
        {base_url : URL principal HTTPS}
        {callback_url : Callback SSO HTTPS del subdominio}
        {--rotate-secret : Reemplaza el secreto si el espacio ya existe}';

    protected $description = 'Crea o actualiza un espacio autorizado para recibir inicios de sesión';

    public function handle(): int
    {
        $slug = Str::slug((string) $this->argument('slug'));
        $baseUrl = rtrim((string) $this->argument('base_url'), '/');
        $callbackUrl = (string) $this->argument('callback_url');

        if (
            $slug === ''
            || ! $this->validUrl($baseUrl)
            || ! $this->validUrl($callbackUrl)
            || parse_url($baseUrl, PHP_URL_HOST) !== parse_url($callbackUrl, PHP_URL_HOST)
        ) {
            $this->error('Las URLs deben ser válidas, usar HTTPS y pertenecer al mismo host.');

            return self::FAILURE;
        }

        $workspace = Workspace::query()->where('slug', $slug)->first();
        $mustGenerateCredentials = ! $workspace || $this->option('rotate-secret');
        $plainSecret = $mustGenerateCredentials ? Str::random(64) : null;

        $workspace ??= new Workspace;
        $workspace->fill([
            'name' => (string) $this->argument('name'),
            'slug' => $slug,
            'base_url' => $baseUrl,
            'callback_url' => $callbackUrl,
            'is_active' => true,
        ]);

        if (! $workspace->client_id) {
            $workspace->client_id = 'naboo_'.$slug.'_'.Str::lower(Str::random(20));
        }

        if ($plainSecret !== null) {
            $workspace->client_secret_hash = Hash::make($plainSecret);
        }

        $workspace->save();

        $this->info('Espacio guardado: '.$workspace->name);
        $this->line('SSO_CLIENT_ID='.$workspace->client_id);

        if ($plainSecret !== null) {
            $this->warn('Guarda este secreto ahora; no volverá a mostrarse:');
            $this->line('SSO_CLIENT_SECRET='.$plainSecret);
        }

        return self::SUCCESS;
    }

    private function validUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true));
    }
}
