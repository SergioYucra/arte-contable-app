<?php

namespace Tests\Feature;

use App\Models\AccessCode;
use App\Models\Activity;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\PaymentRequest;
use App\Models\ReadingState;
use App\Models\StudyNote;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\MvpCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MvpDatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifica la existencia de las tablas del MVP y sus columnas principales.
     */
    public function test_mvp_tables_and_columns_are_created_correctly(): void
    {
        $expectedSchema = [
            'books' => [
                'id', 'author_name', 'title', 'subtitle', 'slug', 'description',
                'cover_path', 'is_published', 'created_at', 'updated_at', 'deleted_at',
            ],
            'book_editions' => [
                'id', 'book_id', 'edition_name', 'total_pages', 'sample_pages_count',
                'toc_json', 'status', 'is_current', 'created_at', 'updated_at', 'deleted_at',
            ],
            'access_codes' => [
                'id', 'code', 'reference', 'duration_days', 'redeemed_by',
                'redeemed_at', 'revoked_at', 'created_by', 'created_at', 'updated_at',
            ],
            'payment_requests' => [
                'id', 'user_id', 'amount', 'reference_text', 'proof_vault_path',
                'status', 'admin_notes', 'reviewed_by', 'reviewed_at', 'created_at', 'updated_at',
            ],
            'subscriptions' => [
                'id', 'user_id', 'book_edition_id', 'payment_request_id', 'access_code_id', 'starts_at',
                'expires_at', 'status', 'active_session_token', 'created_at', 'updated_at',
            ],
            'reading_states' => [
                'id', 'user_id', 'book_edition_id', 'last_page', 'updated_at',
            ],
            'study_notes' => [
                'id', 'user_id', 'book_edition_id', 'page_number',
                'title', 'body', 'created_at', 'updated_at',
            ],
        ];

        foreach ($expectedSchema as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "La tabla [{$table}] debería existir.");
            $this->assertTrue(
                Schema::hasColumns($table, $columns),
                "La tabla [{$table}] no contiene todas las columnas esperadas."
            );
        }
    }

    /**
     * Verifica la generación automática del slug al crear y al actualizar el título del libro.
     */
    public function test_book_generates_and_updates_slug_automatically(): void
    {
        $book = Book::create([
            'title' => 'Contabilidad Financiera Aplicada 2026',
            'author_name' => 'Autor de Prueba',
        ]);

        $this->assertSame('contabilidad-financiera-aplicada-2026', $book->slug);

        $book->update([
            'title' => 'Contabilidad Superior y Costos',
        ]);

        $this->assertSame('contabilidad-superior-y-costos', $book->fresh()->slug);
    }

    /**
     * Verifica SoftDeletes en libros y la protección restrictOnDelete frente a borrado físico.
     */
    public function test_books_support_soft_deletes_and_restrict_physical_deletion_with_editions(): void
    {
        $book = Book::create([
            'title' => 'Manual de Auditoría Contable',
            'author_name' => 'Autor Editorial',
        ]);

        $edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => 'Edición 2026',
            'sample_pages_count' => 12,
        ]);

        // Soft delete oculta el libro de consultas ordinarias sin destruir la fila física.
        $book->delete();
        $this->assertSoftDeleted('books', ['id' => $book->id]);
        $this->assertNull(Book::find($book->id));
        $this->assertNotNull(Book::withTrashed()->find($book->id));

        // El borrado físico debe fallar por restrictOnDelete mientras exista una edición hija.
        try {
            $book->forceDelete();
            $this->fail('Se permitió eliminar físicamente un libro con ediciones asociadas.');
        } catch (QueryException) {
            $this->assertDatabaseHas('book_editions', ['id' => $edition->id]);
        }
    }

    /**
     * Verifica el cálculo de estados 'free', 'active' y 'expired', los permisos de lectura por página
     * y la preservación de notas de estudio tras el vencimiento.
     */
    public function test_user_subscription_status_and_edition_page_authorization(): void
    {
        $book = Book::create([
            'title' => 'El Arte de la Contabilidad',
            'author_name' => 'Equipo Editorial',
            'is_published' => true,
        ]);

        $edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => 'Edición 2026',
            'total_pages' => 120,
            'sample_pages_count' => 12,
            'status' => BookEdition::STATUS_READY,
            'is_current' => true,
        ]);

        // 1. Usuario gratuito ('free')
        $freeUser = User::factory()->create();
        $this->assertFalse($freeUser->hasActiveSubscription());
        $this->assertSame('free', $freeUser->subscription_status);
        $this->assertFalse($freeUser->canReadEditionPage($edition, 0));
        $this->assertTrue($freeUser->canReadEditionPage($edition, 1));
        $this->assertTrue($freeUser->canReadEditionPage($edition, 12));
        $this->assertFalse($freeUser->canReadEditionPage($edition, 13));

        // 2. Usuario con suscripción vigente ('active')
        $activeUser = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $activeUser->id,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $note = StudyNote::create([
            'user_id' => $activeUser->id,
            'book_edition_id' => $edition->id,
            'page_number' => 45,
            'title' => 'Resumen de partida doble',
            'body' => 'No hay deudor sin acreedor por valor equivalente.',
        ]);

        ReadingState::create([
            'user_id' => $activeUser->id,
            'book_edition_id' => $edition->id,
            'last_page' => 45,
            'bookmarks_json' => [3, 12, 45],
        ]);

        $this->assertTrue($activeUser->hasActiveSubscription());
        $this->assertSame('active', $activeUser->subscription_status);
        $this->assertTrue($activeUser->canReadEditionPage($edition, 45));
        $this->assertTrue($activeUser->canReadEditionPage($edition, 120));
        $this->assertFalse($activeUser->canReadEditionPage($edition, 121));

        // 3. Vencimiento de suscripción ('expired'): bloquea páginas > sample pero conserva StudyNote
        $subscription->update([
            'expires_at' => now()->subMinute(),
        ]);

        $activeUser->refresh();
        $this->assertFalse($activeUser->hasActiveSubscription());
        $this->assertSame('expired', $activeUser->subscription_status);
        $this->assertTrue($activeUser->canReadEditionPage($edition, 12));
        $this->assertFalse($activeUser->canReadEditionPage($edition, 45));
        $this->assertDatabaseHas('study_notes', ['id' => $note->id]);

        // 4. Suscripción con fecha futura pero revocada ('revoked') también reporta 'expired'
        $revokedUser = User::factory()->create();
        Subscription::create([
            'user_id' => $revokedUser->id,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonths(6),
            'status' => Subscription::STATUS_REVOKED,
        ]);

        $this->assertFalse($revokedUser->hasActiveSubscription());
        $this->assertSame('expired', $revokedUser->subscription_status);
    }

    /**
     * Verifica la restricción UNIQUE de payment_request_id y access_code_id en subscriptions.
     */
    public function test_subscriptions_enforce_unique_payment_request_and_access_code(): void
    {
        $admin = User::factory()->create();
        $studentA = User::factory()->create();
        $studentB = User::factory()->create();

        $paymentRequest = PaymentRequest::create([
            'user_id' => $studentA->id,
            'amount' => 20.00,
            'reference_text' => 'COMP-QR-0001',
            'proof_vault_path' => 'payments/2026/uuid-proof.webp',
            'status' => PaymentRequest::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        Subscription::create([
            'user_id' => $studentA->id,
            'payment_request_id' => $paymentRequest->id,
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        try {
            Subscription::create([
                'user_id' => $studentB->id,
                'payment_request_id' => $paymentRequest->id,
                'starts_at' => now(),
                'expires_at' => now()->addYear(),
                'status' => Subscription::STATUS_ACTIVE,
            ]);
            $this->fail('Se permitió reutilizar el mismo payment_request_id en dos suscripciones.');
        } catch (QueryException) {
            $this->assertSame(1, Subscription::where('payment_request_id', $paymentRequest->id)->count());
        }

        $accessCode = AccessCode::create([
            'reference' => 'Feria del Libro 2026',
            'created_by' => $admin->id,
            'redeemed_by' => $studentA->id,
            'redeemed_at' => now(),
        ]);

        $this->assertNotEmpty($accessCode->code);

        Subscription::create([
            'user_id' => $studentA->id,
            'access_code_id' => $accessCode->id,
            'starts_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->expectException(QueryException::class);
        Subscription::create([
            'user_id' => $studentB->id,
            'access_code_id' => $accessCode->id,
            'starts_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    /**
     * Verifica que MvpCatalogSeeder sea idempotente y que la auditoría no filtre tokens de sesión.
     */
    public function test_mvp_catalog_seeder_is_idempotent_and_audit_excludes_session_tokens(): void
    {
        $this->seed(MvpCatalogSeeder::class);
        $this->seed(MvpCatalogSeeder::class);

        $this->assertSame(1, Book::count());
        $this->assertSame(1, BookEdition::count());

        $edition = BookEdition::firstOrFail();
        $this->assertSame(12, $edition->sample_pages_count);
        $this->assertTrue($edition->is_current);
        $this->assertIsArray($edition->toc_json);
        $this->assertNotEmpty($edition->toc_json);

        $user = User::factory()->create();
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => Subscription::STATUS_ACTIVE,
            'active_session_token' => 'secret-session-token-xyz',
        ]);

        $log = Activity::where('subject_type', Subscription::class)
            ->where('subject_id', $subscription->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('active_session_token', $log->properties['attributes'] ?? []);
    }

    /**
     * Verifica que desactivar un usuario (SoftDelete) anule el active_session_token de sus suscripciones
     * y que la precarga de 'subscriptions' evite consultas N+1.
     */
    public function test_soft_deleting_user_revokes_subscription_session_token_and_eager_loading_avoids_n_plus_one(): void
    {
        $freeUser = User::factory()->create();
        $activeUser = User::factory()->create();
        $expiredUser = User::factory()->create();

        $activeSub = Subscription::create([
            'user_id' => $activeUser->id,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'status' => Subscription::STATUS_ACTIVE,
            'active_session_token' => 'active-reader-session-token-123',
        ]);

        Subscription::create([
            'user_id' => $expiredUser->id,
            'starts_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        // Precarga en memoria: evaluar subscription_status no debe disparar consultas adicionales
        $loadedUsers = User::with('subscriptions')
            ->whereIn('id', [$freeUser->id, $activeUser->id, $expiredUser->id])
            ->get()
            ->keyBy('id');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('free', $loadedUsers[$freeUser->id]->subscription_status);
        $this->assertSame('active', $loadedUsers[$activeUser->id]->subscription_status);
        $this->assertSame('expired', $loadedUsers[$expiredUser->id]->subscription_status);
        $this->assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();

        // Al desactivar al usuario activo, su token de sesión única en la suscripción debe quedar en NULL
        $activeUser->delete();

        $this->assertSoftDeleted('users', ['id' => $activeUser->id]);
        $this->assertNull($activeSub->fresh()->active_session_token);
    }
}
