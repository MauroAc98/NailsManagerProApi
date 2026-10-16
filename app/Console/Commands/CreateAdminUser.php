<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AdminAuthController;
use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {--name=} {--email=} {--password=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea (o actualiza la contraseña de) el admin del panel — única vía de provisioning del guard admin.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Nombre');
        $email = strtolower((string) ($this->option('email') ?? $this->ask('Email')));

        // Con --password no se pide confirmación (uso no interactivo:
        // scripts de deploy, este test suite); interactivo sí confirma para
        // evitar un typo en la única credencial que abre el panel. Preferí
        // el prompt oculto: --password queda en el historial del shell y en
        // la lista de procesos.
        $password = $this->option('password');
        if ($password === null) {
            $password = $this->secret('Contraseña');
            $confirmacion = $this->secret('Confirmá la contraseña');

            if ($password !== $confirmacion) {
                $this->error('Las contraseñas no coinciden.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => 'required|string|max:255',
                'email' => 'required|email',
                'password' => 'required|string|min:' . AdminAuthController::PASSWORD_MIN,
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existia = AdminUser::where('email', $email)->exists();

        $admin = AdminUser::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        // Cambiar la contraseña de un admin existente es la respuesta típica a
        // una credencial comprometida: las sesiones abiertas no pueden sobrevivir.
        if ($existia) {
            $admin->tokens()->delete();
        }

        $this->info("Admin {$admin->email} listo.");

        return self::SUCCESS;
    }
}
