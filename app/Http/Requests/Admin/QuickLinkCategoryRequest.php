<?php

namespace App\Http\Requests\Admin;

use App\Models\QuickLinkCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuickLinkCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $rawName = is_string($this->input('name'))
            ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $this->input('name')) ?? '')
            : '';

        $this->merge([
            'name' => $rawName,
            'sort_order' => (int) $this->input('sort_order', 0),
            'is_active' => filter_var($this->input('is_active', true), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var QuickLinkCategory|int|string|null $routeCategory */
        $routeCategory = $this->route('category');
        $ignoreId = $routeCategory instanceof QuickLinkCategory
            ? $routeCategory->id
            : (is_numeric($routeCategory) ? (int) $routeCategory : null);

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                Rule::unique('quick_link_categories', 'name')->ignore($ignoreId),
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Custom validation messages in Spanish.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ingresa el nombre de la categoría.',
            'name.min' => 'El nombre de la categoría debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre de la categoría no puede superar los 100 caracteres.',
            'name.unique' => 'Ya existe una categoría registrada con este mismo nombre.',
            'sort_order.required' => 'Indica el orden de aparición de la categoría.',
            'sort_order.integer' => 'El orden debe ser un número entero válido.',
            'sort_order.min' => 'El orden mínimo permitido es 0.',
            'sort_order.max' => 'El orden máximo permitido es 9999.',
        ];
    }
}
