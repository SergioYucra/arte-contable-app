<?php

namespace App\Modules\LibraryProtection\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Turno de lectura de una cuenta: qué dispositivo puede pedir páginas ahora.
 *
 * @property int $user_id
 * @property string $device_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $claimed_at
 * @property Carbon $last_seen_at
 */
class ReadingLease extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'device_hash',
        'ip_address',
        'user_agent',
        'claimed_at',
        'last_seen_at',
    ];

    protected $hidden = ['device_hash'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
