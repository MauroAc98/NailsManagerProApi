<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('confirmacion_automatica', 'recordatorio_automatico');
            $table->string('hora_recordatorio', 5)->default('20:00')->after('recordatorio_automatico');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hora_recordatorio');
            $table->renameColumn('recordatorio_automatico', 'confirmacion_automatica');
        });
    }
};