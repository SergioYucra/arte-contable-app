<?php

namespace Tests\Feature\Student;

use App\Core\Storage\PrivateVault;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\LibraryProtection\Services\ReaderDevices;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Book $book;

    private BookEdition $edition;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-library-test-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/public');
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');

        config()->set('filesystems.disks.public.root', $this->tempStorageBase.'/public');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');

        Storage::forgetDisk('public');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->student = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $this->student->assignRole('User');

        // Todas las peticiones salen del mismo navegador (protección "un dispositivo a la vez").
        $this->withCookie(ReaderDevices::COOKIE, str_repeat('a', 40));

        $this->book = Book::create([
            'author_name' => 'Rubén Yucra',
            'title' => 'El Arte de la Contabilidad Básica',
            'subtitle' => 'Enfoque práctico tributario y contable',
            'slug' => 'el-arte-de-la-contabilidad-basica',
            'description' => 'Obra integral de estudio contable con asientos y estados financieros.',
            'is_published' => true,
        ]);

        $this->edition = BookEdition::create([
            'book_id' => $this->book->id,
            'edition_name' => '1ra Edición 2026',
            'total_pages' => 100,
            'sample_pages_count' => 10,
            'toc_json' => [
                ['title' => 'Capítulo 1: Fundamentos', 'page' => 1],
                ['title' => 'Capítulo 2: Ciclo Contable', 'page' => 25],
            ],
            'status' => BookEdition::STATUS_READY,
            'is_current' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login_when_accessing_library(): void
    {
        $response = $this->get(route('library.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_student_can_view_library_catalog_with_published_books(): void
    {
        $response = $this->actingAs($this->student)->get(route('library.index'));

        $response->assertOk();
        $response->assertViewIs('library.index');
        $response->assertSee('data-vue-island="StudentLibraryCatalog"', false);
        $response->assertViewHas('initialBooks', function (array $books): bool {
            return count($books) === 1 && $books[0]['title'] === 'El Arte de la Contabilidad Básica';
        });
    }

    public function test_reader_shortcut_route_opens_library_with_initial_reader_state(): void
    {
        $response = $this->actingAs($this->student)->get(route('library.reader', [
            'edition' => $this->edition->id,
            'page' => 5,
        ]));

        $response->assertOk();
        $response->assertViewHas('initialOpenReader', [
            'edition_id' => $this->edition->id,
            'page' => 5,
        ]);
    }

    public function test_free_student_can_save_progress_within_sample_pages_but_blocked_beyond(): void
    {
        $okResponse = $this->actingAs($this->student)->postJson(
            route('library.editions.progress', $this->edition->id),
            ['last_page' => 8]
        );

        $okResponse->assertOk();
        $okResponse->assertJsonPath('success', true);
        $okResponse->assertJsonPath('reading_state.last_page', 8);

        $this->assertDatabaseHas('reading_states', [
            'user_id' => $this->student->id,
            'book_edition_id' => $this->edition->id,
            'last_page' => 8,
        ]);

        // Intentar guardar página 20 (más allá de sample_pages_count = 10) sin membresía
        $forbiddenResponse = $this->actingAs($this->student)->postJson(
            route('library.editions.progress', $this->edition->id),
            ['last_page' => 20]
        );

        $forbiddenResponse->assertForbidden();
    }

    public function test_subscribed_student_can_save_progress_on_any_page_and_sees_continue_reading_on_dashboard(): void
    {
        Subscription::create([
            'user_id' => $this->student->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'expires_at' => now()->addDays(180),
        ]);

        $response = $this->actingAs($this->student)->postJson(
            route('library.editions.progress', $this->edition->id),
            ['last_page' => 45]
        );

        $response->assertOk();
        $response->assertJsonPath('reading_state.last_page', 45);
        $response->assertJsonPath('reading_state.progress_percent', 45);
        $response->assertJsonPath('reading_state.current_chapter', 'Capítulo 2: Ciclo Contable');

        // Verificar que el Dashboard muestra el Hero "Continuar lectura"
        $dashboardResponse = $this->actingAs($this->student)->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('El Arte de la Contabilidad Básica');
        $dashboardResponse->assertSee('Continuar lectura');
    }

    public function test_stream_page_enforces_paywall_for_free_student_and_allows_subscribed_student(): void
    {
        $validWebpBytes = base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true) ?: '';
        Storage::disk(PrivateVault::DISK)->put("editions/{$this->edition->id}/page-5.webp", $validWebpBytes);
        Storage::disk(PrivateVault::DISK)->put("editions/{$this->edition->id}/page-25.webp", $validWebpBytes);

        // Página 5 (dentro de muestra gratis <= 10) permitida
        $sampleRes = $this->actingAs($this->student)->get(route('library.editions.page', [
            'edition' => $this->edition->id,
            'page' => 5,
        ]));
        $sampleRes->assertOk();

        // Página 25 (fuera de muestra gratis > 10) bloqueada con 403
        $blockedRes = $this->actingAs($this->student)->get(route('library.editions.page', [
            'edition' => $this->edition->id,
            'page' => 25,
        ]));
        $blockedRes->assertForbidden();

        // Activar suscripción y volver a solicitar página 25
        Subscription::create([
            'user_id' => $this->student->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $unlockedRes = $this->actingAs($this->student->fresh())->get(route('library.editions.page', [
            'edition' => $this->edition->id,
            'page' => 25,
        ]));
        $unlockedRes->assertOk();
    }
}
