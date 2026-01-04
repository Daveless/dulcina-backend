<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'meta_product_id',
        'retailer_id',
        'name',
        'description',
        'price',
        'images',
        'stock',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'images' => 'array', // JSON a array automáticamente
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
