<?php

namespace Tests\Feature\LibraryProtection;

use App\Modules\LibraryProtection\Notifications\ReadingAlertNotification;
use App\Modules\LibraryProtection\Services\ReaderDevices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Un dispositivo a la vez y aviso de cuenta compartida (OWASP A07 · OAT-020).
 */
class ReaderDeviceTest extends ProtectionTestCase
{
    public function test_a_second_device_waits_while_the_first_is_reading(): void
    {
        $this->subscribe($this->student);

        $this->onDevice('celular');
        $this->readPage($this->student, 1)->assertOk();

        $this->onDevice('compu');
        $this->readPage($this->student, 2)
            ->assertStatus(409)
            ->assertJsonPath('code', 'otro_dispositivo');
    }

    public function test_read_here_moves_reading_to_this_device_and_stops_the_other(): void
    {
        $this->subscribe($this->student);

        $this->onDevice('celular');
        $this->readPage($this->student, 1)->assertOk();

        $this->onDevice('compu');
        $this->actingAs($this->student)->withCredentials()->postJson(route('library.reader.claim'))->assertOk();
        $this->readPage($this->student, 2)->assertOk();

        $this->onDevice('celular');
        $this->readPage($this->student, 3)->assertStatus(409);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'cambio_de_dispositivo',
            'causer_id' => $this->student->id,
        ]);
    }

    public function test_an_idle_device_hands_over_by_itself(): void
    {
        $this->subscribe($this->student);

        $this->onDevice('celular');
        $this->readPage($this->student, 1)->assertOk();

        Carbon::setTestNow(now()->addMinutes(11));
        $this->onDevice('compu');
        $this->readPage($this->student, 2)->assertOk();
        Carbon::setTestNow();
    }

    public function test_logging_in_again_on_the_same_browser_is_not_another_device(): void
    {
        $this->readPage($this->student, 1)->assertOk();

        // Nueva sesión (otro inicio de sesión), misma cookie de dispositivo.
        $this->app['session']->flush();
        $this->readPage($this->student, 2)->assertOk();
    }

    public function test_a_browser_without_device_cookie_receives_one(): void
    {
        $this->defaultCookies = [];

        $this->readPage($this->student, 1)
            ->assertOk()
            ->assertCookie(ReaderDevices::COOKIE);
    }

    public function test_jumping_between_devices_alerts_the_admin_about_a_shared_account(): void
    {
        Notification::fake();
        config()->set('library.protection.sharing_alert', ['device_switches' => 2, 'distinct_ips' => 50, 'window_hours' => 24]);
        $this->subscribe($this->student);

        $this->onDevice('celular');
        $this->readPage($this->student, 1)->assertOk();
        $this->onDevice('compu');
        $this->actingAs($this->student)->withCredentials()->postJson(route('library.reader.claim'))->assertOk();
        $this->onDevice('celular');
        $this->actingAs($this->student)->withCredentials()->postJson(route('library.reader.claim'))->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'posible_cuenta_compartida',
            'causer_id' => $this->student->id,
        ]);
        Notification::assertSentTo($this->superAdmin, ReadingAlertNotification::class, function (ReadingAlertNotification $n) {
            return str_contains($n->subject, 'posible cuenta compartida');
        });

        // La lectura no se pausa por esto: solo se avisa.
        $this->readPage($this->student, 2)->assertOk();
    }

    public function test_reading_from_many_connections_alerts_the_admin(): void
    {
        Notification::fake();
        config()->set('library.protection.sharing_alert', ['device_switches' => 50, 'distinct_ips' => 3, 'window_hours' => 24]);
        $this->subscribe($this->student);

        foreach (['181.115.10.1', '181.115.10.2', '181.115.10.2', '200.87.4.9'] as $i => $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
            // Volver a una página ya vista también cuenta: la conexión es lo que importa.
            $this->readPage($this->student, $i === 2 ? 1 : $i + 1)->assertOk();
        }

        $this->assertDatabaseHas('activity_log', [
            'event' => 'posible_cuenta_compartida',
            'causer_id' => $this->student->id,
        ]);
        Notification::assertSentTo($this->superAdmin, ReadingAlertNotification::class);
    }

    public function test_staff_can_read_on_several_devices(): void
    {
        $admin = $this->makeStudent();
        $admin->syncRoles(['Admin']);

        $this->onDevice('oficina');
        $this->readPage($admin, 1)->assertOk();
        $this->onDevice('casa');
        $this->readPage($admin, 2)->assertOk();
    }
}
