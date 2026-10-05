# Reserva online: runbook (holds y creacion)

## Variables de entorno / config (todas opcionales; defaults seguros)

| Variable | Default | Que hace |
|---|---|---|
| `RESERVAS_CREACION_HABILITADA` | `false` | Kill switch de los endpoints de escritura (holds/datos/pago/estado/liberar). Apagado => 503 `creation_disabled`. La disponibilidad (lecturas) no depende de esto. |
| `RESERVAS_HOLD_MINUTOS` / `RESERVAS_HOLD_MINUTOS_ALTA` | `10` / `5` | Duracion del hold (normal / alta ocupacion). |
| `RESERVAS_PAGO_MINUTOS` / `RESERVAS_PAGO_MINUTOS_ALTA` | `15` / `10` | Ventana de pago al iniciar el checkout (se extiende una sola vez). |
| `RESERVAS_OCUPACION_ALTA_UMBRAL` | `0.7` | Fraccion de slots ocupados que activa la duracion corta. |
| `RESERVAS_COOLDOWN_TELEFONO_MINUTOS` | `30` | Espera de un telefono tras un hold vencido sin pago. |
| `RESERVAS_VERIFICACION_HABILITADA` | `false` | Enforce de verificacion de WhatsApp (el estado se registra siempre). Sin proveedor real todavia: dejar apagado. |
| `RESERVAS_VERIFICACION_UMBRAL` / `_VENTANA_HORAS` / `_VALIDEZ_HORAS` | `2` / `24` / `24` | Vencidos sin pago que exigen verificacion, ventana de conteo y vigencia de una verificacion. |
| `RESERVAS_CHALLENGE_HABILITADO` | `false` | Exige Turnstile al crear un hold. |
| `TURNSTILE_SECRET` | (vacio) | Secret de Cloudflare Turnstile (solo si el reto esta habilitado; falla cerrado si falta). |
| `FRONTEND_URL` | (existente) | Base del `checkout_url` stub (`{FRONTEND_URL}/reservar/{slug}/reserva/{token}?stub=1`). |

`RESERVAS_VENTANA_PAGO_MINUTOS` ya no existe (los holds leen `expira_en`).

## Scheduler

Debe existir el cron estandar de Laravel (`* * * * * php artisan schedule:run`). La entrada nueva ya esta en `routes/console.php`:

```
Schedule::command('reservas:expirar-holds')->everyMinute()->withoutOverlapping();
```

La correctitud NO depende del job: la disponibilidad y los chequeos filtran siempre por `expira_en`; el job ordena estados y aplica la reputacion (tambien se expira de forma lazy en hold/datos/estado).

## Deploy (seguir el proceso de deploy del backend)

1. `git pull` + `composer install --no-dev --optimize-autoloader`.
2. `php artisan migrate` (migracion `2026_09_19_100000_add_holds_to_reservas_web_table`: aditiva y reversible; convierte `estado` de enum a string, hace nullable `nombre_completo`/`telefono`, crea el indice unico parcial y `reserva_reputaciones`). En Postgres borra el CHECK `reservas_web_estado_check`.
3. `php artisan config:cache && php artisan route:cache` (las rutas nuevas y los rate limiters se registran en el arranque).
4. Dejar `RESERVAS_CREACION_HABILITADA=false`. Smoke con el flag en `true` SOLO en un salon de prueba, despues de la migracion.

Rollback: poner el flag en `false` (escrituras 503; la disponibilidad sigue igual) y, si hace falta, `php artisan migrate:rollback --step=1` (mapea held/pending_payment -> pending_payment, confirmed -> accepted, cancelled -> rejected antes de restaurar el enum).

## Smoke checklist (salon de prueba, flag en true)

