<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->timestamps();
        });

        // Plain Eloquent unique(['user_id', 'nombre']) is case-sensitive on
        // Postgres (prod), which would let "Manos" and "manos" both insert
        // with no DB-level guard against a race or a direct insert bypassing
        // the app-level check. This expression index closes that gap for
        // plain ASCII names. It is NOT a full Unicode-aware guarantee —
        // SQL LOWER() doesn't reliably fold accented Spanish letters
        // (á/é/í/ó/ú/ñ) the same way on every engine/locale, so the
        // authoritative case-insensitive check (accent-correct, via PHP
        // mb_strtolower) lives in CategoriaServicioController::rules().
        // This index is a best-effort DB-level backstop, not the source
        // of truth for that rule.
        DB::statement('CREATE UNIQUE INDEX categorias_servicio_user_id_nombre_lower_unique ON categorias_servicio (user_id, lower(nombre))');
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_servicio');
    }
};
