<?php

use App\Actions\BackfillSubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensancha el CHECK de subscriptions.status para admitir SUSPENDIDO
     * además de ACTIVO / VENCIDO. Aditivo: no borra columnas ni datos.
     * Después reconcilia las filas existentes contra ends_at (la columna
     * nunca se actualiza sola). Ninguna fila se pone en SUSPENDIDO acá.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_status_check');
            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('ACTIVO', 'SUSPENDIDO', 'VENCIDO'))");
        } else {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->enum('status', ['ACTIVO', 'SUSPENDIDO', 'VENCIDO'])->default('ACTIVO')->change();
            });
        }

        app(BackfillSubscriptionStatus::class)->handle();
    }

    /**
     * Mapea SUSPENDIDO -> VENCIDO ANTES de re-angostar el CHECK, así el
     * down no rompe por el valor que dejó de ser válido. Los datos quedan
     * (solo se pierde la distinción suspendido/vencido).
     */
    public function down(): void
    {
        DB::table('subscriptions')->where('status', 'SUSPENDIDO')->update(['status' => 'VENCIDO']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_status_check');
            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('ACTIVO', 'VENCIDO'))");
        } else {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->enum('status', ['ACTIVO', 'VENCIDO'])->default('ACTIVO')->change();
            });
        }
    }
};
