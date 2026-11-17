<?php

namespace Tests\Feature\Student;

use App\Core\Storage\PrivateVault;
use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\HumanDate;
use Carbon\Carbon;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Lado del estudiante de la membresía: datos que ve, envío del comprobante, códigos,
 * retirar un envío y estado al día.
 */
class MembershipStudentSideTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private string $tempStorageBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-11-16 15:00:00', 'UTC'));

        $this->tempStorageBase = sys_get_temp_dir().'/arte-contable-student-side-'.Str::uuid();
        File::ensureDirectoryExists($this->tempStorageBase.'/vault');
        config()->set('filesystems.disks.'.PrivateVault::DISK.'.root', $this->tempStorageBase.'/vault');
        Storage::forgetDisk(PrivateVault::DISK);

        $this->student = User::factory()->create();
        $this->student->assignRole('User');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PaymentRequest::flushEventListeners();

        if (isset($this->tempStorageBase) && File::isDirectory($this->tempStorageBase)) {
            File::deleteDirectory($this->tempStorageBase);
        }

        parent::tearDown();
    }

    private function png(string $name = 'comprobante.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true) ?: ''
        );
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'comprobante.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"
        );
    }

    /** Archivo subido con contenido real en disco, para que su tipo se lea del contenido. */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $dir = $this->tempStorageBase.'/uploads';
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.Str::uuid().'-'.$name;
        File::put($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param array<string, mixed> $extra */
    private function sendProof(SubscriptionPlan $plan, ?UploadedFile $file = null, array $extra = [])
    {
        return $this->actingAs($this->student)->post(
            route('membership.payment.store'),
            ['subscription_plan_id' => $plan->id, 'proof_file' => $file ?? $this->png()] + $extra,
            ['Accept' => 'application/json'],
        );
    }

    /** @return list<string> */
    private function vaultFiles(): array
    {
        return Storage::disk(PrivateVault::DISK)->allFiles();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return $admin;
    }

    // ------------------------------------------------------------------
    // Lo que la página muestra
    // ------------------------------------------------------------------

    public function test_plans_show_money_and_duration_in_plain_words(): void
    {
        SubscriptionPlan::factory()->create(['name' => 'Plan Anual', 'price' => 200, 'duration_days' => 365, 'sort_order' => 2]);
        SubscriptionPlan::factory()->create(['name' => 'Plan Semestral', 'price' => 120.5, 'duration_days' => 180, 'sort_order' => 1]);
        SubscriptionPlan::factory()->create(['name' => 'Plan de un mes', 'price' => 30, 'duration_days' => 30, 'sort_order' => 3]);

        $plans = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialPlans');

        // El orden es el que eligió la administración, no el del precio.
        $this->assertSame(['Plan Semestral', 'Plan Anual', 'Plan de un mes'], array_column($plans, 'name'));

        $this->assertSame('Bs 120,50', $plans[0]['price_formatted']);
        $this->assertSame('6 meses', $plans[0]['duration_label']);
        $this->assertSame('Bs 20 al mes', $plans[0]['per_month_label']);

        $this->assertSame('Bs 200', $plans[1]['price_formatted']);
        $this->assertSame('1 año', $plans[1]['duration_label']);
        $this->assertSame('Bs 17 al mes', $plans[1]['per_month_label']);

        // Un plan de un mes no necesita "al mes".
        $this->assertNull($plans[2]['per_month_label']);

        // El anual tiene el menor precio por día: es un dato, no una etiqueta de venta.
        $this->assertSame([false, true, false], array_column($plans, 'best_value'));
    }

    public function test_best_value_needs_two_plans_and_a_single_winner(): void
    {
        SubscriptionPlan::factory()->create(['price' => 100, 'duration_days' => 100]);

        $only = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialPlans');
        $this->assertFalse($only[0]['best_value']);

        // Mismo precio por día: ninguno gana.
        SubscriptionPlan::factory()->create(['price' => 200, 'duration_days' => 200]);

        $tie = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialPlans');
        $this->assertSame([false, false], array_column($tie, 'best_value'));
    }

    public function test_special_price_end_names_the_last_day_it_really_applies(): void
    {
        // 04:00 UTC = medianoche en Bolivia: el día 20 ya no vale, el último día completo es el 19.
        $this->assertSame('el 19 de noviembre de 2026', HumanDate::until(Carbon::parse('2026-11-20 04:00:00', 'UTC')));
        // 22:00 UTC = 18:00 en Bolivia.
        $this->assertSame('el 20 de noviembre de 2026 a las 18:00', HumanDate::until(Carbon::parse('2026-11-20 22:00:00', 'UTC')));
        $this->assertNull(HumanDate::until(null));

        SubscriptionPlan::factory()->create([
            'price' => 200,
            'special_price' => 150,
            'special_ends_at' => Carbon::parse('2026-11-20 04:00:00', 'UTC'),
        ]);

        $plans = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialPlans');

        $this->assertSame('el 19 de noviembre de 2026', $plans[0]['special']['ends_label']);
        $this->assertSame('Bs 150', $plans[0]['price_formatted']);
        $this->assertSame('Bs 200', $plans[0]['special']['regular_price_formatted']);
    }

    public function test_summary_warns_when_the_access_is_about_to_end(): void
    {
        $subscription = Subscription::factory()->create([
            'user_id' => $this->student->id,
            'starts_at' => now()->subDays(20),
            'expires_at' => now()->addDays(40),
        ]);

        $summary = fn () => $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialMembership');

        $this->assertFalse($summary()['expiring_soon']);

        $subscription->update(['expires_at' => now()->addDays(15)]);
        $this->assertTrue($summary()['expiring_soon']);
        $this->assertSame(15, $summary()['days_remaining']);

        // Un acceso corto recién empezado (un código de 15 días) no "vence pronto": acaba de empezar.
        $subscription->update(['starts_at' => now(), 'expires_at' => now()->addDays(15)]);
        $this->assertFalse($summary()['expiring_soon']);

        // Sí avisa cuando ya pasó la mitad.
        $subscription->update(['starts_at' => now()->subDays(8), 'expires_at' => now()->addDays(7)]);
        $this->assertTrue($summary()['expiring_soon']);

        $subscription->update(['expires_at' => now()->subDay()]);
        $this->assertFalse($summary()['is_active']);
        $this->assertFalse($summary()['expiring_soon']);
    }

    public function test_a_member_sees_how_far_each_plan_would_take_their_access(): void
    {
        SubscriptionPlan::factory()->create(['duration_days' => 30]);

        $plans = fn () => $this->actingAs($this->student)->getJson(route('membership.status'))->json('plans');

        // Sin acceso vigente el plazo empieza el día que se aprueba el pago: no se promete una fecha.
        $this->assertNull($plans()[0]['extends_until']);

        Subscription::factory()->create([
            'user_id' => $this->student->id,
            'starts_at' => now()->subDays(5),
            'expires_at' => Carbon::parse('2026-12-01 15:00:00', 'UTC'),
        ]);

        // 1 de diciembre + 30 días = 31 de diciembre.
        $this->assertSame('31 de diciembre de 2026', $plans()[0]['extends_until']);
    }

    public function test_summary_tells_an_administrator_they_already_have_full_access(): void
    {
        $this->assertFalse(
            $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialMembership')['is_staff']
        );

        $this->assertTrue(
            $this->actingAs($this->admin())->get(route('membership.index'))->viewData('initialMembership')['is_staff']
        );
    }

    public function test_active_member_keeps_the_name_of_an_archived_plan(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Plan Fundadores']);
        Subscription::factory()->create(['user_id' => $this->student->id, 'subscription_plan_id' => $plan->id]);
        $plan->delete();

        $summary = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialMembership');

        $this->assertSame('Plan Fundadores', $summary['plan_name']);
    }

    public function test_history_shows_the_last_twenty_payments_with_their_details(): void
    {
        PaymentRequest::factory()->count(22)->approved()->create(['user_id' => $this->student->id]);
        $latest = PaymentRequest::factory()->rejected()->create([
            'user_id' => $this->student->id,
            'amount' => 150,
            'special_price_label' => 'Black Friday',
            'created_at' => now()->addMinute(),
        ]);

        $recent = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialRecentRequests');

        $this->assertCount(20, $recent);
        $this->assertSame($latest->id, $recent[0]['id']);
        $this->assertSame('Bs 150', $recent[0]['amount_formatted']);
        $this->assertSame('Black Friday', $recent[0]['special_price_label']);
        $this->assertSame('No aprobado', $recent[0]['status_label']);
        $this->assertNotNull($recent[0]['reviewed_at']);
        // Un pago ya revisado no se puede retirar.
        $this->assertNull($recent[0]['cancel_url']);
    }

    public function test_the_page_does_not_carry_the_email_address(): void
    {
        $html = $this->actingAs($this->student)->get(route('membership.index'))->getContent();

        $this->assertStringNotContainsString($this->student->email, $html);
    }

    // ------------------------------------------------------------------
    // Enviar el comprobante
    // ------------------------------------------------------------------

    public function test_a_note_longer_than_the_column_is_rejected_with_a_message(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $this->sendProof($plan, null, ['student_notes' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_notes.0', 'La nota puede tener como máximo 255 caracteres.');

        $this->sendProof($plan, null, ['student_notes' => str_repeat('a', 255)])->assertCreated();
    }

    public function test_only_images_and_pdf_are_accepted_as_proof(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        // Archivos reales en disco: los de UploadedFile::fake() dicen su tipo por el nombre,
        // y aquí se prueba justo lo contrario, que manda el contenido.
        $notAllowed = [
            $this->realUpload('comprobante.png', '<html><body><script>alert(1)</script></body></html>'),
            $this->realUpload('captura.jpg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->realUpload('comprobante.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true) ?: ''),
            $this->realUpload('comprobante.pdf', '<?php echo 1;'),
        ];

        foreach ($notAllowed as $file) {
            $this->sendProof($plan, $file)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('proof_file');
        }

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame([], $this->vaultFiles());
    }

    public function test_a_file_over_five_megabytes_is_rejected(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $this->sendProof($plan, UploadedFile::fake()->create('foto.png', 5121, 'image/png'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.proof_file.0', 'El archivo pesa más de 5 MB. Envía una captura de pantalla del comprobante en lugar de una foto.');
    }

    public function test_a_pdf_proof_is_accepted(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $this->sendProof($plan, $this->pdf())->assertCreated();

        $this->assertStringEndsWith('.pdf', PaymentRequest::sole()->proof_vault_path);
    }

    public function test_hidden_or_archived_plans_cannot_be_paid(): void
    {
        $hidden = SubscriptionPlan::factory()->create(['is_active' => false]);
        $visible = SubscriptionPlan::factory()->create(['name' => 'Plan visible']);

        $this->sendProof($hidden)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'plan_unavailable')
            // La respuesta trae la lista al día para que la página deje de ofrecer el plan oculto.
            ->assertJsonPath('plans.0.name', 'Plan visible')
            ->assertJsonCount(1, 'plans');

        $visible->delete();

        $this->sendProof($visible)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_plan_id');

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame([], $this->vaultFiles());
    }

    public function test_a_price_that_changed_while_the_page_was_open_is_not_registered_silently(): void
    {
        // La persona abrió la página con el precio especial; cuando envía, ya terminó.
        $plan = SubscriptionPlan::factory()->create(['price' => 200]);

        $this->sendProof($plan, null, ['expected_amount' => 150])
            ->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('plans.0.price_formatted', 'Bs 200');

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame([], $this->vaultFiles());

        // Con el precio al día, el envío entra. El monto lo fija el servidor, y quien revisa ve
        // que la persona tenía otro precio a la vista: su comprobante puede traer ese monto.
        $this->sendProof($plan, null, ['expected_amount' => 200, 'previous_amount' => 150, 'student_notes' => 'Pagué ayer'])->assertCreated();

        $payment = PaymentRequest::sole();
        $this->assertSame('200.00', $payment->amount);
        $this->assertSame('Vio antes otro precio: Bs 150. Pagué ayer', $payment->reference_text);
    }

    public function test_the_price_note_never_overflows_the_column(): void
    {
        $plan = SubscriptionPlan::factory()->create(['price' => 200]);

        $this->sendProof($plan, null, ['expected_amount' => 200, 'previous_amount' => 150, 'student_notes' => str_repeat('a', 255)])->assertCreated();

        $this->assertLessThanOrEqual(255, mb_strlen(PaymentRequest::sole()->reference_text));
    }

    public function test_the_amount_never_comes_from_the_page(): void
    {
        $plan = SubscriptionPlan::factory()->create(['price' => 200]);

        // Sin expected_amount (página vieja en caché) el envío sigue funcionando, con el precio del servidor.
        $this->sendProof($plan, null, ['amount' => 1])->assertCreated();

        $this->assertSame('200.00', PaymentRequest::sole()->amount);
    }

    public function test_a_payment_already_in_review_is_returned_so_the_page_can_show_it(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $pending = PaymentRequest::factory()->forPlan($plan)->create(['user_id' => $this->student->id]);

        $this->sendProof($plan)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'pending_exists')
            ->assertJsonPath('pending_request.id', $pending->id)
            ->assertJsonPath('pending_request.cancel_url', route('membership.payment.cancel', $pending->id));

        $this->assertSame([], $this->vaultFiles());
    }

    public function test_the_stored_file_is_removed_if_registering_the_payment_fails(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        PaymentRequest::creating(function (): void {
            throw new \RuntimeException('La base de datos no respondió.');
        });

        $this->sendProof($plan)->assertStatus(500);

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame([], $this->vaultFiles());
    }

    // ------------------------------------------------------------------
    // Quién puede entrar
    // ------------------------------------------------------------------

    public function test_every_membership_route_requires_a_signed_in_verified_person(): void
    {
        $payment = PaymentRequest::factory()->create(['user_id' => $this->student->id]);
        $unverified = User::factory()->unverified()->create();
        $unverified->assignRole('User');

        $routes = [
            ['GET', route('membership.status')],
            ['POST', route('membership.payment.store')],
            ['POST', route('membership.redeem')],
            ['GET', route('membership.proof', $payment->id)],
            ['DELETE', route('membership.payment.cancel', $payment->id)],
        ];

        foreach ($routes as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }

        $this->get(route('membership.index'))->assertRedirect(route('login'));

        foreach ($routes as [$method, $url]) {
            $this->actingAs($unverified)->json($method, $url)->assertForbidden();
        }

        $this->assertNotNull($payment->fresh());
    }

    public function test_a_proof_is_only_shown_to_its_owner_and_to_the_administration(): void
    {
        Storage::disk(PrivateVault::DISK)->put('receipts/propio.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $payment = PaymentRequest::factory()->create(['user_id' => $this->student->id, 'proof_vault_path' => 'receipts/propio.png']);

        $stranger = User::factory()->create();
        $stranger->assignRole('User');

        $this->actingAs($this->student)->get(route('membership.proof', $payment->id))->assertOk();
        $this->actingAs($this->admin())->get(route('membership.proof', $payment->id))->assertOk();

        // A quien no le corresponde se le responde igual que si no existiera.
        $this->actingAs($stranger)->get(route('membership.proof', $payment->id))->assertNotFound();
        $this->actingAs($stranger)->get(route('membership.proof', $payment->id + 999))->assertNotFound();
        $this->actingAs($this->student)->get('/membresia/solicitudes/abc/comprobante')->assertNotFound();
    }

    public function test_reading_or_viewing_proofs_does_not_use_up_the_attempts_to_redeem_a_code(): void
    {
        Storage::disk(PrivateVault::DISK)->put('receipts/propio.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $payment = PaymentRequest::factory()->create(['user_id' => $this->student->id, 'proof_vault_path' => 'receipts/propio.png']);
        $code = AccessCode::factory()->create(['created_by' => $this->admin()->id]);

        // Más visitas a otra ruta con límite que intentos permite el canje (6 por minuto).
        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($this->student)->get(route('membership.proof', $payment->id))->assertOk();
        }

        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => $code->code])
            ->assertOk();
    }

    public function test_guessing_codes_is_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->student)
                ->postJson(route('membership.redeem'), ['code' => 'NOEXISTE'.$i])
                ->assertUnprocessable();
        }

        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => 'NOEXISTE9'])
            ->assertTooManyRequests();
    }

    // ------------------------------------------------------------------
    // Códigos de acceso
    // ------------------------------------------------------------------

    public function test_a_code_is_found_however_the_person_typed_it(): void
    {
        $admin = $this->admin();

        $typedAs = [
            'AC-9J4K-X8PQ' => '  ac 9j4k x8pq ',          // con espacios
            'AC-7M2N-B5RT' => 'ac7m2nb5rt',               // sin guiones
            'AC-3H6W-Z9CD' => "AC\u{2013}3H6W\u{2014}Z9CD", // el teclado cambió los guiones por rayas
            '2026ARTE' => '2026 arte',                    // código escrito a mano por la administración
            'ARTE-2026' => 'arte2026',                    // escrito a mano con guion, tecleado sin él
            'FERIA2026' => 'feria-2026',                  // y al revés
        ];

        foreach ($typedAs as $stored => $typed) {
            $person = User::factory()->create();
            $person->assignRole('User');
            AccessCode::factory()->create(['code' => $stored, 'created_by' => $admin->id]);

            $this->actingAs($person)
                ->postJson(route('membership.redeem'), ['code' => $typed])
                ->assertOk()
                ->assertJsonPath('redeemed_code.code', $stored);
        }
    }

    public function test_two_codes_that_differ_only_by_hyphens_are_never_confused(): void
    {
        $admin = $this->admin();
        AccessCode::factory()->create(['code' => 'DUO-2026', 'created_by' => $admin->id]);
        AccessCode::factory()->create(['code' => 'DUO2026', 'created_by' => $admin->id]);

        // Escrito de una tercera forma calza con los dos: no se canjea ninguno.
        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => 'D-UO2026'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_found');

        // Escrito exactamente como uno de ellos, se canjea ese.
        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => 'duo2026'])
            ->assertOk()
            ->assertJsonPath('redeemed_code.code', 'DUO2026');

        $this->assertNull(AccessCode::where('code', 'DUO-2026')->sole()->redeemed_at);
    }

    public function test_an_unknown_code_gets_a_plain_answer(): void
    {
        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => 'NO-EXISTE'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'No encontramos ese código. Revisa que esté escrito igual que en tu libro o en el mensaje que recibiste.');

        $used = AccessCode::factory()->create(['created_by' => $this->admin()->id, 'redeemed_at' => now(), 'redeemed_by' => $this->student->id]);

        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => $used->code])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'used')
            ->assertJsonPath('message', 'Este código ya se usó. Cada código sirve una sola vez.');
    }

    public function test_a_redeemed_code_keeps_the_name_of_an_archived_plan(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Plan Feria del Libro']);
        $code = AccessCode::factory()->create(['subscription_plan_id' => $plan->id, 'created_by' => $this->admin()->id]);

        $this->actingAs($this->student)->postJson(route('membership.redeem'), ['code' => $code->code])->assertOk();
        $plan->delete();

        $codes = $this->actingAs($this->student)->get(route('membership.index'))->viewData('initialRecentCodes');

        $this->assertSame('Plan Feria del Libro', $codes[0]['plan_name']);
        $this->assertSame('Plan Feria del Libro', $codes[0]['plan']);
        $this->assertSame('1 mes', $codes[0]['duration_label']);

        // Un código que no venía de un plan no repite la duración como si fuera el nombre del plan.
        $loose = AccessCode::factory()->create(['subscription_plan_id' => null, 'created_by' => $this->admin()->id]);
        $this->actingAs($this->student)->postJson(route('membership.redeem'), ['code' => $loose->code])
            ->assertOk()
            ->assertJsonPath('redeemed_code.plan', null);
    }

    public function test_the_redeem_message_agrees_with_any_duration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 15:00:00', 'UTC'));
        $code = AccessCode::factory()->create(['duration_days' => 30, 'created_by' => $this->admin()->id]);

        // Antes decía "Se sumaron 1 mes".
        $this->actingAs($this->student)
            ->postJson(route('membership.redeem'), ['code' => $code->code])
            ->assertOk()
            ->assertJsonPath('message', 'Código canjeado: sumaste 1 mes a tu acceso. Puedes leer todo hasta el 16 de diciembre de 2026.');
    }

    public function test_redeeming_gives_the_student_role_to_someone_without_a_role(): void
    {
        $newcomer = User::factory()->create();
        $code = AccessCode::factory()->create(['created_by' => $this->admin()->id]);

        $this->actingAs($newcomer)->postJson(route('membership.redeem'), ['code' => $code->code])->assertOk();

        $this->assertTrue($newcomer->fresh()->hasRole('User'));
    }

    // ------------------------------------------------------------------
    // Retirar un envío
    // ------------------------------------------------------------------

    public function test_student_can_withdraw_a_payment_nobody_has_reviewed_and_send_another(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Plan Anual', 'price' => 200]);

        $this->sendProof($plan)->assertCreated();
        $payment = PaymentRequest::sole();
        $this->assertCount(1, $this->vaultFiles());

        $this->actingAs($this->student)
            ->deleteJson(route('membership.payment.cancel', $payment->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pending_request', null)
            ->assertJsonCount(0, 'recent_requests');

        // No queda ni el pago ni la imagen que la persona subió por error.
        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame([], $this->vaultFiles());

        // Queda anotado quién lo retiró.
        $log = Activity::where('log_name', 'payment_requests')->where('description', 'like', '%retiró su comprobante%')->sole();
        $this->assertSame($this->student->id, $log->causer_id);
        $this->assertSame('Plan Anual', $log->properties['plan_name']);

        $this->sendProof($plan)->assertCreated();
    }

    public function test_nobody_can_withdraw_someone_elses_payment(): void
    {
        $payment = PaymentRequest::factory()->create(['user_id' => $this->student->id]);
        $stranger = User::factory()->create();
        $stranger->assignRole('User');

        $this->actingAs($stranger)->deleteJson(route('membership.payment.cancel', $payment->id))->assertNotFound();
        // Tampoco la administración por esta vía: para eso está rechazar, que deja el motivo.
        $this->actingAs($this->admin())->deleteJson(route('membership.payment.cancel', $payment->id))->assertNotFound();

        $this->assertSame(PaymentRequest::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_reviewed_payment_cannot_be_withdrawn(): void
    {
        Storage::disk(PrivateVault::DISK)->put('receipts/aprobado.png', 'x');
        $approved = PaymentRequest::factory()->approved()->create(['user_id' => $this->student->id, 'proof_vault_path' => 'receipts/aprobado.png']);
        $rejected = PaymentRequest::factory()->rejected()->create(['user_id' => $this->student->id]);

        foreach ([$approved, $rejected] as $payment) {
            $this->actingAs($this->student)
                ->deleteJson(route('membership.payment.cancel', $payment->id))
                ->assertUnprocessable()
                ->assertJsonPath('code', 'already_reviewed')
                // La respuesta trae el estado al día, para que la página muestre el resultado.
                ->assertJsonStructure(['membership', 'recent_requests']);
        }

        $this->assertSame(2, PaymentRequest::count());
        $this->assertSame(['receipts/aprobado.png'], $this->vaultFiles());
    }

    public function test_the_administration_cannot_approve_a_payment_that_was_withdrawn(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $payment = PaymentRequest::factory()->forPlan($plan)->create(['user_id' => $this->student->id]);

        $this->actingAs($this->student)->deleteJson(route('membership.payment.cancel', $payment->id))->assertOk();

        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('admin.subscriptions.requests.approve', $payment->id))->assertNotFound();
        $this->actingAs($admin)->postJson(route('admin.subscriptions.requests.reject', $payment->id), ['admin_notes' => 'Tarde'])->assertNotFound();

        $this->assertSame(0, Subscription::count());
    }

    // ------------------------------------------------------------------
    // Estado al día
    // ------------------------------------------------------------------

    public function test_status_follows_a_payment_from_review_to_approval(): void
    {
        $plan = SubscriptionPlan::factory()->create(['duration_days' => 180]);
        $payment = PaymentRequest::factory()->forPlan($plan)->create(['user_id' => $this->student->id]);

        $this->actingAs($this->student)
            ->getJson(route('membership.status'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('membership.is_active', false)
            ->assertJsonPath('pending_request.id', $payment->id)
            ->assertJsonPath('recent_requests.0.status', 'pending');

        $this->actingAs($this->admin())
            ->postJson(route('admin.subscriptions.requests.approve', $payment->id))
            ->assertOk();

        $this->actingAs($this->student)
            ->getJson(route('membership.status'))
            ->assertOk()
            ->assertJsonPath('membership.is_active', true)
            ->assertJsonPath('membership.days_remaining', 180)
            ->assertJsonPath('pending_request', null)
            ->assertJsonPath('recent_requests.0.status', 'approved')
            ->assertJsonPath('recent_requests.0.cancel_url', null);
    }

    public function test_status_only_shows_the_persons_own_history(): void
    {
        $other = User::factory()->create();
        $other->assignRole('User');
        PaymentRequest::factory()->create(['user_id' => $other->id]);
        AccessCode::factory()->create(['created_by' => $this->admin()->id, 'redeemed_by' => $other->id, 'redeemed_at' => now()]);

        $this->actingAs($this->student)
            ->getJson(route('membership.status'))
            ->assertOk()
            ->assertJsonPath('pending_request', null)
            ->assertJsonCount(0, 'recent_requests')
            ->assertJsonCount(0, 'recent_codes');
    }
}
