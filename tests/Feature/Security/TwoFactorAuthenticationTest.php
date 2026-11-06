<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSettingsController;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Support\Security\TwoFactorAuthenticator;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * P4 (H1, A07:2025): verificación en dos pasos, obligatoria para Administradores y Superadministradores.
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
        $this->withoutAutoTwoFactor();
    }

    /**
     * @return array{0: User, 1: string, 2: list<string>}
     */
    private function userWithTwoFactor(string $role = 'Admin'): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $twoFactor = app(TwoFactorAuthenticator::class);
        $secret = $twoFactor->generateSecret();
        $codes = $twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes['hashes'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user->fresh(), $secret, $codes['plain']];
    }

    private function currentCode(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function wrongCode(string $secret): string
    {
        return str_pad((string) (((int) $this->currentCode($secret) + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function startLogin(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));
    }

    // ---------------------------------------------------------------- obligatoriedad

    public function test_admin_without_two_factor_is_sent_to_the_setup_page(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('two-factor.show'));
        $this->actingAs($admin)->get(route('admin.users.index'))->assertRedirect(route('two-factor.show'));

        $this->actingAs($admin)
            ->patchJson(route('admin.users.update-role', $admin), ['role' => 'Manager'])
            ->assertForbidden()
            ->assertJson(['message' => EnsureTwoFactorIsEnabled::MESSAGE]);

        $this->assertTrue($admin->fresh()->hasRole('Admin'));
    }

    public function test_regular_users_are_not_forced_to_enable_two_factor(): void
    {
        $student = User::factory()->create();
        $student->assignRole('User');

        $this->actingAs($student)->get('/dashboard')->assertOk();
    }

    // ---------------------------------------------------------------- activación

    public function test_two_factor_settings_require_a_recent_password_confirmation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));

        $this->actingAs($admin)->post(route('two-factor.enable'))->assertRedirect(route('two-factor.show'));
        $this->assertNull(session(TwoFactorSettingsController::PENDING_SECRET_KEY));
    }

    public function test_two_factor_is_enabled_only_after_confirming_a_valid_code(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post(route('two-factor.enable'))->assertRedirect(route('two-factor.show'));
        $secret = session(TwoFactorSettingsController::PENDING_SECRET_KEY);
        $this->assertNotEmpty($secret);

        $this->get(route('two-factor.show'))->assertOk()->assertSee('<svg', false)->assertSeeText($secret);

        // Un código incorrecto no activa nada.
        $this->post(route('two-factor.confirm'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->assertFalse($admin->fresh()->hasEnabledTwoFactorAuthentication());

        $this->post(route('two-factor.confirm'), ['code' => $this->currentCode($secret)])
            ->assertRedirect(route('two-factor.show'))
            ->assertSessionHas(TwoFactorSettingsController::RECOVERY_CODES_FLASH_KEY, fn (array $codes) => count($codes) === TwoFactorAuthenticator::RECOVERY_CODE_COUNT);

        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'causer_id' => $admin->id, 'description' => 'Verificación en dos pasos activada.']);

        // Con 2FA activo, el panel ya abre.
        $this->get('/dashboard')->assertOk();
    }

    public function test_secrets_are_encrypted_at_rest_and_never_serialized(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, (string) $raw);
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }

    // ---------------------------------------------------------------- login

    public function test_login_with_two_factor_requires_the_code_before_starting_the_session(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->startLogin($user);
        $this->assertGuest();

        $this->post(route('two-factor.challenge'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.challenge'), ['code' => $this->currentCode($secret)])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_never_reaches_the_two_factor_step(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])->assertSessionHasErrors('email');

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $code = $this->currentCode($secret);

        $this->startLogin($user);
        $this->post(route('two-factor.challenge'), ['code' => $code]);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->assertGuest();

        $this->startLogin($user);
        $this->post(route('two-factor.challenge'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_codes_work_once(): void
    {
        [$user, , $recoveryCodes] = $this->userWithTwoFactor();

        $this->startLogin($user);
        $this->post(route('two-factor.challenge'), ['recovery_code' => $recoveryCodes[0]])
            ->assertRedirect(route('two-factor.show'));

        $this->assertAuthenticatedAs($user);
        $this->assertCount(TwoFactorAuthenticator::RECOVERY_CODE_COUNT - 1, $user->fresh()->two_factor_recovery_codes);

        $this->post('/logout');

        $this->startLogin($user);
        $this->post(route('two-factor.challenge'), ['recovery_code' => $recoveryCodes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_too_many_wrong_codes_require_the_password_again_and_alert_superadmins(): void
    {
        Notification::fake();

        $superAdmin = User::role('SuperAdmin')->firstOrFail();
        [$user, $secret] = $this->userWithTwoFactor();

        $this->startLogin($user);

        for ($i = 1; $i < TwoFactorChallengeController::MAX_ATTEMPTS; $i++) {
            $this->post(route('two-factor.challenge'), ['code' => $this->wrongCode($secret)])->assertSessionHasErrors('code');
        }

        $this->post(route('two-factor.challenge'), ['code' => $this->wrongCode($secret)])->assertRedirect(route('login'));

        // El login pendiente se descartó: el código correcto ya no sirve sin volver a poner la contraseña.
        $this->post(route('two-factor.challenge'), ['code' => $this->currentCode($secret)])->assertRedirect(route('login'));
        $this->assertGuest();

        Notification::assertSentTo($superAdmin, SecurityAlertNotification::class);
    }

    public function test_the_pending_login_expires(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->withSession([TwoFactorChallengeController::SESSION_KEY => [
            'id' => $user->id,
            'remember' => false,
            'expires_at' => now()->subMinute()->getTimestamp(),
        ]]);

        $this->post(route('two-factor.challenge'), ['code' => $this->currentCode($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---------------------------------------------------------------- desactivación y recuperación

    public function test_disabling_two_factor_on_an_admin_account_alerts_superadmins(): void
    {
        Notification::fake();

        $superAdmin = User::role('SuperAdmin')->firstOrFail();
        [$admin] = $this->userWithTwoFactor('Admin');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('two-factor.show'));

        $this->assertFalse($admin->fresh()->hasEnabledTwoFactorAuthentication());
        Notification::assertSentTo($superAdmin, SecurityAlertNotification::class);

        // Como su nivel la exige, sin 2FA vuelve a la página de configuración.
        $this->actingAs($admin->fresh())->get('/dashboard')->assertRedirect(route('two-factor.show'));
    }

    public function test_reset_command_disables_two_factor_and_logs_it(): void
    {
        [$admin] = $this->userWithTwoFactor('Admin');

        $this->artisan('user:reset-two-factor', ['email' => $admin->email])
            ->expectsConfirmation("¿Desactivar la verificación en dos pasos de {$admin->name} ({$admin->email})? Hazlo solo si confirmaste su identidad por otro medio.", 'yes')
            ->assertSuccessful();

        $this->assertFalse($admin->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'subject_id' => $admin->id, 'description' => 'Verificación en dos pasos desactivada desde la consola del servidor.']);
    }
}
