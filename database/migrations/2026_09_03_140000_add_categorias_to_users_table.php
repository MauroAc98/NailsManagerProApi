<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user custom category lists for gastos and ingresos. Both are
     * nullable — a null column means "this user never customised, use the
     * factory default set" (resolved by the User model accessor). Purely
     * additive: the gastos.categoria / ingresos.categoria string columns
     * are untouched and no data is migrated.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('categorias_gasto')->nullable()->after('locale');
            $table->json('categorias_ingreso')->nullable()->after('categorias_gasto');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['categorias_gasto', 'categorias_ingreso']);
        });
    }
};
