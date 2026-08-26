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
        // while app-level validation only catches the second one on the
        // request path — no real guard against a race or a direct insert.
        // An expression index on lower(nombre) closes that gap and is
        // supported by both Postgres (prod) and sqlite (local dev).
        DB::statement('CREATE UNIQUE INDEX categorias_servicio_user_id_nombre_lower_unique ON categorias_servicio (user_id, lower(nombre))');
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_servicio');
    }
};
