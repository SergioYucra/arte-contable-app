<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * V4: cambiar el correo exige la contraseña actual; cambiar solo el nombre, no.
     */
    public function test_email_change_requires_current_password(): void
    {
        $user = User::factory()->create(['email' => 'original@example.com']);

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Otro Nombre', 'email' => 'nuevo@example.com'])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Otro Nombre', 'email' => 'nuevo@example.com', 'current_password' => 'incorrecta'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('original@example.com', $user->fresh()->email);

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Solo Nombre', 'email' => 'original@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Solo Nombre', $user->fresh()->name);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_account_deletion_endpoint_is_not_available(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile');

        $response->assertStatus(405);
    }
}
