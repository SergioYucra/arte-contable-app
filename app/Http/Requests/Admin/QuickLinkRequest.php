<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuickLinkRequest extends FormRequest
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
        $title = is_string($this->input('title'))
            ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $this->input('title')) ?? '')
            : '';

        $description = is_string($this->input('description'))
            ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $this->input('description')) ?? '')
            : '';

        $url = is_string($this->input('url'))
            ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $this->input('url')) ?? '')
            : '';

        $this->merge([
            'quick_link_category_id' => $this->input('quick_link_category_id') !== null && $this->input('quick_link_category_id') !== ''
                ? (int) $this->input('quick_link_category_id')
                : null,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'url' => $url,
            'sort_order' => (int) $this->input('sort_order', 0),
            'is_active' => filter_var($this->input('is_active', true), FILTER_VALIDATE_BOOLEAN),
            'remove_logo' => filter_var($this->input('remove_logo', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quick_link_category_id' => [
                'required',
                'integer',
                Rule::exists('quick_link_categories', 'id')->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'remove_logo' => ['required', 'boolean'],
            'logo' => ['nullable', 'file', 'mimetypes:image/webp', 'mimes:webp', 'max:256'],
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
            'quick_link_category_id.required' => 'Selecciona la categoría a la que pertenece el enlace.',
            'quick_link_category_id.exists' => 'La categoría seleccionada no es válida o fue archivada.',
            'title.required' => 'Ingresa el título del acceso rápido.',
            'title.min' => 'El título debe tener al menos 3 caracteres.',
            'title.max' => 'El título no debe superar los 120 caracteres.',
            'description.max' => 'La descripción breve no debe superar los 255 caracteres.',
            'url.required' => 'Ingresa la dirección web oficial (con http:// o https://).',
            'url.url' => 'Ingresa una dirección web válida que inicie con https:// o http://.',
            'url.max' => 'La dirección web no debe superar los 500 caracteres.',
            'sort_order.required' => 'Indica el orden de aparición del enlace.',
            'sort_order.integer' => 'El orden debe ser un número entero válido.',
            'sort_order.min' => 'El orden mínimo permitido es 0.',
            'sort_order.max' => 'El orden máximo permitido es 9999.',
            'logo.mimetypes' => 'El logotipo debe ser una imagen estandarizada en formato WebP.',
            'logo.mimes' => 'El logotipo debe estar en formato WebP.',
            'logo.max' => 'La imagen estandarizada no debe superar los 256 KB.',
        ];
    }
}
