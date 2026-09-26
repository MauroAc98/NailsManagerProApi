<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            // null = turno suelto (todo turno existente).
            $table->foreignId('grupo_id')->nullable()->constrained('turno_grupos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grupo_id');
        });
    }
};
