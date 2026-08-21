<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthRegisterProfesionalNombreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_secret' => 'secreto-de-test']);
        Mail::fake();
    }

    public function test_register_crea_el_profesional_dueño_con_su_propio_nombre_no_el_del_estudio(): void
    {
        $response = $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->postJson('/api/auth/register', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_nombre' => 'Fernanda',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertCreated();

        $user = User::where('email', 'nueva@estudio.com')->firstOrFail();
        $profesional = Profesional::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Nails Studio', $user->name);
        $this->assertSame('Fernanda', $profesional->nombre);
        $this->assertSame('Gómez', $profesional->apellido);
        $this->assertSame('Fernanda Gómez', $profesional->nombre_completo);
    }

    public function test_register_sin_profesional_nombre_es_rechazado(): void
    {
        $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->postJson('/api/auth/register', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_apellido' => 'Gómez',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('profesional_nombre');

        $this->assertDatabaseMissing('users', ['email' => 'nueva@estudio.com']);
    }

    public function test_register_sin_profesional_apellido_es_rechazado(): void
    {
        $this->withHeader('X-Admin-Secret', 'secreto-de-test')
            ->postJson('/api/auth/register', [
                'name' => 'Nails Studio',
                'email' => 'nueva@estudio.com',
                'profesional_nombre' => 'Fernanda',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('profesional_apellido');

        $this->assertDatabaseMissing('users', ['email' => 'nueva@estudio.com']);
    }
}
