<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesional_cuentas', function (Blueprint $table) {
            $table->id();
            // Lowercased + trimmed by the model mutator: the Postgres unique
            // index is case-sensitive, so normalization has to happen on write.
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('activo')->default(true);
            // Fail-closed: a new account is provisional until the first change.
            $table->boolean('debe_cambiar_password')->default(true);
            $table->timestamp('ultimo_login_at')->nullable();
            $table->string('ultimo_login_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    // When rolling back both portal migrations, profesional_vinculos is
    // dropped first (it references this table).
    public function down(): void
    {
        Schema::dropIfExists('profesional_cuentas');
    }
};
