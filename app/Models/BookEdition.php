<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BookEdition extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'book_id',
        'edition_name',
        'total_pages',
        'sample_pages_count',
        'toc_json',
        'status',
        'is_current',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_pages' => 'integer',
            'sample_pages_count' => 'integer',
            'toc_json' => 'array',
            'is_current' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Obra editorial a la que pertenece esta edición.
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Estados de progreso de lectura por usuario.
     */
    public function readingStates(): HasMany
    {
        return $this->hasMany(ReadingState::class);
    }

    /**
     * Notas de estudio asociadas a esta edición.
     */
    public function studyNotes(): HasMany
    {
        return $this->hasMany(StudyNote::class);
    }

    /**
     * Configuración de auditoría inmutable (spatie/laravel-activitylog).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('book_editions')
            ->logOnly([
                'book_id',
                'edition_name',
                'total_pages',
                'sample_pages_count',
                'toc_json',
                'status',
                'is_current',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
