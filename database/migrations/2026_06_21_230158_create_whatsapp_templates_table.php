<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // tipo: 'recordatorio' | 'confirmacion'
            $table->enum('tipo', ['recordatorio', 'confirmacion']);
            
            // La plantilla con placeholders: {nombre}, {apellido}, {servicios}, {fecha}, {hora}, {negocio}
            $table->text('contenido');
            
            $table->timestamps();
            
            // Un usuario solo puede tener una plantilla por tipo
            $table->unique(['user_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};