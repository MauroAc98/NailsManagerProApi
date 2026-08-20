<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('whatsapp_estado_historiales');
    }

    public function down(): void
    {
        Schema::create('whatsapp_estado_historiales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('estado');
            $table->unsignedSmallInteger('status_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }
};
