<?php

namespace Tests\Feature\LibraryProtection;

use App\Modules\LibraryProtection\Models\BookPageView;
use Illuminate\Support\Carbon;

/**
 * Tope diario de páginas distintas, límite por minuto y registro de lectura
 * (OWASP A06 Diseño inseguro · OAT-011 Scraping · API4/API6).
 */
class ReadingLimitsTest extends ProtectionTestCase
{
    public function test_daily_limit_counts_distinct_pages_and_rereading_is_free(): void
    {
        config()->set('library.protection.daily_page_limit', 5);
        $this->subscribe($this->student);

        foreach (range(1, 5) as $page) {
            $this->readPage($this->student, $page)
                ->assertOk()
                ->assertHeader('X-Paginas-Restantes', (string) (5 - $page));
        }

        $this->readPage($this->student, 6)
            ->assertStatus(429)
            ->assertJsonPath('code', 'limite_diario')
            ->assertJsonStructure(['message', 'retry_at', 'retry_at_text']);

        // Volver a una página ya vista hoy no cuenta para el tope.
        $this->readPage($this->student, 3)->assertOk()->assertHeader('X-Paginas-Restantes', '0');
        $this->assertSame(2, BookPageView::where('user_id', $this->student->id)->where('page', 3)->value('hits'));
    }

    public function test_daily_limit_starts_again_at_midnight_in_bolivia(): void
    {
        config()->set('library.protection.daily_page_limit', 2);
        $this->subscribe($this->student);

        // 23:50 en La Paz (03:50 UTC del día siguiente).
        Carbon::setTestNow(Carbon::parse('2026-11-20 23:50', 'America/La_Paz'));
        $this->readPage($this->student, 1)->assertOk();
        $this->readPage($this->student, 2)->assertOk();
        $this->readPage($this->student, 3)->assertStatus(429);

        Carbon::setTestNow(Carbon::parse('2026-11-21 00:05', 'America/La_Paz'));
        $this->readPage($this->student, 3)->assertOk();

        Carbon::setTestNow();
    }

    public function test_staff_has_no_daily_limit_but_their_reading_is_logged(): void
    {
        config()->set('library.protection.daily_page_limit', 2);
        $admin = $this->makeStudent();
        $admin->syncRoles(['Admin']);

        foreach (range(1, 4) as $page) {
            $this->readPage($admin, $page)->assertOk()->assertHeaderMissing('X-Paginas-Restantes');
        }

        $this->assertSame(4, BookPageView::where('user_id', $admin->id)->count());
    }

    public function test_turning_pages_too_fast_gets_a_plain_message(): void
    {
        config()->set('library.protection.pages_per_minute', 3);
        $this->subscribe($this->student);

        foreach (range(1, 3) as $ignored) {
            $this->readPage($this->student, 1)->assertOk();
        }

        $this->readPage($this->student, 1)
            ->assertStatus(429)
            ->assertJsonPath('code', 'muy_rapido');
    }

    public function test_page_limit_has_its_own_counter_and_does_not_block_progress_saving(): void
    {
        config()->set('library.protection.pages_per_minute', 2);
        $this->subscribe($this->student);

        $this->readPage($this->student, 1)->assertOk();
        $this->readPage($this->student, 2)->assertOk();
        $this->readPage($this->student, 3)->assertStatus(429);

        $this->actingAs($this->student)
            ->postJson(route('library.editions.progress', $this->edition->id), ['last_page' => 2])
            ->assertOk();
    }

    public function test_every_page_seen_is_logged_with_day_and_ip(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00', 'America/La_Paz'));
        $this->readPage($this->student, 4)->assertOk();

        $this->assertDatabaseHas('book_page_views', [
            'user_id' => $this->student->id,
            'book_edition_id' => $this->edition->id,
            'page' => 4,
            'viewed_on' => '2026-11-19',
            'hits' => 1,
            'ip_address' => '127.0.0.1',
        ]);

        Carbon::setTestNow();
    }

    public function test_old_reading_log_is_pruned(): void
    {
        config()->set('library.protection.retention_days', 180);
        $now = Carbon::now();

        foreach (['old' => 200, 'recent' => 10] as $days) {
            BookPageView::create([
                'user_id' => $this->student->id,
                'book_edition_id' => $this->edition->id,
                'page' => $days,
                'viewed_on' => $now->copy()->subDays($days)->toDateString(),
                'hits' => 1,
                'first_viewed_at' => $now->copy()->subDays($days),
                'last_viewed_at' => $now->copy()->subDays($days),
            ]);
        }

        $this->artisan('libros:limpiar-registro')->assertSuccessful();

        $this->assertSame([10], BookPageView::pluck('page')->all());
    }
}
