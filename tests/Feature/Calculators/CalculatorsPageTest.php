<?php

namespace Tests\Feature\Calculators;

use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las calculadoras RC-IVA y Bono de antigüedad se abren desde el catálogo de
 * Calculadoras (/calculadoras). Calculan en el navegador: no hay rutas propias.
 */
class CalculatorsPageTest extends TestCase
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
        $this->get('/calculadoras')->assertRedirect(route('login'));
    }

    public function test_unverified_student_is_asked_to_verify_email(): void
    {
        $this->actingAs($this->student(verified: false))
            ->get('/calculadoras')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_student_sees_both_calculators_in_the_catalog(): void
    {
        $this->actingAs($this->student())
            ->get('/calculadoras')
            ->assertOk()
            ->assertSee('data-vue-island="StudentCalculatorsCatalog"', escape: false)
            ->assertSee('RcIvaCalculator')
            ->assertSee('BonoAntiguedadCalculator');
    }

    public function test_the_daily_check_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('calculators:check')
            ->assertSuccessful();
    }
}
