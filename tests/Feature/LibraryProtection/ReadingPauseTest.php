<?php

namespace Tests\Feature\LibraryProtection;

use App\Modules\LibraryProtection\Models\ReadingHold;
use App\Modules\LibraryProtection\Notifications\ReadingAlertNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Pausa automática ante lectura no humana o pedidos trampa, aviso al administrador y
 * reactivación (OWASP A06 · A09 Registro y alertas · OAT-011).
 */
class ReadingPauseTest extends ProtectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('library.protection.velocity_rules', [['pages' => 4, 'minutes' => 5]]);
        Notification::fake();
    }

    public function test_reading_faster_than_a_person_pauses_the_account_and_alerts_the_admin(): void
    {
        $this->subscribe($this->student);

        foreach (range(1, 3) as $page) {
            $this->readPage($this->student, $page)->assertOk();
        }

        $this->readPage($this->student, 4)
            ->assertStatus(423)
            ->assertJsonPath('code', 'lectura_pausada');

        $hold = ReadingHold::where('user_id', $this->student->id)->sole();
        $this->assertSame(ReadingHold::REASON_VELOCITY, $hold->reason);
        $this->assertSame(4, $hold->details['pages']);
        $this->assertTrue($hold->ends_at->between(now()->addHours(5), now()->addHours(7)));

        // Mientras dure la pausa, ni siquiera las páginas ya vistas se entregan.
        $this->readPage($this->student, 1)->assertStatus(423);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'lectura_pausada',
            'causer_id' => $this->student->id,
        ]);

        Notification::assertSentTo($this->superAdmin, ReadingAlertNotification::class, function (ReadingAlertNotification $n) {
            return str_contains($n->subject, $this->student->name)
                && str_contains(implode(' ', $n->lines), 'más rápido de lo que una persona puede leer');
        });
    }

    public function test_a_name_with_markdown_cannot_put_links_in_the_admin_email(): void
    {
        config()->set('library.protection.trap_strikes', ['limit' => 1, 'window_minutes' => 30]);
        $attacker = $this->makeStudent(['name' => 'Juan [Entrar al panel](https://evil.example/login) ![x](https://evil.example/p.png)']);

        $this->readPage($attacker, 999)->assertStatus(423);

        Notification::assertSentTo($this->superAdmin, ReadingAlertNotification::class, function (ReadingAlertNotification $n) {
            $html = (string) $n->toMail($this->superAdmin)->render();

            return ! str_contains($html, 'href="https://evil.example')
                && ! str_contains($html, 'src="https://evil.example')
                && str_contains($html, 'Entrar al panel')
                // El botón legítimo del correo sigue funcionando.
                && str_contains($html, 'href="'.route('admin.library-protection.index').'"');
        });
    }

    public function test_a_normal_reading_pace_is_never_paused(): void
    {
        $this->subscribe($this->student);
        Carbon::setTestNow(now());

        foreach (range(1, 8) as $page) {
            $this->readPage($this->student, $page)->assertOk();
            Carbon::setTestNow(now()->addMinutes(2));
        }

        $this->assertSame(0, ReadingHold::count());
        Carbon::setTestNow();
    }

    public function test_requests_the_reader_never_makes_pause_the_account(): void
    {
        config()->set('library.protection.trap_strikes', ['limit' => 3, 'window_minutes' => 30]);

        // Sin membresía, la página 50 está fuera de la muestra: el lector nunca la pide.
        $this->readPage($this->student, 50)->assertForbidden()->assertJsonPath('code', 'membresia');
        $this->readPage($this->student, 999)->assertNotFound()->assertJsonPath('code', 'no_disponible');
        $this->readPage($this->student, 51)->assertStatus(423)->assertJsonPath('code', 'lectura_pausada');

        $hold = ReadingHold::where('user_id', $this->student->id)->sole();
        $this->assertSame(ReadingHold::REASON_TRAPS, $hold->reason);
        Notification::assertSentTo($this->superAdmin, ReadingAlertNotification::class);
    }

    public function test_the_pause_ends_by_itself(): void
    {
        $this->subscribe($this->student);
        foreach (range(1, 4) as $page) {
            $this->readPage($this->student, $page);
        }
        $this->readPage($this->student, 1)->assertStatus(423);

        Carbon::setTestNow(now()->addHours(7));
        $this->readPage($this->student, 1)->assertOk();
        Carbon::setTestNow();
    }

    public function test_admin_can_reactivate_reading_and_it_is_logged(): void
    {
        $this->subscribe($this->student);
        foreach (range(1, 4) as $page) {
            $this->readPage($this->student, $page);
        }
        $hold = ReadingHold::sole();

        $this->actingAs($this->superAdmin)
            ->postJson(route('admin.library-protection.holds.release', $hold))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.active_holds', 0);

        $this->assertNotNull($hold->fresh()->released_at);
        $this->assertSame($this->superAdmin->id, $hold->fresh()->released_by);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'lectura_reactivada',
            'causer_id' => $this->superAdmin->id,
        ]);

        $this->readPage($this->student, 1)->assertOk();

        // Reactivar dos veces la misma pausa no hace nada.
        $this->actingAs($this->superAdmin)
            ->postJson(route('admin.library-protection.holds.release', $hold))
            ->assertStatus(422);
    }

    public function test_after_reactivation_the_same_pages_do_not_pause_again(): void
    {
        $this->subscribe($this->student);
        foreach (range(1, 4) as $page) {
            $this->readPage($this->student, $page);
        }
        $hold = ReadingHold::sole();

        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($this->superAdmin)->postJson(route('admin.library-protection.holds.release', $hold))->assertOk();

        // Las 4 páginas de antes siguen dentro de los 5 minutos, pero ya se juzgaron.
        $this->readPage($this->student, 5)->assertOk();
        $this->readPage($this->student, 6)->assertOk();
        $this->assertSame(1, ReadingHold::count());
        Carbon::setTestNow();
    }

    public function test_retrying_a_book_that_was_just_unpublished_does_not_pause(): void
    {
        config()->set('library.protection.trap_strikes', ['limit' => 2, 'window_minutes' => 30]);
        $this->readPage($this->student, 1)->assertOk();
        $this->book->update(['is_published' => false]);

        foreach (range(1, 4) as $ignored) {
            $this->readPage($this->student, 2)->assertNotFound()->assertJsonPath('code', 'no_disponible');
        }

        $this->assertSame(0, ReadingHold::count());
    }

    public function test_staff_reading_is_never_paused(): void
    {
        $admin = $this->makeStudent();
        $admin->syncRoles(['Admin']);

        foreach (range(1, 6) as $page) {
            $this->readPage($admin, $page)->assertOk();
        }

        $this->assertSame(0, ReadingHold::count());
    }

    public function test_each_new_pause_sends_its_own_alert(): void
    {
        config()->set('library.protection.trap_strikes', ['limit' => 1, 'window_minutes' => 30]);

        $this->readPage($this->student, 999)->assertStatus(423);
        ReadingHold::query()->update(['released_at' => now()]);
        $this->readPage($this->student, 998)->assertStatus(423);

        // Son dos pausas distintas: cada una avisa una vez.
        Notification::assertSentToTimes($this->superAdmin, ReadingAlertNotification::class, 2);
        $this->assertSame(2, ReadingHold::count());
    }
}
