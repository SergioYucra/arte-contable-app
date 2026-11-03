<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingState extends Model
{
    use HasFactory;

    /**
     * La tabla reading_states solo gestiona updated_at.
     */
    public const CREATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'book_edition_id',
        'last_page',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_page' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Lector propietario de este progreso.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Edición del libro correspondiente al progreso.
     */
    public function bookEdition(): BelongsTo
    {
        return $this->belongsTo(BookEdition::class);
    }
}
