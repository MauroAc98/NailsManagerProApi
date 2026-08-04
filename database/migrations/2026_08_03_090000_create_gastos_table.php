<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profesional_id')->nullable()->constrained('profesionales')->nullOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 10, 2);
            $table->string('categoria', 40);
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');
    }
};
