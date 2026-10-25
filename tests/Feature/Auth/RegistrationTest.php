<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('User', 'web');
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_and_receive_default_user_role(): void
    {
        Event::fake([Registered::class]);

        $response = $this->post('/register', [
            'name' => 'Carlos Estudiante',
            'email' => 'carlos@example.com',
            'password' => 'Password123!#$',
            'password_confirmation' => 'Password123!#$',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'carlos@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('User'));
        $this->assertNull($user->email_verified_at);

        Event::assertDispatched(Registered::class);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_new_user_cannot_access_dashboard_without_email_verification(): void
    {
        $user = User::factory()->unverified()->create();
        $user->assignRole('User');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_user_can_update_email_address(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $user = User::factory()->unverified()->create([
            'email' => 'erroneo@example.com',
        ]);

        $response = $this->actingAs($user)->put(route('verification.update'), [
            'email' => 'correcto@example.com',
        ]);

        $response->assertSessionHas('status', 'email-updated');
        $this->assertEquals('correcto@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $user->fresh(),
            \Illuminate\Auth\Notifications\VerifyEmail::class
        );
    }

    public function test_verified_user_cannot_update_email_via_verification_route(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('verification.update'), [
            'email' => 'nuevo@example.com',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNotEquals('nuevo@example.com', $user->fresh()->email);
    }
}
