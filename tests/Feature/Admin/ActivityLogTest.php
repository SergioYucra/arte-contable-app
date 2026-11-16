<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Visor de bitácora de actividad: acceso exclusivo SuperAdmin, filtros y saneamiento.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);

        $this->superAdmin = User::factory()->create(['name' => 'Root Super']);
        $this->superAdmin->assignRole('SuperAdmin');

        $this->admin = User::factory()->create(['name' => 'Admin Normal']);
        $this->admin->assignRole('Admin');

        $this->student = User::factory()->create(['name' => 'Alumno Uno']);
        $this->student->assignRole('User');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.activity-logs.index'))
            ->assertRedirect(route('login'));

        $this->getJson(route('admin.activity-logs.list'))
            ->assertUnauthorized();
    }

    public function test_non_superadmin_roles_receive_403(): void
    {
        foreach ([$this->admin, $this->student] as $user) {
            $this->actingAs($user)
                ->get(route('admin.activity-logs.index'))
                ->assertForbidden();

            $this->actingAs($user)
                ->getJson(route('admin.activity-logs.list'))
                ->assertForbidden();
        }
    }

    public function test_sidebar_link_is_visible_only_to_superadmin(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.activity-logs.index'));

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.activity-logs.index'));
    }

    public function test_superadmin_can_view_and_filter_activity_logs(): void
    {
        activity('security')
            ->causedBy($this->superAdmin)
            ->withProperties([
                'ip' => '127.0.0.1',
                'password' => 'secret-value',
            ])
            ->log('Bloqueo de prueba por límite excedido');

        activity('books')
            ->causedBy($this->admin)
            ->log('Libro contable publicado');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Bitácora de actividad')
            ->assertSee('ActivityLogViewer');

        // Filtro por módulo
        $securityResponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.activity-logs.list', ['log_name' => 'security']))
            ->assertOk();

        $items = $securityResponse->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Bloqueo de prueba por límite excedido', $items[0]['description']);
        $this->assertSame('[oculto]', $items[0]['properties']['password']);
        $this->assertSame('127.0.0.1', $items[0]['properties']['ip']);

        // Filtro por búsqueda en descripción o responsable
        $searchResponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.activity-logs.list', ['q' => 'Admin Normal']))
            ->assertOk();

        $searchItems = $searchResponse->json('items');
        $this->assertNotEmpty($searchItems);
        $this->assertSame('Libro contable publicado', $searchItems[0]['description']);
    }
}
