<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisiona_un_admin_con_password_hasheado(): void
    {
        $this->artisan('admin:create', [
            '--name' => 'Superadmin',
            '--email' => 'ADMIN@Turnetto.app',
            '--password' => 'password-seguro',
        ])->assertExitCode(0);

        $admin = AdminUser::where('email', 'admin@turnetto.app')->first();

        $this->assertNotNull($admin);
        $this->assertSame('Superadmin', $admin->name);
        $this->assertTrue(Hash::check('password-seguro', $admin->password));
    }

    public function test_es_idempotente_actualiza_el_password_si_el_email_ya_existe(): void
    {
        AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => Hash::make('password-vieja'),
        ]);

        $this->artisan('admin:create', [
            '--name' => 'Superadmin',
            '--email' => 'admin@turnetto.app',
            '--password' => 'password-nueva',
        ])->assertExitCode(0);

        $this->assertSame(1, AdminUser::count());

        $admin = AdminUser::where('email', 'admin@turnetto.app')->first();
        $this->assertTrue(Hash::check('password-nueva', $admin->password));
    }

    public function test_actualizar_la_password_de_un_admin_existente_revoca_sus_tokens(): void
    {
        $admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => Hash::make('password-vieja-12'),
        ]);
        $admin->createToken('admin-panel');
        $admin->createToken('otro');

        $this->artisan('admin:create', [
            '--name' => 'Superadmin',
            '--email' => 'admin@turnetto.app',
            '--password' => 'password-nueva-123',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_pide_la_password_por_prompt_oculto_si_no_se_pasa_la_opcion(): void
    {
        $this->artisan('admin:create', ['--name' => 'Superadmin', '--email' => 'admin@turnetto.app'])
            ->expectsQuestion('Contraseña', 'password-por-prompt-1')
            ->expectsQuestion('Confirmá la contraseña', 'password-por-prompt-1')
            ->assertExitCode(0);

        $admin = AdminUser::where('email', 'admin@turnetto.app')->first();
        $this->assertTrue(Hash::check('password-por-prompt-1', $admin->password));
    }

    public function test_rechaza_password_de_11_caracteres(): void
    {
        $this->artisan('admin:create', [
            '--name' => 'Superadmin',
            '--email' => 'admin@turnetto.app',
            '--password' => '12345678901',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('admin_users', ['email' => 'admin@turnetto.app']);
    }

    public function test_rechaza_password_corta(): void
    {
        $this->artisan('admin:create', [
            '--name' => 'Superadmin',
            '--email' => 'admin@turnetto.app',
            '--password' => 'corta',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('admin_users', ['email' => 'admin@turnetto.app']);
    }
}
