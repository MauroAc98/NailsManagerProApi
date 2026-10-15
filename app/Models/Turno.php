<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Turno extends Model
{
    protected $fillable = [
        'user_id',
        'profesional_id',
        'cliente_id',
        'reserva_web_id',
        'grupo_id',
        'lote_id',
        'fecha_hora',
        'duracion_total_minutos',
        'estado',
        'motivo_cancelacion',
        'cancelado_en',
        'origen',
        'notas',
    ];

    /**
     * Evita que Carbon serialice las fechas convertidas a UTC (con "Z").
     * Así fecha_hora se devuelve tal cual está guardada
     * (hora local de Argentina), sin desfases en el frontend.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'cancelado_en' => 'datetime',
            'duracion_total_minutos' => 'integer',
        ];
    }

    // ── Scopes de seguridad ──────────────────────────────────────
    public function scopeDelUsuario($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeConfirmados($query)
    {
        return $query->where('estado', 'confirmado');
    }

    /**
     * Turnos de un día, como rango [día 00:00, día siguiente 00:00). Reemplaza
     * whereDate('fecha_hora', ...), que en Postgres compila a fecha_hora::date = ?
     * y no puede usar el índice (user_id, fecha_hora, estado): era el filtro de
     * cada carga de la agenda. Mismo resultado: la columna es timestamp sin zona
     * y los límites son hora de pared (ver serializeDate), sin conversión.
     */
    public function scopeDelaFecha($query, string $fecha)
    {
        $inicio = self::inicioDelDia($fecha);

        return $query->where('fecha_hora', '>=', $inicio->toDateTimeString())
            ->where('fecha_hora', '<', $inicio->copy()->addDay()->toDateTimeString());
    }

    /** Turnos entre dos días, ambos inclusive: [desde 00:00, día siguiente a hasta 00:00). */
    public function scopeDelRango($query, string $desde, string $hasta)
    {
        return $query->where('fecha_hora', '>=', self::inicioDelDia($desde)->toDateTimeString())
            ->where('fecha_hora', '<', self::inicioDelDia($hasta)->addDay()->toDateTimeString());
    }

    /**
     * Medianoche del día que nombra $valor. Se queda con la parte "YYYY-MM-DD"
     * tal cual (como el cast a date de Postgres, que ignora hora y zona del
     * texto); cualquier otro formato que acepte la regla `date` va por Carbon.
     */
    private static function inicioDelDia(string $valor): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $valor, $m)) {
            return Carbon::createFromFormat('!Y-m-d', $m[0]);
        }

        return Carbon::parse($valor)->startOfDay();
    }

    /**
     * Turnos de un mes "YYYY-MM", como rango [día 1 00:00, día 1 del mes
     * siguiente 00:00). Reemplaza TO_CHAR(fecha_hora, 'YYYY-MM') = ?, que
     * Postgres no puede resolver con el índice de fecha_hora y que SQLite ni
     * siquiera tiene. Los límites son hora de pared, igual que lo guardado
     * (ver serializeDate): no hay conversión de zona horaria. Un mes mal
     * formado no trae nada (mismo resultado que tenía la comparación de texto).
     */
    public function scopeDelMes($query, string $mes)
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            return $query->whereRaw('1 = 0');
        }

        $inicio = Carbon::createFromFormat('!Y-m-d', $mes.'-01');

        return $query->where('fecha_hora', '>=', $inicio->toDateTimeString())
            ->where('fecha_hora', '<', $inicio->copy()->addMonth()->toDateTimeString());
    }

    /**
     * Turnos cuyo intervalo [fecha_hora, fecha_hora + duracion) se solapa con
     * [$inicio, $fin) (semi-abierto: los intervalos adyacentes NO se solapan).
     * Componer con confirmados() / where('profesional_id', ...) segun el caso.
     *
     * Produccion es PostgreSQL; la rama sqlite existe solo para la suite de tests.
     */
    public function scopeSolapaCon($query, \DateTimeInterface|string $inicio, \DateTimeInterface|string $fin)
    {
        $inicio = Carbon::parse($inicio)->format('Y-m-d H:i:s');
        $fin = Carbon::parse($fin)->format('Y-m-d H:i:s');

        $finExistente = $query->getConnection()->getDriverName() === 'sqlite'
            ? "datetime(fecha_hora, '+' || duracion_total_minutos || ' minutes')"
            : "fecha_hora + (duracion_total_minutos || ' minutes')::interval";

        return $query->whereRaw("fecha_hora < ? AND {$finExistente} > ?", [$fin, $inicio]);
    }

    // ── Helpers de estado ────────────────────────────────────────
    public function estaConfirmado(): bool
    {
        return $this->estado === 'confirmado';
    }

    public function fueCreadoDesdeWeb(): bool
    {
        return $this->origen === 'web';
    }

    /**
     * Adds the additive `sena` key to the serialised turnos (agenda "Cobros").
     *
     * `sena` is null when the turno has no reserva web or that reserva has no
     * PagoSena; otherwise { monto, estado, reserva_web_id }. `monto` is what
     * the client was CHARGED (stored PagoSena.monto: exactly the deposit,
     * no commission gross-up), which for percentage mode is computed on the
     * reserva total. `estado` is the raw value; only 'aprobado' means paid
     * ('pendiente', 'rechazado', 'expirado' are exposed as-is for the UI to
     * decide). No paid-at key: pagos_sena has no dedicated timestamp.
     *
     * The seña is RESERVATION-level: every turno of a combo/grupo shares one
     * reserva_web and therefore one PagoSena, so each of them carries the same
     * object (same reserva_web_id). Consumers summing across turnos must
     * de-duplicate by reserva_web_id.
     *
     * Explicit (not an $appends accessor) so serialising a Turno elsewhere
     * (jobs, reports) never lazy-loads. Eager-loads reservaWeb.pagoSena once
     * for the whole collection (constant queries) and keeps the payload
     * otherwise unchanged: pago_sena is hidden from a nested reserva_web, and
     * reserva_web is hidden if the caller had not loaded it.
     *
     * @param  iterable<Turno>  $turnos
     */
    public static function adjuntarSena(iterable $turnos): void
    {
        $coleccion = \Illuminate\Database\Eloquent\Collection::make($turnos);
        $yaCargado = $coleccion->mapWithKeys(fn (Turno $t) => [$t->id => $t->relationLoaded('reservaWeb')]);

        $coleccion->loadMissing('reservaWeb.pagoSena');

        foreach ($coleccion as $turno) {
            $pago = $turno->reservaWeb?->pagoSena;
            $turno->setAttribute('sena', $pago === null ? null : [
                'monto' => (float) $pago->monto,
                'estado' => $pago->estado,
                'reserva_web_id' => $pago->reserva_web_id,
            ]);

            $turno->reservaWeb?->makeHidden('pagoSena');
            if (! $yaCargado[$turno->id]) {
                $turno->makeHidden('reservaWeb');
            }
        }
    }

    // ── Relaciones ───────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profesional()
    {
        return $this->belongsTo(Profesional::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function reservaWeb()
    {
        return $this->belongsTo(ReservaWeb::class);
    }

    public function grupo()
    {
        return $this->belongsTo(TurnoGrupo::class, 'grupo_id');
    }

    public function servicios()
    {
        return $this->belongsToMany(Servicio::class, 'turno_servicio')->withPivot('precio', 'precio_sugerido');
    }

    public function whatsappMensajes()
    {
        return $this->hasMany(WhatsappMensaje::class);
    }

    /**
     * Latest WhatsApp message of ANY tipo (confirmacion, recordatorio,
     * reprogramacion). ofMany resolves "latest" per turno in a single
     * subquery, so it stays correct when eager loading many turnos.
     */
    public function ultimoWhatsappMensaje()
    {
        return $this->hasOne(WhatsappMensaje::class)->ofMany([
            'created_at' => 'max',
            'id' => 'max',
        ]);
    }
}
