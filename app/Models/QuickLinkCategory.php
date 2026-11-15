<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class QuickLinkCategory extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quick_link_categories';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Genera automáticamente un slug único a partir de name cuando está vacío
     * o cuando cambia el nombre sin haberse especificado un slug manual distinto.
     */
    protected static function booted(): void
    {
        static::saving(function (QuickLinkCategory $category): void {
            if (blank($category->slug) || ($category->exists && $category->isDirty('name') && ! $category->isDirty('slug'))) {
                $category->slug = static::resolveUniqueSlug((string) $category->name, $category->id);
            }
        });
    }

    /**
     * Resuelve un slug único considerando incluso registros archivados (SoftDeletes)
     * y respetando el límite de 120 caracteres de la columna.
     */
    public static function resolveUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($name) ?: 'categoria', 105, '');
        $candidate = $base;
        $suffix = 2;

        while (
            static::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Scope para filtrar únicamente categorías activas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Enlaces rápidos pertenecientes a esta categoría.
     */
    public function quickLinks(): HasMany
    {
        return $this->hasMany(QuickLink::class, 'quick_link_category_id');
    }

    /**
     * Configuración de auditoría inmutable conectada a App\Models\Activity.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('quick_link_categories')
            ->logOnly([
                'name',
                'slug',
                'sort_order',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
