<?php

namespace App\Modules\LibraryProtection\Models;

use App\Models\BookEdition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una página vista por una persona en un día (hora de Bolivia).
 *
 * @property int $user_id
 * @property int $book_edition_id
 * @property int $page
 * @property string $viewed_on Fecha 'Y-m-d' en hora de Bolivia
 * @property int $hits
 * @property Carbon $first_viewed_at
 * @property Carbon $last_viewed_at
 * @property string|null $ip_address
 */
class BookPageView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'book_edition_id',
        'page',
        'viewed_on',
        'hits',
        'first_viewed_at',
        'last_viewed_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        // viewed_on queda como texto 'Y-m-d' (sin cast 'date'): así la llave única y las
        // consultas por día comparan lo mismo en SQLite y en MySQL.
        return [
            'page' => 'integer',
            'hits' => 'integer',
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(BookEdition::class, 'book_edition_id')->withTrashed();
    }
}
