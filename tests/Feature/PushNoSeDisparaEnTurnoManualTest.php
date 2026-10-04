<?php

namespace Tests\Feature;

use App\Jobs\EnviarPushReservaOnline;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Contract\AdminContractTestCase;

class PushNoSeDisparaEnTurnoManualTest extends AdminContractTestCase
{
    public function test_a_turno_created_manually_from_the_agenda_does_not_send_a_push(): void
    {
        $this->admin()->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => '2099-06-10 10:00:00',
        ])->assertCreated();

        Queue::assertNotPushed(EnviarPushReservaOnline::class);
    }
}
