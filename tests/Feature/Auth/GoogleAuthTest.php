<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSecuritySeeder::class);

        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    public function test_login_and_register_pages_render_google_auth_button(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continuar con Google')
            ->assertSee(route('auth.google.redirect', ['from' => 'login']), false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Continuar con Google')
            ->assertSee(route('auth.google.redirect', ['from' => 'register']), false);
    }

    public function test_redirect_sends_user_to_google_with_state_and_select_account(): void
    {
        $response = $this->get(route('auth.google.redirect', ['from' => 'register']));

        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $target);
        $this->assertStringContainsString('client_id=test-google-client-id', $target);
        $this->assertStringContainsString('prompt=select_account', $target);
        $this->assertNotEmpty(session('google_oauth_state'));
        $this->assertSame('register', session('google_oauth_origin'));
    }

    public function test_redirect_fails_gracefully_when_google_credentials_are_missing(): void
    {
        config([
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
        ]);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_callback_rejects_invalid_state_and_cancelled_flow(): void
    {
        $this->withSession([
            'google_oauth_state' => 'expected-state-token',
            'google_oauth_origin' => 'login',
        ])->get(route('auth.google.callback', [
            'state' => 'wrong-state',
            'code' => 'auth-code',
        ]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->withSession([
            'google_oauth_state' => 'expected-state-token',
            'google_oauth_origin' => 'register',
        ])->get(route('auth.google.callback', [
            'error' => 'access_denied',
        ]))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
    }

    public function test_callback_rejects_unverified_google_email(): void
    {
        $this->fakeGoogleResponses([
            'sub' => 'google-sub-unverified',
            'email' => 'sinverificar@gmail.com',
            'email_verified' => false,
            'name' => 'Sin Verificar',
        ]);

        $response = $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'state' => 'valid-state',
                'code' => 'auth-code',
            ]));

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'sinverificar@gmail.com']);
    }

    public function test_initial_google_user_is_created_verified_with_default_role_and_accesses_dashboard_without_email_verification(): void
    {
        Notification::fake();
        Event::fake([Registered::class]);

        $this->fakeGoogleResponses([
            'sub' => 'google-sub-001',
            'email' => 'Nuevo.Estudiante@gmail.com',
            'email_verified' => true,
            'name' => 'Ana Contable',
        ]);

        $response = $this->withSession(['google_oauth_state' => 'state-123'])
            ->get(route('auth.google.callback', [
                'state' => 'state-123',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $user = User::where('email', 'nuevo.estudiante@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Ana Contable', $user->name);
        $this->assertSame('google-sub-001', $user->google_id);
        $this->assertFalse($user->hasPassword());
        $this->assertNotNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('User'));

        Notification::assertNothingSent();
        Event::assertDispatched(Registered::class);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_existing_unverified_user_becomes_verified_and_linked_when_signing_in_with_google(): void
    {
        Event::fake([Verified::class]);

        $user = User::factory()->unverified()->create([
            'name' => 'Carlos Manual',
            'email' => 'carlos@gmail.com',
            'password' => Hash::make('Password123!'),
        ]);
        $user->assignRole('User');

        $this->fakeGoogleResponses([
            'sub' => 'google-sub-carlos',
            'email' => 'carlos@gmail.com',
            'email_verified' => true,
            'name' => 'Carlos Google',
        ]);

        $response = $this->withSession(['google_oauth_state' => 'state-456'])
            ->get(route('auth.google.callback', [
                'state' => 'state-456',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('google-sub-carlos', $user->google_id);
        $this->assertSame('Carlos Manual', $user->name);
        $this->assertTrue(Hash::check('Password123!', (string) $user->password));

        Event::assertDispatched(Verified::class);
    }

    public function test_existing_verified_user_links_google_id_and_subsequent_login_works_directly(): void
    {
        $user = User::factory()->create([
            'name' => 'Elena Verificada',
            'email' => 'elena@gmail.com',
            'google_id' => null,
        ]);
        $user->assignRole('User');
        $originalVerifiedAt = $user->email_verified_at?->toISOString();

        $this->fakeGoogleResponses([
            'sub' => 'google-sub-elena',
            'email' => 'elena@gmail.com',
            'email_verified' => true,
            'name' => 'Elena Otro Nombre',
        ]);

        $this->withSession(['google_oauth_state' => 'state-789'])
            ->get(route('auth.google.callback', [
                'state' => 'state-789',
                'code' => 'valid-code',
            ]))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame('google-sub-elena', $user->google_id);
        $this->assertSame('Elena Verificada', $user->name);
        $this->assertSame($originalVerifiedAt, $user->email_verified_at?->toISOString());

        $this->post(route('logout'));
        $this->assertGuest();

        // Segundo acceso directo ya con google_id guardado
        $this->fakeGoogleResponses([
            'sub' => 'google-sub-elena',
            'email' => 'elena@gmail.com',
            'email_verified' => true,
            'name' => 'Elena Otro Nombre',
        ]);

        $this->withSession(['google_oauth_state' => 'state-790'])
            ->get(route('auth.google.callback', [
                'state' => 'state-790',
                'code' => 'valid-code-2',
            ]))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_soft_deleted_user_cannot_authenticate_with_google(): void
    {
        $user = User::factory()->create([
            'email' => 'desactivado@gmail.com',
            'google_id' => 'google-sub-disabled',
        ]);
        $user->delete();

        $this->fakeGoogleResponses([
            'sub' => 'google-sub-disabled',
            'email' => 'desactivado@gmail.com',
            'email_verified' => true,
            'name' => 'Usuario Desactivado',
        ]);

        $response = $this->withSession(['google_oauth_state' => 'state-disabled'])
            ->get(route('auth.google.callback', [
                'state' => 'state-disabled',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertTrue($user->fresh()->trashed());
    }

    public function test_email_linked_to_different_google_id_cannot_be_overwritten(): void
    {
        User::factory()->create([
            'email' => 'protegido@gmail.com',
            'google_id' => 'google-sub-original',
        ]);

        $this->fakeGoogleResponses([
            'sub' => 'google-sub-attacker',
            'email' => 'protegido@gmail.com',
            'email_verified' => true,
            'name' => 'Otro Usuario',
        ]);

        $response = $this->withSession(['google_oauth_state' => 'state-conflict'])
            ->get(route('auth.google.callback', [
                'state' => 'state-conflict',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_google_user_without_password_can_create_local_password_and_cannot_login_with_empty_password(): void
    {
        $user = User::factory()->create([
            'email' => 'googleonly@gmail.com',
            'google_id' => 'google-sub-nopass',
            'password' => Hash::make('RandomUnusableSecret64!'),
        ]);
        $user->forceFill(['has_password' => false])->save();

        // No puede entrar con formulario tradicional sin contraseña o con clave cualquiera
        $this->post('/login', [
            'email' => 'googleonly@gmail.com',
            'password' => '',
        ])->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->post('/login', [
            'email' => 'googleonly@gmail.com',
            'password' => 'cualquier-clave',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        // En Mi Perfil no se pide Contraseña Actual mientras has_password sea false
        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Crear Contraseña')
            ->assertDontSee('name="current_password"', false);

        // Puede crear una contraseña local sin current_password
        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'password' => 'MiNuevaClave123!#$',
                'password_confirmation' => 'MiNuevaClave123!#$',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertTrue($user->hasPassword());
        $this->assertTrue(Hash::check('MiNuevaClave123!#$', (string) $user->password));

        // Una vez creada la contraseña, vuelve a exigir current_password
        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'incorrecta',
                'password' => 'OtraClave123!#$',
                'password_confirmation' => 'OtraClave123!#$',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');
    }

    public function test_changing_email_in_profile_still_requires_verification_even_when_signing_in_with_google(): void
    {
        $user = User::factory()->create([
            'email' => 'original@gmail.com',
            'google_id' => 'google-sub-email-change',
        ]);

        // Simula cambio de correo pendiente de verificar en perfil
        $user->forceFill([
            'email' => 'nuevo-pendiente@gmail.com',
            'email_verified_at' => null,
        ])->save();

        $this->fakeGoogleResponses([
            'sub' => 'google-sub-email-change',
            'email' => 'original@gmail.com',
            'email_verified' => true,
            'name' => 'Usuario',
        ]);

        $this->withSession(['google_oauth_state' => 'state-pending'])
            ->get(route('auth.google.callback', [
                'state' => 'state-pending',
                'code' => 'valid-code',
            ]))
            ->assertRedirect(route('dashboard', absolute: false));

        // No debe marcar el correo nuevo no verificado como verificado
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * @param  array<string, mixed>  $userinfo
     */
    private function fakeGoogleResponses(array $userinfo): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-google-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response($userinfo, 200),
        ]);
    }
}
