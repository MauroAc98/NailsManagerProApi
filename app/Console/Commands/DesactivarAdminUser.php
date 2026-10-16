<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DesactivarAdminUser extends Command
{
    protected $signature = 'admin:desactivar {email} {--reactivar : Vuelve a activar un admin desactivado}';

    protected $description = 'Desactiva un admin del panel (y revoca sus tokens), o lo reactiva con --reactivar.';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $admin = AdminUser::where('email', $email)->first();

        if (! $admin) {
            $this->error("No existe un admin con el email {$email}.");

            return self::FAILURE;
        }

        if ($this->option('reactivar')) {
            $admin->activo = true;
            $admin->save();
            Log::info('admin:desactivar: admin reactivado', ['admin_id' => $admin->id]);
            $this->info("Admin {$admin->email} reactivado.");

            return self::SUCCESS;
        }

        $admin->activo = false;
        $admin->save();
        $admin->tokens()->delete();
        Log::info('admin:desactivar: admin desactivado', ['admin_id' => $admin->id]);
        $this->info("Admin {$admin->email} desactivado y sus sesiones revocadas.");

        return self::SUCCESS;
    }
}
