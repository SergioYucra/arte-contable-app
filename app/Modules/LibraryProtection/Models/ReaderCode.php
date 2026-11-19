<?php

namespace App\Modules\LibraryProtection\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código personal impreso en cada página que lee una persona.
 *
 * @property int $user_id
 * @property string $code
 */
class ReaderCode extends Model
{
    protected $fillable = ['user_id', 'code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
