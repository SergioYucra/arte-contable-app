<?php

namespace Tests\Feature\LibraryProtection;

use App\Models\Book;

/**
 * Las páginas solo se piden desde el lector y la membresía de un libro no abre los demás
 * (OWASP A01 Control de acceso).
 */
class ReaderAccessTest extends ProtectionTestCase
{
    public function test_opening_a_page_in_a_new_tab_goes_to_the_reader_instead_of_the_image(): void
    {
        $this->readPage($this->student, 7, headers: ['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Site' => 'none'])
            ->assertRedirect(route('library.reader', ['edition' => $this->edition->id, 'page' => 7]));

        $this->assertDatabaseCount('book_page_views', 0);
    }

    public function test_embedding_a_page_in_a_frame_is_rejected(): void
    {
        $this->readPage($this->student, 1, headers: ['Sec-Fetch-Dest' => 'iframe'])
            ->assertForbidden()
            ->assertJsonPath('code', 'fuera_del_lector');
    }

    public function test_another_website_cannot_embed_the_pages(): void
    {
        $this->readPage($this->student, 1, headers: ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image'])
            ->assertForbidden()
            ->assertJsonPath('code', 'fuera_del_lector');
    }

    public function test_browsers_without_fetch_metadata_still_work(): void
    {
        $this->actingAs($this->student)
            ->get(route('library.editions.page', ['edition' => $this->edition->id, 'page' => 1]))
            ->assertOk();
    }

    public function test_a_subscription_to_one_book_does_not_open_the_others(): void
    {
        $otherBook = Book::create([
            'author_name' => 'Max Apaza Luna',
            'title' => 'Contabilidad de Costos',
            'slug' => 'contabilidad-de-costos',
            'is_published' => true,
        ]);
        $otherEdition = $this->makeEdition($otherBook, 60, 5);

        $this->subscribe($this->student, $this->edition->id);

        $this->readPage($this->student, 50)->assertOk();
        $this->readPage($this->student, 50, $otherEdition)
            ->assertForbidden()
            ->assertJsonPath('code', 'membresia');
        $this->readPage($this->student, 3, $otherEdition)->assertOk();

        $this->assertFalse($this->student->fresh()->canReadEditionPage($otherEdition, 50));

        $this->actingAs($this->student)->get(route('library.index'))
            ->assertOk()
            ->assertViewHas('initialBooks', function (array $books) use ($otherEdition): bool {
                $editions = collect($books)->pluck('editions')->flatten(1)->keyBy('id');

                return $editions[$this->edition->id]['full_access'] === true
                    && $editions[$this->edition->id]['max_allowed_page'] === 100
                    && $editions[$otherEdition->id]['full_access'] === false
                    && $editions[$otherEdition->id]['max_allowed_page'] === 5;
            });
    }

    public function test_unpublished_books_are_not_served_to_students(): void
    {
        $this->book->update(['is_published' => false]);

        $this->readPage($this->student, 1)->assertNotFound()->assertJsonPath('code', 'no_disponible');
    }

    public function test_read_here_requires_a_verified_account(): void
    {
        $this->postJson(route('library.reader.claim'))->assertUnauthorized();

        $unverified = $this->makeStudent(['email_verified_at' => null]);
        $this->actingAs($unverified)->postJson(route('library.reader.claim'))->assertForbidden();
    }
}
