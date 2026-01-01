<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Cualquier trabajador puede actualizar cualquier pedido
        return true;
    }

    public function rules(): array
    {
        return [
            // Datos del cliente
            'customer_name' => 'sometimes|string|max:255',
            'customer_phone' => 'sometimes|string|max:20',
            'customer_email' => 'nullable|email|max:255',
            'delivery_address' => 'sometimes|string|max:500',

            // Datos del pedido
            'delivery_datetime' => 'sometimes|date|after:now',
            'gift_message' => 'nullable|string|max:500',
            'materials_cost' => 'sometimes|numeric|min:0',
            'shipping_cost' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:pending,processing,completed,cancelled',
            'internal_notes' => 'nullable|string|max:1000',
        ];
    }
}
