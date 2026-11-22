<?php

namespace Tests\Feature\LibraryProtection;

use App\Core\Storage\PrivateVault;
use App\Modules\LibraryProtection\Models\BookPageView;
use App\Modules\LibraryProtection\Notifications\ReadingAlertNotification;
use App\Modules\LibraryProtection\Services\ReaderCodes;
use App\Modules\LibraryProtection\Services\WatermarkStamp;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Marca de agua por persona y código para rastrear filtraciones; el lector falla cerrado
 * (OWASP A09 · A10 Condiciones excepcionales).
 */
class PageWatermarkTest extends ProtectionTestCase
{
    public function test_each_reader_gets_the_page_with_their_own_mark(): void
    {
        $other = $this->makeStudent();

        $mine = $this->readPage($this->student, 1)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->onDevice('b');
        $theirs = $this->readPage($other, 1)->assertOk();

        $original = self::blankPage();
        $this->assertNotSame($original, $mine->getContent());
        $this->assertNotSame($mine->getContent(), $theirs->getContent());

        $image = imagecreatefromstring((string) $mine->getContent());
        $this->assertNotFalse($image);
        $this->assertSame([240, 320], [imagesx($image), imagesy($image)]);
    }

    public function test_the_mark_covers_the_page_and_the_footer(): void
    {
        $image = imagecreatefromstring((string) $this->readPage($this->student, 1)->getContent());

        // La página original es blanca: todo lo que no es blanco lo puso la marca.
        $this->assertGreaterThan(200, $this->markedPixels($image, 0, 40, 240, 260), 'La diagonal no se ve en el cuerpo de la página.');
        $this->assertGreaterThan(20, $this->markedPixels($image, 0, 300, 240, 320), 'Falta la línea "Copia personal de…" al pie.');
    }

    public function test_page_response_is_not_cacheable_nor_sniffable(): void
    {
        $this->readPage($this->student, 1)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename="pagina.webp"')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox")
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
    }

    public function test_reader_code_is_personal_stable_and_searchable(): void
    {
        $codes = app(ReaderCodes::class);
        $code = $codes->for($this->student);

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
        $this->assertSame($code, $codes->for($this->student));
        $this->assertNotSame($code, $codes->for($this->makeStudent()));

        // Se encuentra aunque se escriba en minúsculas, sin guion o con espacios.
        $typed = strtolower(str_replace('-', ' ', $code));
        $this->assertSame($this->student->id, $codes->find($typed)?->user_id);
        $this->assertNull($codes->find('no-existe'));
    }

    public function test_email_is_shown_half_hidden(): void
    {
        $this->assertSame('j***z@gmail.com', WatermarkStamp::maskEmail('juan.perez@gmail.com'));
        $this->assertSame('a***@x.com', WatermarkStamp::maskEmail('ab@x.com'));
        $this->assertSame('***', WatermarkStamp::maskEmail('sin-arroba'));
    }

    public function test_reader_stops_instead_of_serving_pages_without_mark(): void
    {
        Notification::fake();
        config()->set('library.protection.watermark.font', '/no/existe.ttf');

        $this->readPage($this->student, 1)
            ->assertStatus(503)
            ->assertJsonPath('code', 'lector_detenido')
            ->assertJsonPath('message', 'Ya avisamos al administrador para que lo revise. Intenta de nuevo más tarde.')
            // El estudiante nunca ve datos del servidor.
            ->assertJsonMissingPath('detail');
        $this->readPage($this->student, 2)->assertStatus(503);

        // No se registra lectura de lo que no se entregó, y el aviso llega una sola vez.
        $this->assertSame(0, BookPageView::count());
        Notification::assertSentToTimes($this->superAdmin, ReadingAlertNotification::class, 1);
    }

    public function test_the_admin_sees_the_real_reason_in_the_reader(): void
    {
        Notification::fake();
        config()->set('library.protection.watermark.font', '/no/existe.ttf');

        $response = $this->readPage($this->superAdmin, 1)
            ->assertStatus(503)
            ->assertJsonPath('code', 'lector_detenido');

        $this->assertStringContainsString('fuente de la marca de agua', $response->json('detail'));
        $this->assertStringContainsString('php artisan libros:diagnostico', $response->json('detail'));
        // A quien administra no se le dice "ya avisamos al administrador".
        $this->assertStringNotContainsString('avisamos', $response->json('message'));
    }

    public function test_a_damaged_page_is_not_served_nor_counted_and_the_admin_is_told(): void
    {
        Notification::fake();
        $this->subscribe($this->student);

        // Encabezado WebP válido con el contenido roto: pasa como WebP, pero GD no lo abre.
        $damaged = substr(self::blankPage(), 0, 30).str_repeat("\x00\xff", 64);
        Storage::disk(PrivateVault::DISK)->put("editions/{$this->edition->id}/page-3.webp", $damaged);

        $this->readPage($this->student, 3)
            ->assertStatus(503)
            ->assertJsonPath('code', 'lector_detenido')
            ->assertJsonPath('message', 'Ya avisamos al administrador para que lo revise. Intenta de nuevo más tarde.')
            ->assertJsonMissingPath('detail');

        // La página que no se entregó no se descuenta; las demás se siguen leyendo.
        $this->assertDatabaseMissing('book_page_views', ['user_id' => $this->student->id, 'page' => 3]);
        $this->readPage($this->student, 1)->assertOk();

        Notification::assertSentToTimes($this->superAdmin, ReadingAlertNotification::class, 1);
        Notification::assertSentTo($this->superAdmin, ReadingAlertNotification::class, function (ReadingAlertNotification $n) {
            return str_contains(implode(' ', $n->lines), 'página 3');
        });

        // El administrador ve en el lector qué página falla y qué hacer.
        $detail = $this->readPage($this->superAdmin, 3)->assertStatus(503)->json('detail');
        $this->assertStringContainsString('página 3', $detail);
        $this->assertStringContainsString('vuelve a subir', $detail);
    }

    public function test_a_page_file_that_is_missing_is_not_counted(): void
    {
        $this->subscribe($this->student);
        Storage::disk(PrivateVault::DISK)->delete("editions/{$this->edition->id}/page-5.webp");

        $this->readPage($this->student, 5)->assertNotFound()->assertJsonPath('code', 'no_disponible');

        $this->assertDatabaseMissing('book_page_views', ['user_id' => $this->student->id, 'page' => 5]);
    }

    public function test_diagnose_command_reports_what_the_server_needs(): void
    {
        $this->artisan('libros:diagnostico')->assertSuccessful();

        config()->set('library.protection.watermark.font', '/no/existe.ttf');
        $this->artisan('libros:diagnostico')->assertFailed();
    }

    private function markedPixels(\GdImage $image, int $x0, int $y0, int $x1, int $y1): int
    {
        $count = 0;
        for ($x = $x0; $x < $x1; $x++) {
            for ($y = $y0; $y < $y1; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $sum = (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
                if ($sum < 740) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
