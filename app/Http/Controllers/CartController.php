<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    // Get user's cart
    public function index(Request $request)
    {
        $cart = Cart::where('user_id', $request->user()->id)
                    ->with('product.category')
                    ->get();

        $total = $cart->sum(function ($item) {
            return $item->quantity * $item->product->price;
        });

        return response()->json([
            'cart' => $cart,
            'total' => $total
        ]);
    }

    // Add to cart
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1'
        ]);

        $product = Product::findOrFail($request->product_id);

        // Check stock
        if ($product->stock_quantity < $request->quantity) {
            return response()->json(['message' => 'Insufficient stock'], 400);
        }

        // Check if already in cart
        $cartItem = Cart::where('user_id', $request->user()->id)
                        ->where('product_id', $request->product_id)
                        ->first();

        if ($cartItem) {
            // Update quantity
            $cartItem->quantity += $request->quantity;
            $cartItem->save();
        } else {
            // Create new cart item
            $cartItem = Cart::create([
                'user_id' => $request->user()->id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity
            ]);
        }

        return response()->json([
            'message' => 'Added to cart',
            'cart_item' => $cartItem->load('product')
        ], 201);
    }

    // Update cart item quantity
    public function update(Request $request, $id)
    {
        $cartItem = Cart::findOrFail($id);

        // Check if cart item belongs to user
        if ($cartItem->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'quantity' => 'required|integer|min:1'
        ]);

        // Check stock
        $product = Product::findOrFail($cartItem->product_id);
        if ($product->stock_quantity < $request->quantity) {
            return response()->json(['message' => 'Insufficient stock'], 400);
        }

        $cartItem->update(['quantity' => $request->quantity]);

        return response()->json([
            'message' => 'Cart updated',
            'cart_item' => $cartItem->load('product')
        ]);
    }

    // Remove from cart
    public function destroy(Request $request, $id)
    {
        $cartItem = Cart::findOrFail($id);

        if ($cartItem->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cartItem->delete();

        return response()->json([
            'message' => 'Removed from cart'
        ]);
    }

    // Clear cart
    public function clear(Request $request)
    {
        Cart::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'message' => 'Cart cleared'
        ]);
    }
}