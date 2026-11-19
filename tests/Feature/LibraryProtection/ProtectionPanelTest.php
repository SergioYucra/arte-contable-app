<?php

namespace Tests\Feature\LibraryProtection;

use App\Modules\LibraryProtection\Models\ReadingHold;
use App\Modules\LibraryProtection\Services\ReaderCodes;

/**
 * Panel "Protección de libros": solo el administrador entra; buscar el código de una página
 * filtrada; cada consulta queda en la bitácora (OWASP A01 · A09).
 */
class ProtectionPanelTest extends ProtectionTestCase
{
    public function test_students_cannot_open_the_panel_nor_its_actions(): void
    {
        $hold = ReadingHold::create([
            'user_id' => $this->student->id,
            'reason' => ReadingHold::REASON_VELOCITY,
            'details' => ['pages' => 60, 'minutes' => 5],
            'starts_at' => now(),
            'ends_at' => now()->addHours(6),
        ]);

        $this->actingAs($this->student)->get(route('admin.library-protection.index'))->assertForbidden();
        $this->actingAs($this->student)->getJson(route('admin.library-protection.data'))->assertForbidden();
        $this->actingAs($this->student)->getJson(route('admin.library-protection.lookup', ['code' => 'ABCD-EFGH']))->assertForbidden();
        $this->actingAs($this->student)->postJson(route('admin.library-protection.holds.release', $hold))->assertForbidden();

        $this->assertNull($hold->fresh()->released_at);
    }

    public function test_admin_sees_pauses_and_todays_readers(): void
    {
        $this->readPage($this->student, 1)->assertOk();
        $this->readPage($this->student, 2)->assertOk();
        ReadingHold::create([
            'user_id' => $this->student->id,
            'reason' => ReadingHold::REASON_VELOCITY,
            'details' => ['pages' => 60, 'minutes' => 5],
            'starts_at' => now(),
            'ends_at' => now()->addHours(6),
        ]);

        $admin = $this->makeStudent();
        $admin->syncRoles(['Admin']);

        $this->actingAs($admin)->get(route('admin.library-protection.index'))
            ->assertOk()
            ->assertSee('data-vue-island="BookProtectionPanel"', false)
            ->assertViewHas('initialData', function (array $data): bool {
                return $data['stats']['active_holds'] === 1
                    && $data['stats']['pages_today'] === 2
                    && $data['readers_today'][0]['user_id'] === $this->student->id
                    && $data['active_holds'][0]['reason_text'] === 'Pasó 60 páginas en 5 minutos, más rápido de lo que una persona puede leer.'
                    && str_contains($data['active_holds'][0]['revoke_url'], "/personas/{$this->student->id}/quitar-acceso");
            });
    }

    public function test_admin_finds_who_leaked_a_page_by_its_code(): void
    {
        $this->readPage($this->student, 1)->assertOk();
        $this->readPage($this->student, 2)->assertOk();
        $this->readPage($this->student, 5)->assertOk();
        $code = app(ReaderCodes::class)->for($this->student);

        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.library-protection.lookup', ['code' => strtolower(str_replace('-', '', $code))]))
            ->assertOk()
            ->assertJsonPath('code', $code)
            ->assertJsonPath('student.email', $this->student->email)
            ->assertJsonPath('days.0.pages_count', 3)
            ->assertJsonPath('days.0.books.0.title', 'El Arte de la Contabilidad')
            ->assertJsonPath('days.0.books.0.pages', '1–2, 5')
            ->assertJsonPath('days.0.ips.0', '127.0.0.1');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'busqueda_codigo_lector',
            'causer_id' => $this->superAdmin->id,
            'subject_id' => $this->student->id,
        ]);
    }

    public function test_unknown_or_malformed_codes_get_a_plain_answer(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.library-protection.lookup', ['code' => 'ZZZZ-ZZZZ']))
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontramos ese código. Revisa que esté bien escrito: son 8 letras y números, como K7QM-3XPA.');

        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.library-protection.lookup', ['code' => '']))
            ->assertUnprocessable();
    }
}
