<?php

namespace App\Console\Commands;

use App\Services\Portal\EmitirCodigoReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the recovery codes of the professionals' portal that nobody needs any
 * more.
 *
 * A row is two things: a code (alive until `expira_en`) and the memory of the
 * forgot cooldown (`ultimo_envio_en`). After an exhausted code the cooldown is
 * LONGER than the life of the code, so pruning by `expira_en` alone would erase
 * the memory while the cooldown still runs and let a new forgot restart the
 * attempts. A row therefore goes only when its code has expired AND the longest
 * cooldown the forgot flow can impose since its last send is over.
 *
 * Both comparisons are epoch integers (the columns are not datetimes), so
 * app.timezone does not matter. Rows go in batches of ids, and every batch
 * repeats the conditions in the DELETE: a code replaced by a new forgot between
 * the SELECT and the DELETE is live again and must survive.
 */
class PurgarCodigosResetProfesional extends Command
{
    private const LOTE = 500;

    protected $signature = 'profesionales:purgar-codigos-reset';

    protected $description = 'Borra los códigos de recuperación del portal de profesionales vencidos cuya espera ya terminó';

    public function handle(): int
    {
        $ahora = now()->timestamp;
        $espera = EmitirCodigoReset::ENFRIAMIENTO_TRAS_AGOTAR_SEGUNDOS;
        $borrados = 0;

        do {
            $ids = DB::table(EmitirCodigoReset::TABLA)
                ->where('expira_en', '<=', $ahora)
                ->where('ultimo_envio_en', '<=', $ahora - $espera)
                ->orderBy('id')
                ->limit(self::LOTE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $borrados += DB::table(EmitirCodigoReset::TABLA)
                ->whereIn('id', $ids->all())
                ->where('expira_en', '<=', $ahora)
                ->where('ultimo_envio_en', '<=', $ahora - $espera)
                ->delete();
        } while ($ids->count() === self::LOTE);

        $this->info("Códigos de recuperación del portal purgados: {$borrados}");

        return self::SUCCESS;
    }
}
