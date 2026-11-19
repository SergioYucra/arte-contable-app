<?php

namespace App\Modules\LibraryProtection\Console;

use App\Modules\LibraryProtection\Models\BookPageView;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Borra el registro de páginas vistas más antiguo que `retention_days` (180 por defecto).
 * Guarda IP y hábitos de lectura: no conviene conservarlo para siempre.
 */
class PrunePageViewsCommand extends Command
{
    protected $signature = 'libros:limpiar-registro';

    protected $description = 'Borra del registro de lectura las páginas vistas hace más de los días configurados';

    public function handle(): int
    {
        $days = max(30, (int) config('library.protection.retention_days', 180));
        $cutoff = Carbon::now((string) config('library.protection.timezone', 'America/La_Paz'))
            ->subDays($days)
            ->toDateString();

        $deleted = 0;
        do {
            $ids = BookPageView::where('viewed_on', '<', $cutoff)->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : BookPageView::whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());

        $this->info("Registro de lectura: se borraron {$deleted} filas anteriores al {$cutoff} ({$days} días).");

        return self::SUCCESS;
    }
}
