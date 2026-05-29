<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('slug', 100)->unique()->after('name');
            $table->string('telefono', 30)->nullable()->after('email');
            $table->string('direccion')->nullable()->after('telefono');
            $table->boolean('activo')->default(true)->after('direccion');
            $table->date('fecha_vencimiento')->nullable()->after('activo');
            $table->boolean('confirmacion_automatica')->default(false)->after('fecha_vencimiento');
            $table->decimal('sena_monto', 10, 2)->nullable()->after('confirmacion_automatica');
            $table->string('fcm_token')->nullable()->after('sena_monto');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'slug', 'telefono', 'direccion', 'activo',
                'fecha_vencimiento', 'confirmacion_automatica',
                'sena_monto', 'fcm_token',
            ]);
        });
    }
};