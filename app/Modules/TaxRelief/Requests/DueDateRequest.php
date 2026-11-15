<?php

namespace App\Modules\TaxRelief\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Vencimiento sugerido. Del NIT solo se pide (y solo se envía afuera) el
 * último dígito: el NIT completo nunca sale del navegador.
 */
class DueDateRequest extends FormRequest
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
            // "Otro" no tiene calendario de vencimientos.
            'impuesto' => ['required', 'string', 'in:IVA,IT,IUE,RC-IVA'],
            'periodo' => $this->periodRules(),
            'nit_digito' => ['required', 'integer', 'between:0,9'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->commonAttributes() + ['nit_digito' => 'último dígito del NIT'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->commonMessages();
    }
}
