<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['evolution_instance_name', 'whatsapp_estado', 'whatsapp_provider']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('evolution_instance_name')->nullable()->after('mensaje_whatsapp');
            $table->string('whatsapp_estado')->default('desconectado')->after('evolution_instance_name');
            $table->string('whatsapp_provider')->default('evolution')->after('evolution_instance_name');
        });
    }
};
