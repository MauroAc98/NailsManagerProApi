<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Password-recovery codes of the professionals' portal. Own table, keyed by
    // account id: the tenant flow keys `password_reset_tokens` by email, which
    // would collide with the tenant user of the same email.
    //
    // One row per account = one live code. The attempts counter lives here (not
    // in the cache) so flushing the cache cannot reset it, and the row outlives
    // an exhausted code so `ultimo_envio_en` keeps enforcing the cooldown.
    //
    // Times are epoch seconds in plain integers, not datetimes: app.timezone is
    // not UTC and a datetime cast round-trip shifts the instant (precedent:
    // whatsapp_mensajes.status_event_at).
    public function up(): void
    {
        Schema::create('profesional_cuenta_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->unique()->constrained('profesional_cuentas')->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedBigInteger('expira_en');
            $table->unsignedBigInteger('ultimo_envio_en');
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->timestamps();

            // Daily prune of expired codes.
            $table->index('expira_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profesional_cuenta_reset_codes');
    }
};
