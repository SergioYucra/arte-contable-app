<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * P8 (MVP 2.4, A09:2025): visor de solo lectura de la bitácora de auditoría.
 *
 * La bitácora es inmutable (S4), así que aquí solo se consulta. La ruta exige 'view-audit-logs',
 * que en la matriz de roles solo tiene el Superadministrador.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    /** @var array<string, string> */
    public const LOG_LABELS = [
        'security' => 'Seguridad',
        'users' => 'Usuarios',
        'default' => 'General',
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'log' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'causer' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $activities = Activity::query()
            ->with('causer:id,name,email')
            ->when($filters['log'] ?? null, fn (Builder $query, string $log) => $query->where('log_name', $log))
            ->when($filters['q'] ?? null, fn (Builder $query, string $text) => $query->where('description', 'like', '%'.$text.'%'))
            ->when($filters['causer'] ?? null, function (Builder $query, string $causer): void {
                $query->whereHasMorph('causer', [User::class], function (Builder $causerQuery) use ($causer): void {
                    $causerQuery->withTrashed()->where(function (Builder $match) use ($causer): void {
                        $match->where('email', 'like', '%'.$causer.'%')
                            ->orWhere('name', 'like', '%'.$causer.'%');
                    });
                });
            })
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $logNames = Activity::query()->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values();

        return view('admin.audit-logs.index', [
            'activities' => $activities,
            'logNames' => $logNames,
            'logLabels' => self::LOG_LABELS,
            'filters' => $filters,
        ]);
    }
}
