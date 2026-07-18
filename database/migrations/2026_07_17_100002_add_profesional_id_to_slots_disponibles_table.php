<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slots_disponibles', function (Blueprint $table) {
            // Nullable on purpose: keeps the deploy backward compatible. Accounts
            // that predate multi-agenda get backfilled to their default
            // Profesional by the 2026_07_17_100004 migration, but we don't want
            // this column to ever be a hard requirement for existing rows in
            // case the backfill needs to be re-run or inspected before a future
            // migration tightens this to NOT NULL.
            $table->foreignId('profesional_id')
                ->nullable()
                ->after('user_id')
                ->constrained('profesionales')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('slots_disponibles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profesional_id');
        });
    }
};
