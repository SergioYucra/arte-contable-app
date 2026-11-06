<?php

namespace App\Models\Builders;

use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Query Builder base de la bitácora de auditoría (S4).
 *
 * Toda operación de modificación o borrado lanza excepción. Como cualquier consulta que nace del
 * modelo Activity termina en este builder (query()->delete(), forceDelete(), truncate(), update(),
 * increment(), upsert(), getQuery()->..., toBase()->...), una sola clase cierra todos los caminos
 * de Eloquent. Las inserciones siguen permitidas: la bitácora debe poder escribir.
 *
 * Lo que no pasa por el modelo (DB::table(...), SQL directo) lo bloquean los triggers de la
 * migración 2026_11_05_000001_add_immutability_triggers_to_activity_log_table.
 */
class ImmutableActivityQueryBuilder extends Builder
{
    private const MESSAGE = 'Los registros de auditoría son inmutables: no pueden modificarse ni eliminarse.';

    public function delete($id = null): never
    {
        throw new LogicException(self::MESSAGE);
    }

    public function update(array $values): never
    {
        throw new LogicException(self::MESSAGE);
    }

    public function truncate(): never
    {
        throw new LogicException(self::MESSAGE);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new LogicException(self::MESSAGE);
    }
}
