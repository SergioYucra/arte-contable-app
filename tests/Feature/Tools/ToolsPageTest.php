<?php

namespace Tests\Feature\Tools;

use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El balance de apertura se abre desde el catálogo de Herramientas (/herramientas).
 * Se arma en el navegador: no hay rutas propias ni se guarda nada.
 */
class ToolsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
    }

    private function student(bool $verified = true): User
    {
        $user = User::factory()->create(['email_verified_at' => $verified ? now() : null]);
        $user->assignRole('User');

        return $user;
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/herramientas')->assertRedirect(route('login'));
    }

    public function test_unverified_student_is_asked_to_verify_email(): void
    {
        $this->actingAs($this->student(verified: false))
            ->get('/herramientas')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_student_sees_the_opening_balance_tool_in_the_catalog(): void
    {
        $this->actingAs($this->student())
            ->get('/herramientas')
            ->assertOk()
            ->assertSee('data-vue-island="StudentToolsCatalog"', escape: false)
            ->assertSee('CrearBalanceAperturaTool')
            ->assertSee('Crear Balance de apertura');
    }
}
