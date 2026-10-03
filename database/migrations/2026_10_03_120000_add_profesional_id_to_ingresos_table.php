<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->foreignId('profesional_id')
                ->nullable()
                ->after('user_id')
                ->constrained('profesionales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ingresos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profesional_id');
        });
    }
};
