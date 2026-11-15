<?php

namespace App\Support;

/**
 * Arma patrones LIKE a partir de texto escrito por una persona, sin que sus `%` o `_`
 * actúen como comodines.
 *
 * Se usa `!` como carácter de escape porque no necesita escaparse a su vez en MySQL ni en SQLite
 * (la barra invertida sí, y de forma distinta en cada motor). Usar siempre con la cláusula ESCAPE:
 *
 *   $query->whereRaw("users.name LIKE ? ESCAPE '!'", [LikePattern::contains($term)]);
 */
final class LikePattern
{
    public const ESCAPE_CLAUSE = "ESCAPE '!'";

    public static function contains(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)).'%';
    }
}
