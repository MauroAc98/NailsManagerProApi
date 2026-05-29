<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mp_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('mp_access_token');
            $table->string('mp_user_id', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mp_credentials');
    }
};