<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_address',
        'delivery_datetime',
        'gift_message',
        'subtotal',
        'materials_cost',
        'shipping_cost',
        'total',
        'status',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'delivery_datetime' => 'datetime',
            'subtotal' => 'decimal:2',
            'materials_cost' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    // ELIMINAR la relación con User
    // public function user() { ... }  ← BORRAR ESTO

    // Mantener la relación con items
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Método helper para calcular total
    public function calculateTotal(): float
    {
        return $this->subtotal + $this->materials_cost + $this->shipping_cost;
    }

    // Scope para filtrar por estado
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // Scope para pedidos de hoy
    public function scopeToday($query)
    {
        return $query->whereDate('delivery_datetime', today());
    }
}
