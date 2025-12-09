<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    // Get user's wishlist
    public function index(Request $request)
    {
        $wishlist = Wishlist::where('user_id', $request->user()->id)
                            ->with('product.category')
                            ->get();

        return response()->json($wishlist);
    }

    // Add to wishlist
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        // Check if already in wishlist
        $existing = Wishlist::where('user_id', $request->user()->id)
                            ->where('product_id', $request->product_id)
                            ->first();

        if ($existing) {
            return response()->json(['message' => 'Product already in wishlist'], 400);
        }

        $wishlist = Wishlist::create([
            'user_id' => $request->user()->id,
            'product_id' => $request->product_id
        ]);

        return response()->json([
            'message' => 'Added to wishlist',
            'wishlist' => $wishlist->load('product')
        ], 201);
    }

    // Remove from wishlist
    public function destroy(Request $request, $id)
    {
        $wishlist = Wishlist::findOrFail($id);

        // Check if wishlist belongs to user
        if ($wishlist->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $wishlist->delete();

        return response()->json([
            'message' => 'Removed from wishlist'
        ]);
    }
}