<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\RegistrationAttemptNotification;
use App\Notifications\SecurityAlertNotification;
use App\Rules\Turnstile;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Revisión de seguridad OWASP Top 10:2025 (REVISION-SEGURIDAD.pdf): autenticación (A07) y registro (A09).
 * V2, V3, V4, V5, P5, P7 y P9.
 */
class OwaspReviewAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);
    }

    private function registrationPayload(string $email): array
    {
        return [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Password123!#$',
            'password_confirmation' => 'Password123!#$',
        ];
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Navegador de otra persona',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    // ---------------------------------------------------------------- V2: enumeración

    public function test_forgot_password_answers_the_same_for_active_deactivated_and_unknown_emails(): void
    {
        Notification::fake();

        $active = User::factory()->create(['email' => 'activa@example.com']);
        $deactivated = User::factory()->create(['email' => 'inactiva@example.com']);
        $deactivated->delete();

        foreach (['activa@example.com', 'inactiva@example.com', 'nadie@example.com'] as $email) {
            $this->from('/forgot-password')
                ->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', PasswordResetLinkController::NEUTRAL_STATUS);
        }

        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($deactivated, ResetPassword::class);
    }

    public function test_registration_answers_the_same_whether_or_not_the_email_exists(): void
    {
        Notification::fake();

        $existing = User::factory()->create(['email' => 'existe@example.com']);

        $responses = [
            $this->post('/register', $this->registrationPayload('nueva@example.com')),
            $this->post('/register', $this->registrationPayload('existe@example.com')),
        ];

        foreach ($responses as $response) {
            $response->assertRedirect(route('login'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', RegisteredUserController::NEUTRAL_STATUS);
        }

        $this->assertGuest();

        // Correo nuevo: cuenta creada, rol base y verificación enviada.
        $created = User::where('email', 'nueva@example.com')->firstOrFail();
        $this->assertTrue($created->hasRole('User'));
        Notification::assertSentTo($created, VerifyEmail::class);

        // Correo existente: no se crea ni se modifica nada; el dueño recibe el aviso.
        $this->assertSame(1, User::withTrashed()->where('email', 'existe@example.com')->count());
        $this->assertTrue(Hash::check('password', $existing->fresh()->password));
        Notification::assertSentTo($existing, RegistrationAttemptNotification::class, fn ($n) => $n->deactivated === false);

        // El aviso no se repite dentro de la ventana.
        $this->post('/register', $this->registrationPayload('existe@example.com'));
        Notification::assertSentToTimes($existing, RegistrationAttemptNotification::class, 1);
    }

    public function test_registration_with_a_deactivated_account_email_notifies_the_owner_without_reactivating(): void
    {
        Notification::fake();

        $deactivated = User::factory()->create(['email' => 'baja@example.com']);
        $deactivated->delete();

        $this->post('/register', $this->registrationPayload('baja@example.com'))
            ->assertSessionHas('status', RegisteredUserController::NEUTRAL_STATUS);

        $this->assertTrue($deactivated->fresh()->trashed());
        $this->assertSame(1, User::withTrashed()->where('email', 'baja@example.com')->count());
        Notification::assertSentTo($deactivated, RegistrationAttemptNotification::class, fn ($n) => $n->deactivated === true);
    }

    // ---------------------------------------------------------------- V3: sesiones

    public function test_sessions_are_authenticated_against_the_current_password(): void
    {
        $kernel = app(HttpKernel::class);

        // authenticateSessions() agrega el alias 'auth.session' al grupo web.
        $this->assertContains('auth.session', $kernel->getMiddlewareGroups()['web']);
        $this->assertSame(AuthenticateSession::class, $kernel->getRouteMiddleware()['auth.session']);
    }

    public function test_changing_the_password_closes_the_other_sessions(): void
    {
        $user = User::factory()->create();
        $this->insertSession('sesion-de-otro-equipo', $user);

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NuevaClave123!#$',
                'password_confirmation' => 'NuevaClave123!#$',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-de-otro-equipo']);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'causer_id' => $user->id,
            'description' => 'Contraseña cambiada desde el perfil; se cerraron las demás sesiones.',
        ]);
    }

    public function test_resetting_the_password_closes_every_session(): void
    {
        $user = User::factory()->create();
        $this->insertSession('sesion-1', $user);
        $this->insertSession('sesion-2', $user);

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'NuevaClave123!#$',
            'password_confirmation' => 'NuevaClave123!#$',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'causer_id' => $user->id,
            'description' => 'Contraseña restablecida con el enlace del correo; se cerraron todas las sesiones.',
        ]);
    }

    // ---------------------------------------------------------------- V4: cambio de correo

    public function test_email_change_notifies_the_previous_address_and_the_revert_link_recovers_the_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'duena@example.com']);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'intruso@example.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $revertUrl = null;
        Notification::assertSentOnDemand(EmailChangedNotification::class, function (EmailChangedNotification $notification, array $channels, $notifiable) use (&$revertUrl): bool {
            $revertUrl = $notification->revertUrl;

            return $notifiable->routes['mail'] === 'duena@example.com';
        });

        $this->insertSession('sesion-del-intruso', $user);

        // Un enlace alterado no sirve.
        $this->get(str_replace('duena%40example.com', 'otra%40example.com', $revertUrl))->assertForbidden();

        $this->get($revertUrl)->assertRedirect(route('login'));

        $recovered = $user->fresh();
        $this->assertSame('duena@example.com', $recovered->email);
        $this->assertNotNull($recovered->email_verified_at);
        $this->assertFalse(Hash::check('password', $recovered->password), 'La contraseña anterior debía quedar invalidada.');
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-intruso']);
        Notification::assertSentTo($recovered, ResetPassword::class);

        // Volver a usar el enlace no cambia nada más.
        $this->get($revertUrl)->assertSessionHas('status', fn (string $status) => str_contains($status, 'ya se usó'));
    }

    public function test_no_revert_notice_is_sent_when_the_previous_email_was_never_verified(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['email' => 'sin-verificar@example.com']);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'otra-direccion@example.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 0);
    }

    public function test_unverified_email_correction_also_requires_the_current_password(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'mal-escrito@example.com']);

        $this->actingAs($user)
            ->put(route('verification.update'), ['email' => 'bien-escrito@example.com'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('mal-escrito@example.com', $user->fresh()->email);
    }

    /**
     * P9: insistir con un cambio de correo bloqueado deja una sola fila en la bitácora por ventana.
     */
    public function test_blocked_email_changes_are_logged_once_per_window(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit('email-change:'.$user->id, 86400);
        }

        foreach (['uno@example.com', 'dos@example.com'] as $email) {
            $this->actingAs($user)->patch('/profile', [
                'name' => $user->name,
                'email' => $email,
                'current_password' => 'password',
            ])->assertSessionHasErrors('email');
        }

        $this->assertSame(1, Activity::query()
            ->where('log_name', 'security')
            ->where('description', 'like', 'Intento de cambio de correo bloqueado%')
            ->count());
    }

    // ---------------------------------------------------------------- V5 y P5: login

    public function test_failed_logins_take_at_least_the_minimum_time(): void
    {
        $start = microtime(true);

        $this->post('/login', ['email' => 'nadie@example.com', 'password' => 'Cualquiera123!'])
            ->assertSessionHasErrors('email');

        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(LoginRequest::FAILED_LOGIN_MIN_MICROSECONDS / 1_000_000 - 0.02, $elapsed);
    }

    public function test_login_is_throttled_per_ip_across_different_accounts(): void
    {
        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS_PER_IP; $i++) {
            RateLimiter::hit('login-ip:127.0.0.1', LoginRequest::IP_DECAY_SECONDS);
        }

        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => "persona{$i}@example.com"]);
        }

        $this->post('/forgot-password', ['email' => 'otra@example.com'])->assertStatus(429);
    }

    public function test_password_confirmation_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/confirm-password', ['password' => 'incorrecta']);
        }

        $this->actingAs($user)->post('/confirm-password', ['password' => 'incorrecta'])->assertStatus(429);
    }

    private function enableTurnstile(?string $secret = 'secreto-de-prueba'): void
    {
        config([
            'services.turnstile.enabled' => true,
            'services.turnstile.site_key' => 'clave-de-sitio',
            'services.turnstile.secret_key' => $secret,
        ]);
    }

    public function test_login_form_renders_the_turnstile_widget_when_enabled(): void
    {
        $this->enableTurnstile();

        $this->get('/login')
            ->assertOk()
            ->assertSee('class="cf-turnstile"', false)
            ->assertSee('data-sitekey="clave-de-sitio"', false);
    }

    public function test_login_succeeds_with_a_valid_captcha(): void
    {
        $this->enableTurnstile();
        Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true])]);

        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', Turnstile::FIELD => 'token-valido'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request) => $request['secret'] === 'secreto-de-prueba' && $request['response'] === 'token-valido');
    }

    public function test_login_is_rejected_when_cloudflare_rejects_the_captcha(): void
    {
        $this->enableTurnstile();
        Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', Turnstile::FIELD => 'token-falso'])
            ->assertSessionHasErrors(Turnstile::FIELD);

        $this->assertGuest();
    }

    public function test_captcha_fails_closed_when_the_token_is_missing_cloudflare_is_down_or_the_secret_is_missing(): void
    {
        $user = User::factory()->create();

        // Sin token: se rechaza sin llamar a Cloudflare.
        $this->enableTurnstile();
        Http::fake();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors(Turnstile::FIELD);
        Http::assertNothingSent();

        // Sin clave secreta configurada.
        $this->enableTurnstile(secret: null);
        $this->post('/register', $this->registrationPayload('x@example.com') + [Turnstile::FIELD => 'token'])
            ->assertSessionHasErrors(Turnstile::FIELD);

        $this->assertGuest();
    }

    public function test_captcha_fails_closed_when_cloudflare_does_not_answer(): void
    {
        $this->enableTurnstile();
        Http::fake(fn () => throw new ConnectionException('Tiempo de espera agotado'));

        $this->post('/forgot-password', ['email' => 'alguien@example.com', Turnstile::FIELD => 'token'])
            ->assertSessionHasErrors(Turnstile::FIELD);
    }

    // ---------------------------------------------------------------- P7: eventos de autenticación

    public function test_logins_and_logouts_are_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');

        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'causer_id' => $user->id, 'description' => 'Inicio de sesión.']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'causer_id' => $user->id, 'description' => 'Cierre de sesión.']);
    }

    public function test_failed_logins_are_logged_once_per_window_and_never_store_the_password(): void
    {
        $user = User::factory()->create(['email' => 'objetivo@example.com']);

        $this->post('/login', ['email' => 'objetivo@example.com', 'password' => 'ClaveSecretaIncorrecta1!']);
        $this->post('/login', ['email' => 'objetivo@example.com', 'password' => 'ClaveSecretaIncorrecta2!']);

        $rows = Activity::query()->where('log_name', 'security')->where('description', 'like', 'Inicio de sesión fallido%')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('objetivo@example.com', $rows->first()->properties['attempted_email']);
        $this->assertSame($user->id, $rows->first()->subject_id);
        $this->assertStringNotContainsString('ClaveSecretaIncorrecta', json_encode($rows->first()->properties));
    }

    public function test_lockouts_are_logged_and_alert_superadmins_once(): void
    {
        Notification::fake();

        $superAdmin = User::role('SuperAdmin')->firstOrFail();
        $user = User::factory()->create(['email' => 'bloqueada@example.com']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS_PER_ACCOUNT; $i++) {
            RateLimiter::hit('bloqueada@example.com|127.0.0.1');
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertSame(1, Activity::query()->where('description', 'Inicio de sesión bloqueado por demasiados intentos fallidos.')->count());
        Notification::assertSentToTimes($superAdmin, SecurityAlertNotification::class, 1);
    }
}
