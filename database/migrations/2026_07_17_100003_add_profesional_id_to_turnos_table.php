<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            // Nullable on purpose — see 2026_07_17_100002 for the full reasoning.
            // Kept optional so this deploy never breaks the mobile app before the
            // backfill runs, and so a future migration can safely tighten it to
            // NOT NULL once the backfill is verified in production.
            $table->foreignId('profesional_id')
                ->nullable()
                ->after('user_id')
                ->constrained('profesionales')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profesional_id');
        });
    }
};
