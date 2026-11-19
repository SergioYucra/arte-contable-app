<?php

namespace Tests\Feature\LibraryProtection;

use App\Modules\LibraryProtection\Models\BookPageView;
use App\Modules\LibraryProtection\Notifications\ReadingAlertNotification;
use App\Modules\LibraryProtection\Services\ReaderCodes;
use App\Modules\LibraryProtection\Services\WatermarkStamp;
use Illuminate\Support\Facades\Notification;

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
            ->assertJsonPath('code', 'lector_detenido');
        $this->readPage($this->student, 2)->assertStatus(503);

        // No se registra lectura de lo que no se entregó, y el aviso llega una sola vez.
        $this->assertSame(0, BookPageView::count());
        Notification::assertSentToTimes($this->superAdmin, ReadingAlertNotification::class, 1);
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
