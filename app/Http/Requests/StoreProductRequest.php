<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'image_url' => 'nullable|url',
            'stock' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio',
            'price.required' => 'El precio es obligatorio',
            'price.min' => 'El precio debe ser mayor o igual a 0',
            'stock.required' => 'El stock es obligatorio',
            'image_url.url' => 'La URL de la imagen no es válida',
        ];
    }
}