1. `GET disponibilidad` lista un horario H.
2. `POST reservas/holds` (headers `X-Device-Token`, `Idempotency-Key`) => 201; el mismo request repetido => 200 con el mismo `token`.
3. `GET disponibilidad`: H ya no aparece para esa profesional (si para otras).
4. Desde la agenda de la duena, agendar un turno en H => 422 con `code: slot_held`.
5. `PUT reservas/{token}/datos` => 200; `POST reservas/{token}/pago` => 200 con `checkout_url` stub y `expira_en_ms` +15 min; repetir el pago no mueve la expiracion.
6. `DELETE reservas/{token}` => 204 (idempotente); H vuelve a estar disponible.
7. Esperar el vencimiento: `GET reservas/{token}` => `estado: expired` (200).

## Test de concurrencia opt-in (Postgres LOCAL)

Solo contra una base local de pruebas (docker). El test se saltea solo si no estan `ALLOW_PGSQL_CONCURRENCY_TEST=1`, `DB_CONNECTION=pgsql` y un host local. NUNCA apuntarlo a produccion ni al VPS.

```
docker run --rm -d --name nails-pg -e POSTGRES_PASSWORD=pw -e POSTGRES_DB=nails_test -p 5433:5432 postgres:16
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=nails_test DB_USERNAME=postgres DB_PASSWORD=pw php artisan migrate
ALLOW_PGSQL_CONCURRENCY_TEST=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=nails_test DB_USERNAME=postgres DB_PASSWORD=pw \
  php artisan test --filter=ReservaHoldsConcurrenciaPgsqlTest
```

Lanza 8 procesos (`tests/Support/hold_worker.php`) que intentan retener el mismo horario a la vez: debe haber exactamente 1 ganador y 7 `slot_taken`. Cubre `pg_advisory_xact_lock` + indice unico parcial (en sqlite solo se prueba el camino `lockForUpdate` y la carrera simulada por seam).

## Notas operativas

- Todo writer nuevo de holds/turnos online debe pasar por `SlotLock` (el advisory lock solo protege a quien lo usa). La duena agenda sin lock: el conflicto se re-verifica al confirmar (`needs_refund`).
- `requiere_reembolso = true` marca una reserva cuya sena se COBRO pero no pudo convertirse en turno (`needs_refund`: horario ocupado, vencido y tomado, datos incompletos, sin profesional). El reembolso sigue siendo MANUAL (no hay ejecutor automatico), pero la duena ya se entera:
  - Web Push "Sena cobrada sin turno" (job `EnviarPushReembolsoReserva`, best-effort, solo si tiene dispositivos suscriptos).
  - `GET /api/turnos/notificaciones` devuelve `reembolsos_pendientes` (ultimos 30 dias: `reserva_id`, cliente, `fecha`, `hora`, `monto`, `mp_payment_id`). Todavia no existe un estado "reembolsado": la fila sale de la lista a los 30 dias.
  - Pasos manuales: abrir Mercado Pago de la cuenta del negocio > Actividad, buscar el pago por `mp_payment_id` (o por la referencia externa = `public_token` de la reserva) > "Devolver dinero". Avisar a la clienta por WhatsApp. Si hace falta cerrar la marca: `UPDATE reservas_web SET requiere_reembolso = false WHERE id = <reserva_id>` (cuidando de verificar el entorno antes).
  - Una order de MP no vence a los 10-15 min del hold: la clienta puede pagar tarde y por eso cae en `slot_taken_after_expiry` (ver logs `reserva.needs_refund`). El campo `expiration_time` de Orders API no se usa todavia (documentacion ambigua sobre rango minimo y ubicacion).
- Pagos aprobados: `sincronizarPago` marca el pago `aprobado` y confirma la reserva en UNA transaccion; si la confirmacion lanza, el pago vuelve a `pendiente` y el webhook (5xx) o `pagos:reconciliar` lo reintentan. El reconciliador aisla cada fila (un error se loguea como `mercadopago.reconciliar.fila_fallo` y sigue con la siguiente).
- Logs sin PII: eventos `reserva.hold.*`, `reserva.confirmed`, `reserva.needs_refund`, `reserva.abuse.*` con `user_id`, `profesional_id`, `reserva_id`, `device_prefix`, `motivo`.
