<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    /**
     * Blindaje de inmutabilidad:
     * Impide cualquier modificación o eliminación de los registros de auditoría.
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
}
