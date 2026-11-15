<?php

namespace Tests\Feature\Student;

use App\Models\Book;
use App\Models\BookEdition;
use App\Models\StudyNote;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CoreSecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private BookEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreSecuritySeeder::class);

        $this->student = $this->makeUser('User');

        $book = Book::create([
            'author_name' => 'Rubén Yucra',
            'title' => 'El Arte de la Contabilidad Básica',
            'subtitle' => 'Enfoque práctico',
            'slug' => 'el-arte-de-la-contabilidad-basica',
            'description' => 'Obra de estudio contable.',
            'is_published' => true,
        ]);

        $this->edition = BookEdition::create([
            'book_id' => $book->id,
            'edition_name' => '1ra Edición 2026',
            'total_pages' => 100,
            'sample_pages_count' => 10,
            'toc_json' => [],
            'status' => BookEdition::STATUS_READY,
            'is_current' => true,
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function activate(User $user, int $days = 30): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays($days),
        ]);
    }

    private function expire(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(40),
            'expires_at' => now()->subMinute(),
        ]);
    }

    private function noteFor(User $user, array $attributes = []): StudyNote
    {
        $note = new StudyNote;
        $note->user_id = $user->id;
        $note->book_edition_id = $this->edition->id;
        $note->page_number = $attributes['page_number'] ?? 5;
        $note->title = $attributes['title'] ?? 'Nota';
        $note->body = $attributes['body'] ?? 'Contenido';
        $note->save();

        return $note;
    }

    public function test_guest_cannot_access_notes(): void
    {
        $this->getJson(route('library.notes.index', $this->edition->id))->assertUnauthorized();
    }

    public function test_active_member_can_list_notes_ordered_by_page_and_only_their_own(): void
    {
        $this->activate($this->student);
        $this->noteFor($this->student, ['page_number' => 40, 'title' => 'B']);
        $this->noteFor($this->student, ['page_number' => 3, 'title' => 'A']);
        $this->noteFor($this->makeUser('User'), ['title' => 'Ajena']);

        $response = $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'A')
            ->assertJsonPath('data.1.title', 'B')
            ->assertJsonPath('meta.max', 500)
            ->assertJsonMissingPath('data.0.user_id');
    }

    public function test_active_member_can_create_update_and_delete_a_note(): void
    {
        $this->activate($this->student);

        $created = $this->actingAs($this->student)->postJson(
            route('library.notes.store', $this->edition->id),
            ['page_number' => 12, 'body' => 'Asiento de apertura']
        );

        $created->assertCreated()->assertJsonPath('data.title', 'Apunte · Página 12');
        $noteId = $created->json('data.id');

        $this->actingAs($this->student)
            ->putJson(route('library.notes.update', $noteId), ['title' => 'Apertura', 'body' => 'Actualizado'])
            ->assertOk()
            ->assertJsonPath('message', 'Guardado');

        $this->assertDatabaseHas('study_notes', ['id' => $noteId, 'title' => 'Apertura', 'body' => 'Actualizado']);

        $this->actingAs($this->student)
            ->deleteJson(route('library.notes.destroy', $noteId))
            ->assertOk();

        $this->assertDatabaseMissing('study_notes', ['id' => $noteId]);
    }

    public function test_store_ignores_user_id_and_edition_sent_by_client(): void
    {
        $this->activate($this->student);
        $other = $this->makeUser('User');

        $response = $this->actingAs($this->student)->postJson(
            route('library.notes.store', $this->edition->id),
            ['user_id' => $other->id, 'book_edition_id' => 999, 'title' => 'Mía', 'body' => 'x']
        );

        $response->assertCreated();
        $this->assertDatabaseHas('study_notes', [
            'title' => 'Mía',
            'user_id' => $this->student->id,
            'book_edition_id' => $this->edition->id,
        ]);
    }

    public function test_expired_member_is_blocked_on_every_action_but_notes_are_preserved(): void
    {
        $this->expire($this->student);
        $note = $this->noteFor($this->student);

        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('membership_url', route('membership.index'));

        $this->actingAs($this->student)
            ->postJson(route('library.notes.store', $this->edition->id), ['body' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->student)
            ->putJson(route('library.notes.update', $note->id), ['body' => 'hack'])
            ->assertForbidden();

        $this->actingAs($this->student)
            ->deleteJson(route('library.notes.destroy', $note->id))
            ->assertForbidden();

        $this->assertDatabaseHas('study_notes', ['id' => $note->id, 'body' => 'Contenido']);
    }

    public function test_renewing_membership_restores_access_to_preserved_notes(): void
    {
        $this->expire($this->student);
        $this->noteFor($this->student);

        $this->activate($this->student);

        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_revoked_subscription_is_blocked(): void
    {
        $subscription = $this->activate($this->student);
        $subscription->update(['status' => Subscription::STATUS_REVOKED]);

        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertForbidden();
    }

    public function test_free_user_is_blocked(): void
    {
        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    public function test_student_cannot_touch_another_students_note(): void
    {
        $this->activate($this->student);
        $foreign = $this->noteFor($this->makeUser('User'));

        $this->actingAs($this->student)
            ->putJson(route('library.notes.update', $foreign->id), ['body' => 'hack'])
            ->assertNotFound();

        $this->actingAs($this->student)
            ->deleteJson(route('library.notes.destroy', $foreign->id))
            ->assertNotFound();

        $this->assertDatabaseHas('study_notes', ['id' => $foreign->id, 'body' => 'Contenido']);
    }

    public function test_super_admin_cannot_read_or_edit_students_notes_despite_gate_before(): void
    {
        $superAdmin = $this->makeUser('SuperAdmin');
        $this->noteFor($this->student, ['title' => 'Privada']);
        $foreign = $this->noteFor($this->student);

        $this->actingAs($superAdmin)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($superAdmin)
            ->putJson(route('library.notes.update', $foreign->id), ['body' => 'hack'])
            ->assertNotFound();

        $this->actingAs($superAdmin)
            ->deleteJson(route('library.notes.destroy', $foreign->id))
            ->assertNotFound();
    }

    public function test_admin_without_subscription_can_manage_own_notes(): void
    {
        $admin = $this->makeUser('Admin');

        $this->actingAs($admin)
            ->postJson(route('library.notes.store', $this->edition->id), ['title' => 'Revisión', 'body' => ''])
            ->assertCreated();

        $this->actingAs($admin)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_autosave_with_empty_body_does_not_fail(): void
    {
        $this->activate($this->student);
        $note = $this->noteFor($this->student);

        $this->actingAs($this->student)
            ->putJson(route('library.notes.update', $note->id), ['body' => ''])
            ->assertOk();

        $this->assertDatabaseHas('study_notes', ['id' => $note->id, 'body' => '']);
    }

    public function test_page_number_out_of_range_is_rejected(): void
    {
        $this->activate($this->student);

        $this->actingAs($this->student)
            ->postJson(route('library.notes.store', $this->edition->id), ['page_number' => 101])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_number');
    }

    public function test_general_note_without_page_is_allowed_and_listed_first(): void
    {
        $this->activate($this->student);
        $this->noteFor($this->student, ['page_number' => 2, 'title' => 'Con página']);

        $this->actingAs($this->student)
            ->postJson(route('library.notes.store', $this->edition->id), ['body' => 'Resumen'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Apunte general');

        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertJsonPath('data.0.title', 'Apunte general');
    }

    public function test_notes_limit_per_edition_is_enforced(): void
    {
        config()->set('library.study_notes.max_per_edition', 2);
        $this->activate($this->student);
        $this->noteFor($this->student);
        $this->noteFor($this->student);

        $this->actingAs($this->student)
            ->postJson(route('library.notes.store', $this->edition->id), ['body' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'notes_limit_reached');
    }

    public function test_unpublished_edition_returns_not_found_for_students(): void
    {
        $this->activate($this->student);
        $this->edition->book->update(['is_published' => false]);

        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertNotFound();
    }

    public function test_edition_scoped_subscription_only_unlocks_its_edition(): void
    {
        $otherEdition = BookEdition::create([
            'book_id' => $this->edition->book_id,
            'edition_name' => '2da Edición',
            'total_pages' => 50,
            'sample_pages_count' => 5,
            'toc_json' => [],
            'status' => BookEdition::STATUS_READY,
            'is_current' => false,
        ]);

        Subscription::create([
            'user_id' => $this->student->id,
            'book_edition_id' => $otherEdition->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $otherEdition->id))
            ->assertOk();

        $this->actingAs($this->student)
            ->getJson(route('library.notes.index', $this->edition->id))
            ->assertForbidden();
    }
}
