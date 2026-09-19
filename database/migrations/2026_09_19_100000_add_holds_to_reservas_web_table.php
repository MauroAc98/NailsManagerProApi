<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDICE_SLOT = 'reservas_web_slot_vivo_unique';

    /**
     * Reserva online (slice 3): reservas_web pasa a ser tambien el "hold" de un
     * horario. Aditivo: no borra columnas ni datos. El estado deja de ser un
     * enum cerrado (string) y se crea reserva_reputaciones para los cooldowns.
     */
    public function up(): void
    {
        // 1) estado: enum -> string(20) default 'held'.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservas_web DROP CONSTRAINT IF EXISTS reservas_web_estado_check');
        }
        Schema::table('reservas_web', function (Blueprint $table) {
            $table->string('estado', 20)->default('held')->change();
        });

        // 2) nombre_completo / telefono: el hold nace antes que los datos.
        Schema::table('reservas_web', function (Blueprint $table) {
            $table->string('nombre_completo', 200)->nullable()->change();
            $table->string('telefono', 30)->nullable()->change();
        });

        // 3) columnas nuevas.
        Schema::table('reservas_web', function (Blueprint $table) {
            $table->char('public_token', 40)->nullable()->unique();
            $table->foreignId('profesional_id')->nullable()->constrained('profesionales')->nullOnDelete();
            $table->unsignedBigInteger('expira_en')->nullable();
            $table->boolean('pago_extendido')->default(false);
            $table->boolean('alta_ocupacion')->default(false);
            $table->char('device_hash', 64)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('nombre', 100)->nullable();
            $table->string('apellido', 100)->nullable();
            $table->string('nota', 300)->nullable();
            $table->boolean('requiere_reembolso')->default(false);
            $table->unsignedBigInteger('confirmada_en')->nullable();
            $table->string('motivo_cierre', 30)->nullable();

            $table->index(['profesional_id', 'fecha', 'estado']);
            $table->index(['estado', 'expira_en']);
            $table->index(['device_hash', 'estado']);
            $table->unique(['device_hash', 'idempotency_key']);
        });

        // 4) Ultima linea de defensa contra doble reserva del mismo inicio.
        // Indice unico PARCIAL (pg y sqlite): solo cuenta holds vivos.
        DB::statement(
            'CREATE UNIQUE INDEX ' . self::INDICE_SLOT
            . ' ON reservas_web (profesional_id, fecha, slot_hora)'
            . " WHERE estado IN ('held', 'pending_payment')"
        );

        Schema::create('reserva_reputaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // device | phone
            $table->char('key_hash', 64);
            $table->unsignedInteger('expirados_sin_pago')->default(0);
            $table->unsignedBigInteger('bloqueado_hasta')->nullable();
            $table->unsignedBigInteger('verificado_hasta')->nullable();
            $table->unsignedBigInteger('ultimo_expirado_en')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'key_hash']);
        });
    }

    /**
     * Mapea los estados nuevos a los legacy ANTES de restaurar el enum
     * (held/pending_payment -> pending_payment, confirmed -> accepted,
     * cancelled -> rejected) y rellena '' donde el NOT NULL lo exige.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserva_reputaciones');

        DB::statement('DROP INDEX IF EXISTS ' . self::INDICE_SLOT);

        DB::table('reservas_web')->where('estado', 'held')->update(['estado' => 'pending_payment']);
        DB::table('reservas_web')->where('estado', 'confirmed')->update(['estado' => 'accepted']);
        DB::table('reservas_web')->where('estado', 'cancelled')->update(['estado' => 'rejected']);
        DB::table('reservas_web')->whereNull('nombre_completo')->update(['nombre_completo' => '']);
        DB::table('reservas_web')->whereNull('telefono')->update(['telefono' => '']);

        Schema::table('reservas_web', function (Blueprint $table) {
            $table->dropUnique(['device_hash', 'idempotency_key']);
            $table->dropIndex(['device_hash', 'estado']);
            $table->dropIndex(['estado', 'expira_en']);
            $table->dropIndex(['profesional_id', 'fecha', 'estado']);
            $table->dropUnique(['public_token']);
            $table->dropConstrainedForeignId('profesional_id');
        });

        Schema::table('reservas_web', function (Blueprint $table) {
            $table->dropColumn([
                'public_token', 'expira_en', 'pago_extendido', 'alta_ocupacion',
                'device_hash', 'idempotency_key', 'nombre', 'apellido', 'nota',
                'requiere_reembolso', 'confirmada_en', 'motivo_cierre',
            ]);
        });

        Schema::table('reservas_web', function (Blueprint $table) {
            $table->string('nombre_completo', 200)->nullable(false)->change();
            $table->string('telefono', 30)->nullable(false)->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reservas_web ALTER COLUMN estado SET DEFAULT 'pending_payment'");
            DB::statement("ALTER TABLE reservas_web ADD CONSTRAINT reservas_web_estado_check CHECK (estado IN ('pending_payment', 'accepted', 'rejected', 'expired'))");
        } else {
            Schema::table('reservas_web', function (Blueprint $table) {
                $table->enum('estado', ['pending_payment', 'accepted', 'rejected', 'expired'])->default('pending_payment')->change();
            });
        }
    }
};
