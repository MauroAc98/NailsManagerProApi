<?php

namespace Tests\Concerns;

use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Subscription;
use App\Models\User;

/**
 * Helpers para los tests de los endpoints publicos de reserva online.
 */
trait CreaSalonPublico
{
    private function crearSalon(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['is_exempt' => true], $attrs));

        return $user;
    }

    private function crearProfesional(User $user, string $nombre = 'Ana', bool $activo = true): Profesional
    {
        return Profesional::create([
            'user_id' => $user->id,
            'nombre'  => $nombre,
            'activo'  => $activo,
        ]);
    }

    private function crearServicio(User $user, string $nombre = 'Esmaltado', int $duracion = 45, bool $activo = true, ?Profesional $profesional = null): Servicio
    {
        $servicio = Servicio::create([
            'user_id'          => $user->id,
            'nombre'           => $nombre,
            'duracion_minutos' => $duracion,
            'precio'           => 12000,
            'activo'           => $activo,
        ]);

        if ($profesional) {
            $profesional->servicios()->syncWithoutDetaching([$servicio->id]);
        }

        return $servicio;
    }

    private function crearSlot(User $user, ?Profesional $profesional, string $hora, bool $activo = true): SlotDisponible
    {
        return SlotDisponible::create([
            'user_id'        => $user->id,
            'profesional_id' => $profesional?->id,
            'hora'           => $hora,
            'activo'         => $activo,
        ]);
    }

    private function crearSuscripcion(User $user, string $status = 'ACTIVO', ?\DateTimeInterface $endsAt = null): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'ends_at' => $endsAt ?? now()->addDays(10),
            'status'  => $status,
        ]);
    }
}
