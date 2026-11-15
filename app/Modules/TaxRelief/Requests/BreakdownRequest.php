<?php

namespace App\Modules\TaxRelief\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Desglose de una sola deuda ("¿De dónde salen los montos?"). */
class BreakdownRequest extends FormRequest
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
            'impuesto' => $this->taxRules(),
            'periodo' => $this->periodRules(),
            'fecha_vencimiento' => $this->dueDateRules(),
            'tributo_omitido' => $this->amountRules(),
            'con_sancion' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->commonAttributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->commonMessages();
    }
}
