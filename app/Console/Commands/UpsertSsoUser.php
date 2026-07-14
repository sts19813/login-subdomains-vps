<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UpsertSsoUser extends Command
{
    protected $signature = 'sso:user
        {email : Correo del usuario}
        {--name= : Nombre completo}
        {--password= : Contraseña inicial; si se omite se solicitará de forma segura al crear}
        {--inactive : Guarda la cuenta desactivada}';

    protected $description = 'Crea o actualiza una identidad central';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $password = (string) ($this->option('password') ?? '');

        if (! $user && $password === '') {
            $password = (string) $this->secret('Contraseña inicial (mínimo 8 caracteres)');
        }

        if ($password !== '' && strlen($password) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        $user ??= new User;
        $user->name = (string) ($this->option('name') ?: $user->name ?: Str::before($email, '@'));
        $user->email = $email;
        $user->is_active = ! $this->option('inactive');
        $user->email_verified_at ??= now();

        if ($password !== '') {
            $user->password = Hash::make($password);
        }

        $user->save();

        $this->info('Usuario guardado: '.$user->email);

        return self::SUCCESS;
    }
}
