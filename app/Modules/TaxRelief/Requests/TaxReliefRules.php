<?php

namespace App\Modules\TaxRelief\Requests;

/**
 * Reglas compartidas por los formularios de la calculadora.
 * Los límites vienen de config/tax_relief.php.
 */
trait TaxReliefRules
{
    public const TAXES = ['IVA', 'IT', 'IUE', 'RC-IVA', 'Otro'];

    /** @return list<string> */
    protected function requestDateRules(): array
    {
        return [
            'required', 'date_format:Y-m-d', 'after_or_equal:2016-01-01',
            'before_or_equal:'.CalculatePlanRequest::today()->addYear()->toDateString(),
        ];
    }

    /** @return list<string> */
    protected function taxRules(): array
    {
        return ['required', 'string', 'in:'.implode(',', self::TAXES)];
    }

    /** @return list<string> */
    protected function periodRules(): array
    {
        return ['required', 'string', 'regex:/^(19|20)\d{2}-(0[1-9]|1[0-2])$/'];
    }

    /** @return list<string> */
    protected function dueDateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:fecha_solicitud'];
    }

    /** @return list<string> */
    protected function amountRules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.(int) config('tax_relief.max_amount')];
    }

    /** @return array<string, string> */
    protected function commonAttributes(): array
    {
        return [
            'fecha_solicitud' => 'fecha de solicitud',
            'impuesto' => 'impuesto',
            'periodo' => 'periodo',
            'fecha_vencimiento' => 'fecha de vencimiento',
            'tributo_omitido' => 'tributo omitido',
        ];
    }

    /** @return array<string, string> */
    protected function commonMessages(): array
    {
        return [
            'regex' => 'El :attribute debe tener el formato año-mes (por ejemplo, 2019-01).',
            'min' => 'El :attribute debe ser mayor a cero.',
            'decimal' => 'El :attribute puede tener como máximo 2 decimales.',
            'fecha_vencimiento.before_or_equal' => 'La fecha de vencimiento no puede ser posterior a la fecha de solicitud.',
        ];
    }
}
