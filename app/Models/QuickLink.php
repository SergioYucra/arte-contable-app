<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class QuickLink extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quick_links';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quick_link_category_id',
        'title',
        'description',
        'url',
        'logo_path',
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
            'quick_link_category_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Scope para filtrar únicamente enlaces activos.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Categoría a la que pertenece este enlace rápido.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(QuickLinkCategory::class, 'quick_link_category_id');
    }

    /**
     * URL pública del ícono o logotipo institucional estandarizado (WebP).
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Configuración de auditoría inmutable conectada a App\Models\Activity.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('quick_links')
            ->logOnly([
                'quick_link_category_id',
                'title',
                'description',
                'url',
                'logo_path',
                'sort_order',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
