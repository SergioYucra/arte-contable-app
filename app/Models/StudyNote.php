<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyNote extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'book_edition_id',
        'page_number',
        'title',
        'body',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
        ];
    }

    /**
     * Lector autor de la nota de estudio.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Edición del libro a la que está vinculada la nota.
     */
    public function bookEdition(): BelongsTo
    {
        return $this->belongsTo(BookEdition::class);
    }
}
