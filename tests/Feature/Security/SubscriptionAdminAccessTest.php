<?php

namespace Tests\Feature\Security;

use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ninguna ruta del panel de membresías responde a un estudiante ni a un visitante.
 *
 * Recorre todas las rutas con nombre `admin.subscriptions.*`, así que una ruta nueva queda cubierta sola.
 */
class SubscriptionAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);
    }

    public function test_every_subscription_admin_route_is_closed_to_students_and_guests(): void
    {
        $student = User::factory()->create();
        $student->assignRole('User');

        $routes = $this->subscriptionRoutes();
        // 10 rutas originales del panel + 5 nuevas (listados paginados y personas con acceso).
        $this->assertGreaterThanOrEqual(15, count($routes), 'Se esperaban todas las rutas del panel de membresías.');

        foreach ($routes as [$method, $uri, $name]) {
            $this->actingAs($student)->json($method, $uri)
                ->assertForbidden();

            auth()->logout();

            $guest = $this->call($method, $uri);
            $this->assertTrue(
                $guest->isRedirect(route('login')),
                "Un visitante no debería poder entrar a {$name} ({$method} {$uri}); respondió {$guest->getStatusCode()}."
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function subscriptionRoutes(): array
    {
        $owner = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $payment = PaymentRequest::factory()->create(['user_id' => $owner->id]);
        $code = AccessCode::factory()->create(['created_by' => $owner->id]);

        $params = [
            'id' => $plan->id,
            'plan' => $plan->id,
            'paymentRequest' => $payment->id,
            'accessCode' => $code->id,
            'user' => $owner->id,
        ];

        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.subscriptions.')) {
                continue;
            }

            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $bindings = array_intersect_key($params, array_flip($route->parameterNames()));

            $routes[] = [$method, route($name, $bindings, false), $name];
        }

        return $routes;
    }
}
