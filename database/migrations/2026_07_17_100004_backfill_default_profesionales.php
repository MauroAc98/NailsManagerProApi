<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Runs the idempotent `profesionales:backfill-default` command as part of
     * the deploy itself. This is intentional, not just a nice-to-have: the
     * moment this deploy lands, TurnoController's default-profesional
     * resolution (the backward-compatibility mechanism for the RN app, which
     * never sends profesional_id) requires every existing account to already
     * have exactly one Profesional row. Leaving the backfill as a manual
     * post-deploy step would mean every existing user's turno creation
     * breaks the instant this migration runs and before someone remembers to
     * run the command by hand. Running it here guarantees zero downtime.
     *
     * Safe to run again: App\Console\Commands\BackfillProfesionalesDefault is
     * idempotent (skips users that already have a Profesional, only touches
     * slots/turnos rows with a null profesional_id).
     */
    public function up(): void
    {
        Artisan::call('profesionales:backfill-default');
    }

    public function down(): void
    {
        // No-op: reverting this migration should not delete the
        // profesionales/pivot rows it created, since 2026_07_17_100000 and
        // 2026_07_17_100001's down() already drop those tables entirely.
    }
};
