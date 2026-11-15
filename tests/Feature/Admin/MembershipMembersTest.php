<?php

namespace Tests\Feature\Admin;

use App\Core\Storage\PrivateVault;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\PaymentRequest;
use App\Models\ReadingState;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Personas con acceso": listado, historial y quitar acceso.
 */
class MembershipMembersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-members-test-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    public function test_list_filters_by_status_and_orders_active_by_soonest_expiry(): void
    {
        $later = User::factory()->create(['name' => 'Ana Later']);
        Subscription::factory()->create(['user_id' => $later->id, 'expires_at' => now()->addDays(200)]);
        $soon = User::factory()->create(['name' => 'Beto Soon']);
        Subscription::factory()->create(['user_id' => $soon->id, 'expires_at' => now()->addDays(3)]);
        $expired = User::factory()->create(['name' => 'Carla Expired']);
        Subscription::factory()->expired()->create(['user_id' => $expired->id]);
        $revoked = User::factory()->create(['name' => 'Dani Revoked']);
        Subscription::factory()->revoked()->create(['user_id' => $revoked->id]);
        User::factory()->create(['name' => 'Sin Membresía']);

        $active = $this->actingAs($this->admin)->getJson(route('admin.subscriptions.members.index'))->assertOk();
        $this->assertSame(['Beto Soon', 'Ana Later'], array_column($active->json('items'), 'name'));
        $this->assertTrue($active->json('items.0.expiring_soon'));

        $this->assertSame(['Beto Soon'], array_column(
            $this->actingAs($this->admin)->getJson(route('admin.subscriptions.members.index', ['status' => 'expiring']))->json('items'), 'name'));
        $this->assertSame(['Carla Expired'], array_column(
            $this->actingAs($this->admin)->getJson(route('admin.subscriptions.members.index', ['status' => 'expired']))->json('items'), 'name'));
        $this->assertSame(['Dani Revoked'], array_column(
            $this->actingAs($this->admin)->getJson(route('admin.subscriptions.members.index', ['status' => 'revoked']))->json('items'), 'name'));
        $this->assertSame(4, $this->actingAs($this->admin)->getJson(route('admin.subscriptions.members.index', ['status' => 'all']))->json('total'));
    }

    public function test_search_matches_name_or_email(): void
    {
        $maria = User::factory()->create(['name' => 'María Quispe', 'email' => 'mq@example.test']);
        Subscription::factory()->create(['user_id' => $maria->id]);
        $other = User::factory()->create(['name' => 'Pedro']);
        Subscription::factory()->create(['user_id' => $other->id]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.subscriptions.members.index', ['q' => 'quispe']))
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $maria->id);

        $this->actingAs($this->admin)
            ->getJson(route('admin.subscriptions.members.index', ['q' => 'mq@']))
            ->assertJsonPath('total', 1);
    }

    public function test_revoke_closes_paid_pages_and_keeps_progress(): void
    {
        $student = User::factory()->create();
        $student->assignRole('User');
        $payment = PaymentRequest::factory()->create(['user_id' => $student->id]);
        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertOk();

        $edition = $this->editionWithPage(25);
        ReadingState::create(['user_id' => $student->id, 'book_edition_id' => $edition->id, 'last_page' => 25]);

        $this->actingAs($student)->get(route('library.editions.page', ['edition' => $edition->id, 'page' => 25]))->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.members.revoke', $student), ['reason' => 'Pago devuelto por el banco'])
            ->assertOk()
            ->assertJsonPath('member.status', 'revoked');

        $this->actingAs($student->fresh())
            ->get(route('library.editions.page', ['edition' => $edition->id, 'page' => 25]))
            ->assertForbidden();
        $this->assertSame(25, ReadingState::where('user_id', $student->id)->value('last_page'));
        $this->assertFalse($student->fresh()->hasActiveSubscription());
    }

    public function test_revoke_requires_a_reason_and_an_active_access(): void
    {
        $student = User::factory()->create();
        Subscription::factory()->create(['user_id' => $student->id]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.members.revoke', $student), ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->assertTrue($student->fresh()->hasActiveSubscription());

        $expired = User::factory()->create();
        Subscription::factory()->expired()->create(['user_id' => $expired->id]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.members.revoke', $expired), ['reason' => 'Prueba'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    public function test_history_lists_grants_and_revocations_newest_first(): void
    {
        $student = User::factory()->create();
        $payment = PaymentRequest::factory()->create(['user_id' => $student->id]);

        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00'));
        $this->actingAs($this->admin)->postJson(route('admin.subscriptions.requests.approve', $payment))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00'));
        $this->actingAs($this->admin)
            ->postJson(route('admin.subscriptions.members.revoke', $student), ['reason' => 'Comprobante falso'])
            ->assertOk();
        Carbon::setTestNow();

        $events = $this->actingAs($this->admin)
            ->getJson(route('admin.subscriptions.members.history', $student))
            ->assertOk()
            ->json('events');

        $this->assertSame(['revoke', 'grant'], array_column($events, 'type'));
        $this->assertSame('Comprobante falso', $events[0]['detail']);
        $this->assertSame($this->admin->name, $events[0]['by']);
        $this->assertSame('Pago aprobado', $events[1]['title']);
        $this->assertSame('1 de noviembre de 2027', $events[1]['new_until']);
    }

    private function editionWithPage(int $page): BookEdition
    {
        $book = Book::create([
            'author_name' => 'Max Apaza Luna',
            'title' => 'Libro de prueba',
            'slug' => 'libro-de-prueba-'.Str::random(6),
            'description' => 'Prueba',
            'is_published' => true,
        ]);

        $edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => '2026',
            'total_pages' => 100,
            'sample_pages_count' => 10,
            'status' => BookEdition::STATUS_READY,
            'is_current' => true,
        ]);

        Storage::disk(PrivateVault::DISK)->put(
            "editions/{$edition->id}/page-{$page}.webp",
            base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true) ?: ''
        );

        return $edition;
    }
}
