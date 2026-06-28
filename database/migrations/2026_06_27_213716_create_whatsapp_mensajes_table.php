<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('turno_id')->nullable()->constrained()->nullOnDelete();
            $table->string('numero', 20);
            $table->text('mensaje');
            $table->string('tipo', 20); // 'confirmacion' | 'recordatorio'
            $table->string('message_id', 100)->nullable()->index();
            $table->string('status', 20)->default('pending'); // pending | delivered | read | failed
            $table->unsignedTinyInteger('intentos')->default(1);
            $table->timestamp('ultimo_intento')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensajes');
    }
};