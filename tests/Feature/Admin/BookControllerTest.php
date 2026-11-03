<?php

namespace Tests\Feature\Admin;

use App\Core\Storage\PrivateVault;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-books-test-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/public');
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');

        config()->set('filesystems.disks.public.root', $this->tempStorageBase.'/public');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');

        Storage::forgetDisk('public');
        Storage::forgetDisk(PrivateVault::DISK);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    private function makeValidWebpUpload(string $name = 'page-1.webp'): UploadedFile
    {
        // Binario mínimo real 1x1 WebP sin pérdida con cabecera RIFF....WEBP válida
        $webpBytes = base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true);

        return UploadedFile::fake()->createWithContent($name, $webpBytes ?: '');
    }

    private function createVerifiedUserWithRole(string $role): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_role_authorization_protects_books_module(): void
    {
        $student = $this->createVerifiedUserWithRole('User');
        $unverifiedAdmin = User::factory()->unverified()->create();
        $unverifiedAdmin->assignRole('Admin');
        $admin = $this->createVerifiedUserWithRole('Admin');
        $superAdmin = $this->createVerifiedUserWithRole('SuperAdmin');

        $this->actingAs($student)
            ->get(route('admin.books.index'))
            ->assertForbidden();

        $this->actingAs($unverifiedAdmin)
            ->get(route('admin.books.index'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($admin)
            ->get(route('admin.books.index'))
            ->assertOk()
            ->assertSee('data-vue-island="BookCatalogManager"', false);

        $this->actingAs($superAdmin)
            ->get(route('admin.books.index'))
            ->assertOk();
    }

    public function test_admin_can_create_update_replace_cover_archive_and_restore_book(): void
    {
        $admin = $this->createVerifiedUserWithRole('Admin');

        // 1. Crear libro con portada WebP e inicialización automática de edición vigente
        $createResponse = $this->actingAs($admin)->postJson(route('admin.books.store'), [
            'title' => 'El Arte de la Contabilidad Básica',
            'subtitle' => 'Enfoque Práctico Boliviano',
            'author_name' => 'Equipo Arte Contable',
            'description' => 'Obra introductoria al ciclo contable.',
            'is_published' => false,
            'cover' => $this->makeValidWebpUpload('portada-inicial.webp'),
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('book.title', 'El Arte de la Contabilidad Básica')
            ->assertJsonCount(1, 'book.editions');

        $book = Book::firstOrFail();
        $firstCoverPath = $book->cover_path;

        $this->assertNotNull($firstCoverPath);
        Storage::disk('public')->assertExists($firstCoverPath);

        // 2. Actualizar libro reemplazando portada -> elimina portada anterior (0 archivos huérfanos)
        $updateResponse = $this->actingAs($admin)->postJson(route('admin.books.update', $book->id), [
            'title' => 'El Arte de la Contabilidad General',
            'subtitle' => 'Edición Ampliada',
            'author_name' => 'Equipo Arte Contable',
            'description' => 'Descripción actualizada.',
            'is_published' => true,
            'remove_cover' => false,
            'cover' => $this->makeValidWebpUpload('portada-nueva.webp'),
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('book.title', 'El Arte de la Contabilidad General')
            ->assertJsonPath('book.is_published', true);

        $book->refresh();
        $this->assertNotSame($firstCoverPath, $book->cover_path);
        Storage::disk('public')->assertMissing($firstCoverPath);
        Storage::disk('public')->assertExists($book->cover_path);

        // 3. Archivar libro -> lo oculta automáticamente del público y aplica borrado lógico
        $this->actingAs($admin)
            ->deleteJson(route('admin.books.destroy', $book->id))
            ->assertOk()
            ->assertJsonPath('book.is_archived', true)
            ->assertJsonPath('book.is_published', false);

        $this->assertSoftDeleted('books', ['id' => $book->id]);

        // 4. Restaurar libro archivado
        $this->actingAs($admin)
            ->postJson(route('admin.books.restore', $book->id))
            ->assertOk()
            ->assertJsonPath('book.is_archived', false);

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'deleted_at' => null,
        ]);
    }

    public function test_batch_webp_upload_preview_and_automatic_orphan_cleanup(): void
    {
        $admin = $this->createVerifiedUserWithRole('Admin');

        $book = Book::create([
            'title' => 'Contabilidad de Sociedades',
            'author_name' => 'Autor Demo',
            'is_published' => false,
        ]);

        $edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => 'Edición 2026',
            'sample_pages_count' => 12,
            'status' => BookEdition::STATUS_DRAFT,
            'is_current' => true,
        ]);

        // Lote 1 (páginas 1 a 5 de 6)
        $batchOnePages = [];
        for ($i = 1; $i <= 5; $i++) {
            $batchOnePages[] = [
                'number' => $i,
                'file' => $this->makeValidWebpUpload("page-{$i}.webp"),
            ];
        }

        $this->actingAs($admin)->postJson(route('admin.books.editions.upload-pages', $edition->id), [
            'total_pages' => 6,
            'is_first_batch' => true,
            'is_final_batch' => false,
            'pages' => $batchOnePages,
        ])->assertOk()
            ->assertJsonPath('edition.stored_pages_count', 5)
            ->assertJsonPath('edition.status', BookEdition::STATUS_DRAFT);

        // Lote 2 final (página 6 de 6) -> marca status = 'ready'
        $this->actingAs($admin)->postJson(route('admin.books.editions.upload-pages', $edition->id), [
            'total_pages' => 6,
            'is_first_batch' => false,
            'is_final_batch' => true,
            'pages' => [
                ['number' => 6, 'file' => $this->makeValidWebpUpload('page-6.webp')],
            ],
        ])->assertOk()
            ->assertJsonPath('edition.stored_pages_count', 6)
            ->assertJsonPath('edition.total_pages', 6)
            ->assertJsonPath('edition.status', BookEdition::STATUS_READY);

        // Vista previa protegida sirve inline con cabeceras de seguridad
        $preview = $this->actingAs($admin)
            ->get(route('admin.books.editions.preview-page', ['edition' => $edition->id, 'page' => 1]));

        $preview->assertOk();
        $this->assertSame('nosniff', $preview->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $preview->headers->get('Content-Disposition'));

        // Re-subida de una versión más corta (2 páginas) -> limpia páginas huérfanas 3..6 automáticamente
        $this->actingAs($admin)->postJson(route('admin.books.editions.upload-pages', $edition->id), [
            'total_pages' => 2,
            'is_first_batch' => true,
            'is_final_batch' => true,
            'pages' => [
                ['number' => 1, 'file' => $this->makeValidWebpUpload('page-1.webp')],
                ['number' => 2, 'file' => $this->makeValidWebpUpload('page-2.webp')],
            ],
        ])->assertOk()
            ->assertJsonPath('edition.stored_pages_count', 2)
            ->assertJsonPath('edition.total_pages', 2);

        Storage::disk(PrivateVault::DISK)->assertExists("editions/{$edition->id}/page-1.webp");
        Storage::disk(PrivateVault::DISK)->assertExists("editions/{$edition->id}/page-2.webp");
        Storage::disk(PrivateVault::DISK)->assertMissing("editions/{$edition->id}/page-3.webp");
        Storage::disk(PrivateVault::DISK)->assertMissing("editions/{$edition->id}/page-6.webp");
    }

    public function test_upload_rejects_spoofed_files_and_batches_larger_than_five(): void
    {
        $admin = $this->createVerifiedUserWithRole('Admin');

        $book = Book::create([
            'title' => 'Auditoría Financiera',
            'author_name' => 'Autor Demo',
        ]);

        $edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => 'Edición 2026',
            'is_current' => true,
        ]);

        // Archivo falso con extensión .webp pero contenido de texto plano
        $spoofed = UploadedFile::fake()->createWithContent('page-1.webp', 'NOT_A_WEBP_BINARY_HEADER');

        $this->actingAs($admin)->postJson(route('admin.books.editions.upload-pages', $edition->id), [
            'total_pages' => 1,
            'is_first_batch' => true,
            'is_final_batch' => true,
            'pages' => [
                ['number' => 1, 'file' => $spoofed],
            ],
        ])->assertStatus(422);

        // Más de 5 páginas en un mismo lote
        $sixPages = [];
        for ($i = 1; $i <= 6; $i++) {
            $sixPages[] = [
                'number' => $i,
                'file' => $this->makeValidWebpUpload("page-{$i}.webp"),
            ];
        }

        $this->actingAs($admin)->postJson(route('admin.books.editions.upload-pages', $edition->id), [
            'total_pages' => 6,
            'is_first_batch' => true,
            'is_final_batch' => true,
            'pages' => $sixPages,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['pages']);
    }

    public function test_toc_sorts_automatically_and_purge_protects_published_current_edition(): void
    {
        $admin = $this->createVerifiedUserWithRole('Admin');

        $book = Book::create([
            'title' => 'Costos Industriales',
            'author_name' => 'Autor Demo',
            'is_published' => true,
        ]);

        $edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => 'Edición 2026',
            'total_pages' => 20,
            'status' => BookEdition::STATUS_READY,
            'is_current' => true,
        ]);

        Storage::disk(PrivateVault::DISK)->put(
            "editions/{$edition->id}/page-1.webp",
            base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true) ?: ''
        );

        // 1. Ordenamiento automático del índice por página
        $this->actingAs($admin)->putJson(route('admin.books.editions.toc', $edition->id), [
            'toc' => [
                ['title' => 'Tema 2: Costos Indirectos', 'page' => 12],
                ['title' => 'Tema 1: Materia Prima', 'page' => 3],
            ],
        ])->assertOk()
            ->assertJsonPath('edition.toc.0.page', 3)
            ->assertJsonPath('edition.toc.1.page', 12);

        // 2. Rechaza página fuera del rango total cargado (25 > 20)
        $this->actingAs($admin)->putJson(route('admin.books.editions.toc', $edition->id), [
            'toc' => [
                ['title' => 'Anexo Inexistente', 'page' => 25],
            ],
        ])->assertStatus(422);

        // 3. Impide vaciar páginas mientras el libro está publicado y la edición es vigente
        $this->actingAs($admin)
            ->deleteJson(route('admin.books.editions.purge-pages', $edition->id))
            ->assertStatus(422);

        // 4. Al pasar el libro a borrador, permite liberar el espacio físico
        $book->update(['is_published' => false]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.books.editions.purge-pages', $edition->id))
            ->assertOk()
            ->assertJsonPath('edition.stored_pages_count', 0)
            ->assertJsonPath('edition.status', BookEdition::STATUS_DRAFT);
    }
}
