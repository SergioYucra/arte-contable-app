<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Book extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_name',
        'title',
        'subtitle',
        'slug',
        'description',
        'cover_path',
        'is_published',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Genera automáticamente el slug a partir de title cuando está vacío
     * o cuando cambia el título sin especificarse un nuevo slug manual.
     */
    protected static function booted(): void
    {
        static::saving(function (Book $book): void {
            if (blank($book->slug) || ($book->exists && $book->isDirty('title') && ! $book->isDirty('slug'))) {
                $book->slug = Str::slug($book->title);
            }
        });
    }

    /**
     * Ediciones asociadas al libro.
     */
    public function editions(): HasMany
    {
        return $this->hasMany(BookEdition::class);
    }

    /**
     * Edición vigente actual del libro.
     */
    public function currentEdition(): HasOne
    {
        return $this->hasOne(BookEdition::class)->where('is_current', true);
    }

    /**
     * Configuración de auditoría inmutable (spatie/laravel-activitylog).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('books')
            ->logOnly([
                'author_name',
                'title',
                'subtitle',
                'slug',
                'description',
                'cover_path',
                'is_published',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
