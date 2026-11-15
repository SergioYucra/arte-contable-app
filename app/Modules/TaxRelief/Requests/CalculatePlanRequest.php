<?php

namespace App\Modules\TaxRelief\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Plan al contado / en cuotas. Solo se usan los campos validados: cualquier
 * otro campo que llegue del navegador se descarta y nunca sale hacia afuera.
 */
class CalculatePlanRequest extends FormRequest
{
    use TaxReliefRules;

    public function authorize(): bool
    {
        return true; // El acceso por rol lo controla la ruta.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_solicitud' => $this->requestDateRules(),
            'garantia' => ['required', 'string', 'in:efectivo,hipotecaria'],
            'cuotas' => ['required', 'integer', 'between:1,'.(int) config('tax_relief.max_installments')],
            'deudas' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('tax_relief.max_debts')],
            'deudas.*' => ['required', 'array:impuesto,periodo,fecha_vencimiento,tributo_omitido'],
            'deudas.*.impuesto' => $this->taxRules(),
            'deudas.*.periodo' => $this->periodRules(),
            'deudas.*.fecha_vencimiento' => $this->dueDateRules(),
            'deudas.*.tributo_omitido' => $this->amountRules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->commonAttributes() + [
            'garantia' => 'tipo de garantía',
            'cuotas' => 'número de cuotas',
            'deudas' => 'deudas',
            'deudas.*.impuesto' => 'impuesto',
            'deudas.*.periodo' => 'periodo',
            'deudas.*.fecha_vencimiento' => 'fecha de vencimiento',
            'deudas.*.tributo_omitido' => 'tributo omitido',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->commonMessages() + [
            'deudas.max' => 'Puedes calcular hasta :max deudas a la vez.',
            'deudas.*.fecha_vencimiento.before_or_equal' => 'La fecha de vencimiento no puede ser posterior a la fecha de solicitud.',
        ];
    }

    public static function today(): Carbon
    {
        return Carbon::now((string) config('tax_relief.timezone'))->startOfDay();
    }
}
