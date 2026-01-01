<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Información del cliente
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
            ],

            // Información de entrega
            'delivery' => [
                'address' => $this->delivery_address,
                'datetime' => $this->delivery_datetime->format('Y-m-d H:i:s'),
            ],

            'gift_message' => $this->gift_message,

            // Costos
            'costs' => [
                'subtotal' => (float) $this->subtotal,
                'materials' => (float) $this->materials_cost,
                'shipping' => (float) $this->shipping_cost,
                'total' => (float) $this->total,
            ],

            'status' => $this->status,
            'internal_notes' => $this->internal_notes,

            // Items del pedido
            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            // Timestamps
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
