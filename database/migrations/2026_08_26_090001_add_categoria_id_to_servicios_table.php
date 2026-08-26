<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            $table->foreignId('categoria_id')
                ->nullable()
                ->after('orden')
                ->constrained('categorias_servicio')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categoria_id');
        });
    }
};
