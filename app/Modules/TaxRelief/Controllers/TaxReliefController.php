<?php

namespace App\Modules\TaxRelief\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TaxRelief\Exceptions\UpstreamUnavailable;
use App\Modules\TaxRelief\Requests\BreakdownRequest;
use App\Modules\TaxRelief\Requests\CalculatePlanRequest;
use App\Modules\TaxRelief\Requests\DueDateRequest;
use App\Modules\TaxRelief\Services\TaxReliefService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Calculadora de alivio tributario para estudiantes.
 * Controlador delgado: la lógica y las protecciones viven en Services/.
 */
class TaxReliefController extends Controller
{
    public function __construct(private readonly TaxReliefService $service) {}

    public function index(): View
    {
        return view('tax-relief.index', [
            'props' => [
                'enabled' => (bool) config('tax_relief.enabled'),
                'today' => CalculatePlanRequest::today()->toDateString(),
                'maxDebts' => (int) config('tax_relief.max_debts'),
                'maxInstallments' => (int) config('tax_relief.max_installments'),
                'calculateUrl' => route('tax-relief.calculate'),
                'breakdownUrl' => route('tax-relief.breakdown'),
                'dueDateUrl' => route('tax-relief.due-date'),
            ],
        ]);
    }

    public function calculate(CalculatePlanRequest $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->plan($request->validated()));
    }

    public function breakdown(BreakdownRequest $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->breakdown($request->validated()));
    }

    public function dueDate(DueDateRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->respond(fn () => [
            'date' => $this->service->dueDate($data['impuesto'], $data['periodo'], (int) $data['nit_digito']),
        ]);
    }

    /** Respuesta uniforme: {data} si salió bien; {message, reason} si no (sin detalles técnicos). */
    private function respond(Closure $compute): JsonResponse
    {
        try {
            return response()->json(['data' => $compute()]);
        } catch (UpstreamUnavailable $e) {
            return response()->json(array_filter([
                'message' => $e->userMessage(),
                'reason' => $e->reason,
                'detail' => $e->reason === UpstreamUnavailable::REJECTED ? $e->detail : null,
            ]), $e->httpStatus());
        }
    }
}
