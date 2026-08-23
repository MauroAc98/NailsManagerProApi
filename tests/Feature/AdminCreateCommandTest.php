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
