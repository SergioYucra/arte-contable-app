<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    /**
     * Blindaje de inmutabilidad (S4):
     * Impide cualquier modificación, eliminación directa o truncado masivo de los registros de auditoría.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::updating(function () {
            throw new \LogicException('Los registros de auditoría son inmutables y no pueden modificarse.');
        });

        static::deleting(function () {
            throw new \LogicException('Los registros de auditoría son inmutables y no pueden eliminarse.');
        });
    }

    /**
     * Bloquea la eliminación de una instancia del modelo.
     */
    public function delete(): ?bool
    {
        throw new \LogicException('Los registros de auditoría son inmutables y no pueden eliminarse.');
    }

    /**
     * Bloquea la eliminación forzada de una instancia del modelo.
     */
    public function forceDelete(): ?bool
    {
        throw new \LogicException('Los registros de auditoría son inmutables y no pueden eliminarse.');
    }

    /**
     * Bloquea el truncado masivo de la tabla de auditoría.
     */
    public static function truncate(): void
    {
        throw new \LogicException('Los registros de auditoría son inmutables y no pueden truncarse.');
    }

    /**
     * Bloquea eliminaciones masivas y actualizaciones directas a nivel de Query Builder.
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder {
            public function delete()
            {
                throw new \LogicException('Los registros de auditoría son inmutables y no pueden eliminarse masivamente.');
            }

            public function update(array $values)
            {
                throw new \LogicException('Los registros de auditoría son inmutables y no pueden modificarse masivamente.');
            }
        };
    }
}
