<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->string('provider', 20)->default('evolution')->after('numero');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
