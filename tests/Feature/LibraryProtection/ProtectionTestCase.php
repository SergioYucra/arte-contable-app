<?php

namespace Tests\Feature\LibraryProtection;

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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base de las pruebas de protección de los libros: un libro publicado de 100 páginas
 * (10 de muestra) con páginas WebP reales en una bóveda temporal.
 *
 * Requiere la extensión GD con WebP y FreeType (imagen Docker arte-php83-gd:local).
 */
abstract class ProtectionTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $student;

    protected User $superAdmin;

    protected Book $book;

    protected BookEdition $edition;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd') || empty(gd_info()['WebP Support']) || empty(gd_info()['FreeType Support'])) {
            $this->markTestSkipped('Requiere GD con WebP y FreeType (usa la imagen arte-php83-gd:local).');
        }

        $this->seed(CoreSecuritySeeder::class);

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-protection-test-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/public');
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');
        config()->set('filesystems.disks.public.root', $this->tempStorageBase.'/public');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');
        Storage::forgetDisk('public');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->superAdmin = User::factory()->create(['email_verified_at' => now()]);
        $this->superAdmin->assignRole('SuperAdmin');

        $this->student = $this->makeStudent();

        $this->book = Book::create([
            'author_name' => 'Max Apaza Luna',
            'title' => 'El Arte de la Contabilidad',
            'slug' => 'el-arte-de-la-contabilidad',
            'is_published' => true,
        ]);

        $this->edition = $this->makeEdition($this->book);

        // Por defecto, todas las peticiones salen del mismo navegador.
        $this->onDevice('a');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    protected function makeStudent(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['email_verified_at' => now()]);
        $user->assignRole('User');

        return $user;
    }

    protected function makeEdition(Book $book, int $totalPages = 100, int $samplePages = 10): BookEdition
    {
        $edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => '1ra Edición',
            'total_pages' => $totalPages,
            'sample_pages_count' => $samplePages,
            'status' => BookEdition::STATUS_READY,
            'is_current' => true,
        ]);

        $page = self::blankPage();
        for ($n = 1; $n <= $totalPages; $n++) {
            Storage::disk(PrivateVault::DISK)->put("editions/{$edition->id}/page-{$n}.webp", $page);
        }

        return $edition;
    }

    protected function subscribe(User $user, ?int $editionId = null): void
    {
        Subscription::create([
            'user_id' => $user->id,
            'book_edition_id' => $editionId,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addDays(90),
        ]);
    }

    /** Las siguientes peticiones salen del navegador identificado por $device. */
    protected function onDevice(string $device): void
    {
        $this->withCookie(ReaderDevices::COOKIE, str_pad($device, 40, 'x'));
    }

    /**
     * Pide una página como lo hace el lector (fetch desde la misma página).
     *
     * @param  array<string, string>  $headers
     */
    protected function readPage(User $user, int $page, ?BookEdition $edition = null, array $headers = []): TestResponse
    {
        $edition ??= $this->edition;

        return $this->actingAs($user)->get(
            route('library.editions.page', ['edition' => $edition->id, 'page' => $page]),
            $headers + [
                'Accept' => 'application/json, image/webp;q=0.9',
                'Sec-Fetch-Site' => 'same-origin',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Dest' => 'empty',
            ],
        );
    }

    /** Página en blanco de 240×320, como las que sube el administrador (WebP). */
    protected static function blankPage(int $width = 240, int $height = 320): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagewebp($image, null, 90);

        return (string) ob_get_clean();
    }
}
