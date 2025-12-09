<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // Get user's orders with filters
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Order::where('user_id', $user->id)
                       ->with(['items.product'])
                       ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status') && in_array($request->status, Order::STATUSES)) {
            $query->where('status', $request->status);
        }

        // Filter by payment status
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [
                $request->start_date,
                $request->end_date
            ]);
        }

        // Filter by order number
        if ($request->has('order_number')) {
            $query->where('order_number', 'like', '%' . $request->order_number . '%');
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $orders = $query->paginate($perPage);

        // Add summary statistics
        $summary = [
            'total_orders' => Order::where('user_id', $user->id)->count(),
            'total_spent' => Order::where('user_id', $user->id)->sum('total_amount'),
            'pending_orders' => Order::where('user_id', $user->id)
                                    ->where('status', Order::STATUS_PENDING)
                                    ->count(),
            'delivered_orders' => Order::where('user_id', $user->id)
                                      ->where('status', Order::STATUS_DELIVERED)
                                      ->count(),
        ];

        return response()->json([
            'orders' => $orders,
            'summary' => $summary,
            'filters' => $request->only(['status', 'payment_status', 'start_date', 'end_date'])
        ]);
    }

    // Get user's order history (simplified version)
    public function history(Request $request)
    {
        $user = $request->user();
        
        $orders = Order::where('user_id', $user->id)
                       ->select([
                           'id',
                           'order_number',
                           'total_amount',
                           'status',
                           'payment_status',
                           'created_at'
                       ])
                       ->withCount('items')
                       ->orderBy('created_at', 'desc')
                       ->paginate(10);

        return response()->json([
            'orders' => $orders,
            'total_orders' => $orders->total(),
            'total_spent' => Order::where('user_id', $user->id)->sum('total_amount')
        ]);
    }

    // Place order from cart
    public function store(Request $request)
    {
        $user = $request->user();

        // Get user's cart
        $cart = Cart::where('user_id', $user->id)
                    ->with('product')
                    ->get();

        if ($cart->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 400);
        }

        // Validate cart items stock
        foreach ($cart as $item) {
            if ($item->product->stock_quantity < $item->quantity) {
                return response()->json([
                    'message' => "Insufficient stock for product: {$item->product->name}"
                ], 400);
            }
        }

        // Start database transaction
        DB::beginTransaction();

        try {
            // Calculate total
            $total = $cart->sum(function ($item) {
                return $item->quantity * $item->product->price;
            });

            // Create order
            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'user_id' => $user->id,
                'total_amount' => $total,
                'shipping_address' => $request->shipping_address,
                'billing_address' => $request->billing_address ?? $request->shipping_address,
                'payment_method' => $request->payment_method,
                'payment_status' => Order::PAYMENT_PENDING,
                'notes' => $request->notes
            ]);

            // Create order items and update stock
            foreach ($cart as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->product->price
                ]);

                // Update product stock
                $item->product->decrement('stock_quantity', $item->quantity);
            }

            // Clear cart
            Cart::where('user_id', $user->id)->delete();

            DB::commit();

            return response()->json([
                'message' => 'Order placed successfully',
                'order' => $order->load('items.product'),
                'order_number' => $order->order_number
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Failed to place order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Get specific order
    public function show(Request $request, $id)
    {
        $order = Order::with(['items.product', 'user'])
                      ->findOrFail($id);

        // Check if order belongs to user or user is admin
        if ($order->user_id !== $request->user()->id && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'order' => $order,
            'status_color' => $order->status_color,
            'payment_status_color' => $order->payment_status_color,
            'can_cancel' => $order->canBeCancelled()
        ]);
    }

    // Cancel order
    public function cancel(Request $request, $id)
    {
        $order = Order::with('items.product')->findOrFail($id);

        // Check if order belongs to user
        if ($order->user_id !== $request->user()->id && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if order can be cancelled
        if (!$order->canBeCancelled()) {
            return response()->json([
                'message' => 'Order cannot be cancelled at this stage'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Restore stock for each item
            foreach ($order->items as $item) {
                if ($item->product) {
                    $item->product->increment('stock_quantity', $item->quantity);
                }
            }

            // Update order status
            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'payment_status' => $order->payment_status === Order::PAYMENT_PAID 
                    ? Order::PAYMENT_REFUNDED 
                    : $order->payment_status
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Order cancelled successfully',
                'order' => $order->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Update order status (Admin only)
    public function updateStatus(Request $request, $id)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $order = Order::findOrFail($id);

        $request->validate([
            'status' => 'required|in:' . implode(',', Order::STATUSES),
            'notes' => 'nullable|string',
            'tracking_number' => 'nullable|string|max:100'
        ]);

        $oldStatus = $order->status;
        $newStatus = $request->status;

        // Additional validation for status transitions
        if ($newStatus === Order::STATUS_CANCELLED && !$order->canBeCancelled()) {
            return response()->json([
                'message' => 'Cannot cancel order at this stage'
            ], 400);
        }

        // Update order
        $updateData = ['status' => $newStatus];
        
        if ($request->has('notes')) {
            $updateData['notes'] = $request->notes;
        }
        
        if ($request->has('tracking_number') && $newStatus === Order::STATUS_SHIPPED) {
            $updateData['tracking_number'] = $request->tracking_number;
        }

        // If order is delivered, update payment status if still pending
        if ($newStatus === Order::STATUS_DELIVERED && $order->payment_status === Order::PAYMENT_PENDING) {
            $updateData['payment_status'] = Order::PAYMENT_PAID;
        }

        $order->update($updateData);

        return response()->json([
            'message' => 'Order status updated successfully',
            'order' => $order->load('user'),
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);
    }

    // Update payment status (Admin only)
    public function updatePaymentStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $order = Order::findOrFail($id);

        $request->validate([
            'payment_status' => 'required|in:' . implode(',', [
                Order::PAYMENT_PENDING,
                Order::PAYMENT_PAID,
                Order::PAYMENT_FAILED,
                Order::PAYMENT_REFUNDED
            ])
        ]);

        $oldPaymentStatus = $order->payment_status;
        $order->update(['payment_status' => $request->payment_status]);

        return response()->json([
            'message' => 'Payment status updated successfully',
            'order' => $order,
            'old_payment_status' => $oldPaymentStatus,
            'new_payment_status' => $request->payment_status
        ]);
    }

    // Get order statistics for user
    public function statistics(Request $request)
    {
        $user = $request->user();

        $stats = DB::table('orders')
            ->selectRaw('
                COUNT(*) as total_orders,
                SUM(total_amount) as total_spent,
                COUNT(CASE WHEN status = ? THEN 1 END) as pending_orders,
                COUNT(CASE WHEN status = ? THEN 1 END) as processing_orders,
                COUNT(CASE WHEN status = ? THEN 1 END) as shipped_orders,
                COUNT(CASE WHEN status = ? THEN 1 END) as delivered_orders,
                COUNT(CASE WHEN status = ? THEN 1 END) as cancelled_orders,
                COUNT(CASE WHEN payment_status = ? THEN 1 END) as pending_payments,
                COUNT(CASE WHEN payment_status = ? THEN 1 END) as paid_payments
            ', [
                Order::STATUS_PENDING,
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,
                Order::STATUS_DELIVERED,
                Order::STATUS_CANCELLED,
                Order::PAYMENT_PENDING,
                Order::PAYMENT_PAID
            ])
            ->where('user_id', $user->id)
            ->first();

        // Monthly spending
        $monthlySpending = DB::table('orders')
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as order_count,
                SUM(total_amount) as total_amount
            ')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->get();

        // Most ordered products
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw('
                products.id,
                products.name,
                COUNT(*) as times_ordered,
                SUM(order_items.quantity) as total_quantity,
                SUM(order_items.quantity * order_items.price) as total_spent
            ')
            ->where('orders.user_id', $user->id)
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_quantity', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'summary' => $stats,
            'monthly_spending' => $monthlySpending,
            'top_products' => $topProducts,
            'currency' => 'USD'
        ]);
    }

    // Get recent orders for user dashboard
    public function recentOrders(Request $request)
    {
        $user = $request->user();
        
        $orders = Order::where('user_id', $user->id)
                       ->select([
                           'id',
                           'order_number',
                           'total_amount',
                           'status',
                           'payment_status',
                           'created_at'
                       ])
                       ->with(['items' => function($query) {
                           $query->select('order_id', 'product_id', 'quantity')
                                 ->with(['product:id,name,price']);
                       }])
                       ->orderBy('created_at', 'desc')
                       ->limit(5)
                       ->get();

        return response()->json($orders);
    }

    // Admin: Get all orders
    public function adminIndex(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Order::with(['user:id,name,email', 'items.product'])
                       ->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('order_number')) {
            $query->where('order_number', 'like', '%' . $request->order_number . '%');
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        $perPage = $request->get('per_page', 20);
        $orders = $query->paginate($perPage);

        // Statistics for admin
        $stats = [
            'total_orders' => Order::count(),
            'total_revenue' => Order::where('payment_status', Order::PAYMENT_PAID)->sum('total_amount'),
            'pending_orders' => Order::where('status', Order::STATUS_PENDING)->count(),
            'today_orders' => Order::whereDate('created_at', today())->count()
        ];

        return response()->json([
            'orders' => $orders,
            'stats' => $stats
        ]);
    }
}