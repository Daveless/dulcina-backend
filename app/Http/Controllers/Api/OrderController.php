<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Listar TODOS los pedidos (cualquier trabajador puede ver todos)
     */
    public function index(Request $request)
    {
        $query = Order::with(['items.product']);

        // Filtros opcionales
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date')) {
            $query->whereDate('delivery_datetime', $request->date);
        }

        if ($request->has('customer_name')) {
            $query->where('customer_name', 'like', '%' . $request->customer_name . '%');
        }

        $orders = $query->orderBy('delivery_datetime', 'asc')->paginate(15);

        return OrderResource::collection($orders);
    }

    /**
     * Crear un nuevo pedido
     */
    public function store(StoreOrderRequest $request)
    {
        DB::beginTransaction();

        try {
            $subtotal = 0;

            // Calcular subtotal y verificar stock
            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);

                if ($product->stock < $item['quantity']) {
                    return response()->json([
                        'message' => "Stock insuficiente para {$product->name}. Disponible: {$product->stock}",
                    ], 400);
                }

                $subtotal += $product->price * $item['quantity'];
            }

            // Calcular total
            $total = $subtotal + $request->materials_cost + $request->shipping_cost;

            // Crear el pedido (SIN user_id)
            $order = Order::create([
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_email' => $request->customer_email,
                'delivery_address' => $request->delivery_address,
                'delivery_datetime' => $request->delivery_datetime,
                'gift_message' => $request->gift_message,
                'subtotal' => $subtotal,
                'materials_cost' => $request->materials_cost,
                'shipping_cost' => $request->shipping_cost,
                'total' => $total,
                'status' => 'pending',
                'internal_notes' => $request->internal_notes,
            ]);

            // Crear los items del pedido
            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'subtotal' => $product->price * $item['quantity'],
                ]);

                // Reducir stock
                $product->decrement('stock', $item['quantity']);
            }

            DB::commit();

            $order->load(['items.product']);

            return new OrderResource($order);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear el pedido',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar un pedido específico (cualquier trabajador puede verlo)
     */
    public function show(Order $order)
    {
        $order->load(['items.product']);

        return new OrderResource($order);
    }

    /**
     * Actualizar un pedido (cualquier trabajador puede actualizarlo)
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        $order->update($request->validated());

        $order->load(['items.product']);

        return new OrderResource($order);
    }

    /**
     * Eliminar un pedido (cualquier trabajador puede eliminarlo)
     */
    public function destroy(Order $order)
    {
        // Solo permitir eliminar pedidos pendientes
        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'Solo se pueden eliminar pedidos pendientes',
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Devolver el stock
            foreach ($order->items as $item) {
                $item->product->increment('stock', $item->quantity);
            }

            $order->delete();

            DB::commit();

            return response()->json([
                'message' => 'Pedido eliminado exitosamente',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al eliminar el pedido',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de pedidos
     */
    public function stats()
    {
        return response()->json([
            'total_orders' => Order::count(),
            'pending' => Order::where('status', 'pending')->count(),
            'processing' => Order::where('status', 'processing')->count(),
            'completed' => Order::where('status', 'completed')->count(),
            'cancelled' => Order::where('status', 'cancelled')->count(),
            'today_deliveries' => Order::whereDate('delivery_datetime', today())->count(),
        ]);
    }
}
