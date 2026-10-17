<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Marks the links whose ACCOUNT was created by that very invitation. It is
    // what lets the persona titular see and reset only the accounts her own
    // invitation created, and nothing about accounts that already existed.
    // Existing rows (and every link to a pre-existing account) stay false.
    public function up(): void
    {
        Schema::table('profesional_vinculos', function (Blueprint $table) {
            $table->boolean('cuenta_creada_aqui')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('profesional_vinculos', function (Blueprint $table) {
            $table->dropColumn('cuenta_creada_aqui');
        });
    }
};
