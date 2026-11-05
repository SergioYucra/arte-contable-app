<?php

namespace App\Models;

use App\Models\Builders\ImmutableActivityQueryBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Bitácora de auditoría inmutable (S4), blindada en tres capas:
 *
 *  1. Modelo: los eventos updating/deleting, los métodos delete()/forceDelete() de instancia
 *     (que atrapan también deleteQuietly()/forceDeleteQuietly(), que no disparan eventos)
 *     y truncate() estático.
 *  2. Query Builder base: ImmutableActivityQueryBuilder bloquea delete/update/truncate/upsert en
 *     cualquier consulta que nazca del modelo (query(), getQuery(), toBase(), increment(), ...).
 *  3. Base de datos: triggers BEFORE DELETE / BEFORE UPDATE sobre la tabla
 *     (migración 2026_11_05_000001), que cubren DB::table(...) y el SQL directo.
 */
class Activity extends SpatieActivity
{
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
     * Toda consulta del modelo usa el builder base inmutable (capa 2).
     */
    protected function newBaseQueryBuilder(): QueryBuilder
    {
        $connection = $this->getConnection();

        return new ImmutableActivityQueryBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        );
    }
}
