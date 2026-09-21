<?php

namespace Tests\Feature;

use App\Mail\ResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Frenos contra abuso en el flujo de contrasenas: enumeracion de emails,
 * spam de mails de reseteo y fuerza bruta del codigo de 6 digitos o de la
 * contrasena provisoria.
 */
class AuthPasswordAbuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_responde_igual_para_un_email_inexistente_y_no_envia_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nadie@ejemplo.com'])
            ->assertOk()
            ->assertJson(['message' => 'Te enviamos un código a tu email.']);

        Mail::assertNothingSent();
    }

    public function test_forgot_password_sigue_enviando_el_codigo_a_un_email_registrado(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertSent(ResetCodeMail::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => strtolower($user->email)]);
    }

    public function test_forgot_password_tiene_limite_de_pedidos_por_minuto(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    public function test_reset_password_invalida_el_codigo_tras_5_intentos_fallidos(): void
    {
        $user = User::factory()->create();
        DB::table('password_reset_tokens')->insert([
            'email' => strtolower($user->email),
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', $this->cuerpoReset($user, '000000'))
                ->assertStatus(422);
        }

        // Ya con el codigo correcto: el codigo se invalido, hay que pedir uno nuevo.
        $this->postJson('/api/auth/reset-password', $this->cuerpoReset($user, '123456'))
            ->assertStatus(422);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => strtolower($user->email)]);
    }

    public function test_reset_password_funciona_con_el_codigo_correcto_antes_del_limite(): void
    {
        $user = User::factory()->create();
        DB::table('password_reset_tokens')->insert([
            'email' => strtolower($user->email),
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', $this->cuerpoReset($user, '000000'))->assertStatus(422);
        $this->postJson('/api/auth/reset-password', $this->cuerpoReset($user, '123456'))->assertOk();

        $this->assertTrue(Hash::check('NuevaClave123', $user->fresh()->password));
    }

    public function test_reset_password_no_revela_si_el_email_existe(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'email' => 'nadie@ejemplo.com',
            'code' => '123456',
            'password' => 'NuevaClave123',
            'password_confirmation' => 'NuevaClave123',
        ])->assertStatus(422)->assertJsonValidationErrors('code')->assertJsonMissingValidationErrors('email');
    }

    public function test_cambiar_password_obligatorio_tiene_limite_de_intentos(): void
    {
        $user = User::factory()->create(['debe_cambiar_password' => true]);
        $cuerpo = [
            'email' => $user->email,
            'password_actual' => 'incorrecta',
            'password' => 'NuevaClave123',
            'password_confirmation' => 'NuevaClave123',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/cambiar-password-obligatorio', $cuerpo)->assertStatus(422);
        }

        $this->postJson('/api/auth/cambiar-password-obligatorio', $cuerpo)->assertStatus(429);
    }

    private function cuerpoReset(User $user, string $codigo): array
    {
        return [
            'email' => $user->email,
            'code' => $codigo,
            'password' => 'NuevaClave123',
            'password_confirmation' => 'NuevaClave123',
        ];
    }
}
