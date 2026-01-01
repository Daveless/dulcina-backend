<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Cualquier trabajador autenticado puede crear pedidos
        return true;
    }

    public function rules(): array
    {
        return [
            // Datos del cliente
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:20',
            'customer_email' => 'nullable|email|max:255',
            'delivery_address' => 'required|string|max:500',

            // Datos del pedido
            'delivery_datetime' => 'required|date|after:now',
            'gift_message' => 'nullable|string|max:500',
            'materials_cost' => 'required|numeric|min:0',
            'shipping_cost' => 'required|numeric|min:0',
            'internal_notes' => 'nullable|string|max:1000',

            // Items
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_name.required' => 'El nombre del cliente es obligatorio',
            'customer_phone.required' => 'El teléfono del cliente es obligatorio',
            'delivery_address.required' => 'La dirección de entrega es obligatoria',
            'delivery_datetime.after' => 'La fecha de entrega debe ser futura',
            'items.required' => 'El pedido debe tener al menos un producto',
            'items.*.product_id.exists' => 'El producto no existe',
        ];
    }
}
