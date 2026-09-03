<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Purely additive. Meta's delivery-failure webhook (status = 'failed')
     * carries an `errors[]` array describing why the message could not be
     * delivered. Until now that reason was only written to laravel.log; these
     * columns persist it on the row so it can be surfaced in the app and
     * queried. `respuesta_api` / `status_code` describe the SEND response, not
     * the async delivery failure — hence separate columns.
     */
    public function up(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->integer('error_code')->nullable()->after('status_code');      // errors[0].code, e.g. 131026
            $table->string('error_titulo', 255)->nullable()->after('error_code'); // errors[0].title
            $table->string('error_detalle', 500)->nullable()->after('error_titulo'); // errors[0].error_data.details, fallback errors[0].message
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->dropColumn(['error_code', 'error_titulo', 'error_detalle']);
        });
    }
};
