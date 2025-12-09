<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    // Display all products with filters
    public function index(Request $request)
    {
        try {
            $query = Product::with(['category:id,name,slug', 'approvedReviews'])
                           ->where('is_active', true);

            // Filter by category
            if ($request->has('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            // Filter by category slug
            if ($request->has('category_slug')) {
                $query->whereHas('category', function($q) use ($request) {
                    $q->where('slug', $request->category_slug);
                });
            }

            // Search by name or description
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%')
                      ->orWhere('sku', 'like', '%' . $search . '%');
                });
            }

            // Price range
            if ($request->has('min_price')) {
                $query->where('price', '>=', $request->min_price);
            }
            if ($request->has('max_price')) {
                $query->where('price', '<=', $request->max_price);
            }

            // Stock availability
            if ($request->has('in_stock')) {
                $query->where('stock_quantity', '>', 0);
            }

            // Rating filter
            if ($request->has('min_rating')) {
                $query->whereHas('approvedReviews', function($q) use ($request) {
                    $q->select(DB::raw('AVG(rating)'))
                      ->havingRaw('AVG(rating) >= ?', [$request->min_rating]);
                });
            }

            // Sort options
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            
            $validSortColumns = ['name', 'price', 'created_at', 'updated_at'];
            if (in_array($sortBy, $validSortColumns)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Special sorting by rating or popularity
            if ($sortBy === 'rating') {
                $query->orderBy(
                    DB::raw('(SELECT AVG(rating) FROM reviews WHERE product_id = products.id AND status = "approved")'),
                    $sortOrder
                );
            } elseif ($sortBy === 'popularity') {
                $query->orderBy(
                    DB::raw('(SELECT SUM(quantity) FROM order_items 
                             WHERE product_id = products.id 
                             AND EXISTS (SELECT 1 FROM orders WHERE orders.id = order_items.order_id AND orders.status = "delivered"))'),
                    $sortOrder
                );
            }

            $perPage = $request->get('per_page', 12);
            $products = $query->paginate($perPage);

            // Add average rating to each product
            $products->getCollection()->transform(function($product) {
                $product->average_rating = $product->averageRating();
                $product->total_reviews = $product->approvedReviewsCount();
                return $product;
            });

            return response()->json([
                'success' => true,
                'data' => $products,
                'filters' => $request->only(['search', 'category_id', 'min_price', 'max_price', 'sort_by', 'sort_order'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Store new product (admin only)
    public function store(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $request->validate([
                'name' => 'required|string|max:255|unique:products,name',
                'description' => 'required|string',
                'price' => 'required|numeric|min:0',
                'stock_quantity' => 'required|integer|min:0',
                'category_id' => 'required|exists:categories,id',
                'images' => 'nullable|array',
                'images.*' => 'string|url',
                'sku' => 'nullable|string|max:100|unique:products,sku',
                'is_active' => 'boolean'
            ]);

            // Generate SKU if not provided
            $sku = $request->sku;
            if (empty($sku)) {
                $sku = 'SKU-' . strtoupper(Str::random(8)) . '-' . time();
            }

            $product = Product::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . Str::random(6),
                'description' => $request->description,
                'price' => $request->price,
                'stock_quantity' => $request->stock_quantity,
                'sku' => $sku,
                'images' => $request->images ?? [],
                'category_id' => $request->category_id,
                'is_active' => $request->is_active ?? true
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product->load('category')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Display specific product
    public function show($id)
    {
        try {
            $product = Product::with([
                    'category:id,name,slug,description',
                    'reviews.user:id,name,email',
                    'approvedReviews.user:id,name'
                ])
                ->withCount(['approvedReviews as total_reviews'])
                ->withAvg('approvedReviews as average_rating', 'rating')
                ->findOrFail($id);

            // Check if product is active or user is admin
            if (!$product->is_active && (!auth()->check() || auth()->user()->role !== 'admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not available'
                ], 404);
            }

            // Get rating distribution
            $product->rating_distribution = $this->getProductRatingDistribution($product->id);

            // Get related products (same category)
            $relatedProducts = Product::where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)
                ->where('is_active', true)
                ->withCount(['approvedReviews as total_reviews'])
                ->withAvg('approvedReviews as average_rating', 'rating')
                ->limit(4)
                ->get();

            // Get sales data if available
            $salesData = OrderItem::where('product_id', $product->id)
                ->select(
                    DB::raw('SUM(quantity) as total_sold'),
                    DB::raw('COUNT(DISTINCT order_id) as total_orders')
                )
                ->first();

            $product->sales_data = [
                'total_sold' => $salesData->total_sold ?? 0,
                'total_orders' => $salesData->total_orders ?? 0
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => $product,
                    'related_products' => $relatedProducts
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Update product (admin only)
    public function update(Request $request, $id)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $product = Product::findOrFail($id);

            $request->validate([
                'name' => 'required|string|max:255|unique:products,name,' . $id,
                'description' => 'required|string',
                'price' => 'required|numeric|min:0',
                'stock_quantity' => 'required|integer|min:0',
                'category_id' => 'required|exists:categories,id',
                'images' => 'nullable|array',
                'images.*' => 'string|url',
                'sku' => 'nullable|string|max:100|unique:products,sku,' . $id,
                'is_active' => 'boolean'
            ]);

            $updateData = [
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'stock_quantity' => $request->stock_quantity,
                'category_id' => $request->category_id,
                'is_active' => $request->is_active ?? $product->is_active
            ];

            // Update SKU if provided
            if ($request->has('sku')) {
                $updateData['sku'] = $request->sku;
            }

            // Update images if provided
            if ($request->has('images')) {
                $updateData['images'] = $request->images;
            }

            // Update slug if name changed
            if ($product->name !== $request->name) {
                $updateData['slug'] = Str::slug($request->name) . '-' . Str::random(6);
            }

            $product->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->fresh()->load('category')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Delete product (admin only)
    public function destroy(Request $request, $id)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $product = Product::findOrFail($id);

            // Check if product has associated orders
            $hasOrders = OrderItem::where('product_id', $id)->exists();
            if ($hasOrders) {
                // Soft delete if product has orders
                $product->update(['is_active' => false]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Product deactivated successfully (cannot delete due to existing orders)',
                    'data' => $product
                ]);
            }

            // Hard delete if no orders
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get most sold products (public API)
     */
    public function mostSold(Request $request)
    {
        try {
            $request->validate([
                'limit' => 'nullable|integer|min:1|max:50',
                'category_id' => 'nullable|exists:categories,id',
                'period' => 'nullable|in:all_time,monthly,weekly,daily',
                'min_sold' => 'nullable|integer|min:0'
            ]);

            $limit = $request->get('limit', 10);
            
            // Base query for order items with delivered orders only
            $query = OrderItem::query()
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->select([
                    'order_items.product_id',
                    DB::raw('SUM(order_items.quantity) as total_sold'),
                    DB::raw('COUNT(DISTINCT order_items.order_id) as total_orders'),
                    DB::raw('SUM(order_items.quantity * order_items.price) as total_revenue')
                ])
                ->where('orders.status', 'delivered') // Only count delivered orders
                ->groupBy('order_items.product_id')
                ->orderBy('total_sold', 'desc');

            // Filter by period if specified
            if ($request->has('period') && $request->period !== 'all_time') {
                $dateFilter = now()->sub($request->period);
                $query->where('orders.created_at', '>=', $dateFilter);
            }

            // Filter by minimum sold quantity
            if ($request->has('min_sold')) {
                $query->having('total_sold', '>=', $request->min_sold);
            }

            // Execute query to get product IDs with sales data
            $topSoldItems = $query->limit($limit)->get();

            // Get product details with eager loading
            $productIds = $topSoldItems->pluck('product_id')->toArray();
            
            $productsQuery = Product::with(['category:id,name,slug'])
                ->whereIn('id', $productIds)
                ->where('is_active', true);

            // Filter by category if specified
            if ($request->has('category_id')) {
                $productsQuery->where('category_id', $request->category_id);
            }

            $products = $productsQuery->get();

            // Add sales data and rating to products
            $products = $products->map(function($product) use ($topSoldItems) {
                $soldData = $topSoldItems->firstWhere('product_id', $product->id);
                
                if ($soldData) {
                    $product->total_sold = $soldData->total_sold;
                    $product->total_orders = $soldData->total_orders;
                    $product->total_revenue = $soldData->total_revenue;
                } else {
                    $product->total_sold = 0;
                    $product->total_orders = 0;
                    $product->total_revenue = 0;
                }
                
                // Add rating data
                $product->average_rating = $product->averageRating();
                $product->total_reviews = $product->approvedReviewsCount();
                
                return $product;
            });

            // Sort by total sold
            $products = $products->sortByDesc('total_sold')->values();

            // Prepare response data
            $response = [
                'success' => true,
                'data' => $products,
                'meta' => [
                    'total' => $products->count(),
                    'limit' => $limit,
                    'period' => $request->get('period', 'all_time'),
                    'total_sold_sum' => $products->sum('total_sold'),
                    'total_revenue_sum' => $products->sum('total_revenue'),
                    'total_orders_sum' => $products->sum('total_orders'),
                ]
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch most sold products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get most reviewed products (public API)
     */
    public function mostReviewed(Request $request)
    {
        try {
            $request->validate([
                'limit' => 'nullable|integer|min:1|max:50',
                'category_id' => 'nullable|exists:categories,id',
                'review_type' => 'nullable|in:all,approved,pending',
                'min_rating' => 'nullable|integer|min:1|max:5',
                'min_reviews' => 'nullable|integer|min:0',
                'period' => 'nullable|in:all_time,monthly,weekly,daily'
            ]);

            $limit = $request->get('limit', 10);
            
            // Base query for reviews
            $reviewQuery = Review::query()
                ->select([
                    'product_id',
                    DB::raw('COUNT(*) as total_reviews'),
                    DB::raw('AVG(rating) as average_rating'),
                    DB::raw('SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved_reviews'),
                    DB::raw('SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_reviews'),
                    DB::raw('SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected_reviews')
                ])
                ->groupBy('product_id');

            // Apply filters to review query
            if ($request->has('review_type') && $request->review_type !== 'all') {
                if ($request->review_type === 'approved') {
                    $reviewQuery->where('status', Review::STATUS_APPROVED);
                } elseif ($request->review_type === 'pending') {
                    $reviewQuery->where('status', Review::STATUS_PENDING);
                }
            }

            if ($request->has('min_rating')) {
                $reviewQuery->where('rating', '>=', $request->min_rating);
            }

            if ($request->has('period') && $request->period !== 'all_time') {
                $dateFilter = now()->sub($request->period);
                $reviewQuery->where('created_at', '>=', $dateFilter);
            }

            // Filter by minimum reviews
            if ($request->has('min_reviews')) {
                $reviewQuery->having('total_reviews', '>=', $request->min_reviews);
            }

            // Execute query to get product IDs with review data
            $topReviewedProducts = $reviewQuery
                ->orderBy('total_reviews', 'desc')
                ->limit($limit)
                ->get();

            // Get product details
            $productIds = $topReviewedProducts->pluck('product_id')->toArray();
            
            $productsQuery = Product::with(['category:id,name,slug'])
                ->whereIn('id', $productIds)
                ->where('is_active', true);

            // Filter by category if specified
            if ($request->has('category_id')) {
                $productsQuery->where('category_id', $request->category_id);
            }

            $products = $productsQuery->get();

            // Add review data to products
            $products = $products->map(function($product) use ($topReviewedProducts) {
                $reviewData = $topReviewedProducts->firstWhere('product_id', $product->id);
                
                if ($reviewData) {
                    $product->total_reviews = $reviewData->total_reviews;
                    $product->approved_reviews = $reviewData->approved_reviews;
                    $product->pending_reviews = $reviewData->pending_reviews;
                    $product->rejected_reviews = $reviewData->rejected_reviews;
                    $product->average_rating = round($reviewData->average_rating, 1);
                } else {
                    $product->total_reviews = 0;
                    $product->approved_reviews = 0;
                    $product->pending_reviews = 0;
                    $product->rejected_reviews = 0;
                    $product->average_rating = 0;
                }
                
                // Get rating distribution for approved reviews
                if ($product->approved_reviews > 0) {
                    $product->rating_distribution = $this->getProductRatingDistribution($product->id);
                }
                
                // Get sales data
                $salesData = OrderItem::where('product_id', $product->id)
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->where('orders.status', 'delivered')
                    ->select(
                        DB::raw('SUM(order_items.quantity) as total_sold')
                    )
                    ->first();
                
                $product->total_sold = $salesData->total_sold ?? 0;
                
                return $product;
            });

            // Sort by total reviews
            $products = $products->sortByDesc('total_reviews')->values();

            // Prepare response data
            $response = [
                'success' => true,
                'data' => $products,
                'meta' => [
                    'total' => $products->count(),
                    'limit' => $limit,
                    'review_type' => $request->get('review_type', 'all'),
                    'period' => $request->get('period', 'all_time'),
                    'total_reviews_sum' => $products->sum('total_reviews'),
                    'overall_average_rating' => round($products->avg('average_rating'), 1),
                ]
            ];

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch most reviewed products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Helper method to get rating distribution for a product
     */
    private function getProductRatingDistribution($productId)
    {
        $distribution = DB::table('reviews')
            ->selectRaw('
                rating,
                COUNT(*) as count,
                ROUND((COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM reviews WHERE product_id = ? AND status = ?), 0)), 1) as percentage
            ', [$productId, Review::STATUS_APPROVED])
            ->where('product_id', $productId)
            ->where('status', Review::STATUS_APPROVED)
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->get();

        // Ensure all ratings 1-5 are present
        $result = [];
        for ($i = 5; $i >= 1; $i--) {
            $found = false;
            foreach ($distribution as $item) {
                if ($item->rating == $i) {
                    $result[] = $item;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result[] = (object)[
                    'rating' => $i,
                    'count' => 0,
                    'percentage' => 0.0
                ];
            }
        }

        return $result;
    }

    /**
     * Get trending products (combination of sales and reviews)
     */
    public function trending(Request $request)
    {
        try {
            $request->validate([
                'limit' => 'nullable|integer|min:1|max:50',
                'category_id' => 'nullable|exists:categories,id'
            ]);

            $limit = $request->get('limit', 10);
            
            // Get products with both sales and review data
            $products = Product::with(['category:id,name,slug'])
                ->where('is_active', true)
                ->withCount(['approvedReviews as total_reviews'])
                ->withAvg('approvedReviews as average_rating', 'rating')
                ->get();

            // Get sales data for all products
            $salesData = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.status', 'delivered')
                ->whereIn('order_items.product_id', $products->pluck('id'))
                ->select([
                    'order_items.product_id',
                    DB::raw('SUM(order_items.quantity) as total_sold')
                ])
                ->groupBy('order_items.product_id')
                ->get()
                ->keyBy('product_id');

            // Calculate trending score (weighted combination of sales and reviews)
            $products = $products->map(function($product) use ($salesData) {
                $sold = $salesData->get($product->id)?->total_sold ?? 0;
                $reviews = $product->total_reviews ?? 0;
                $rating = $product->average_rating ?? 0;
                
                // Trending score formula (adjust weights as needed)
                $product->trending_score = 
                    ($sold * 0.5) +           // 50% weight to sales
                    ($reviews * 0.3) +        // 30% weight to review count
                    ($rating * 10 * 0.2);     // 20% weight to rating (multiplied by 10 for scaling)
                
                $product->total_sold = $sold;
                
                return $product;
            });

            // Filter by category if specified
            if ($request->has('category_id')) {
                $products = $products->where('category_id', $request->category_id);
            }

            // Sort by trending score and limit
            $products = $products->sortByDesc('trending_score')
                               ->take($limit)
                               ->values();

            return response()->json([
                'success' => true,
                'data' => $products,
                'meta' => [
                    'total' => $products->count(),
                    'limit' => $limit
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trending products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Search products with advanced filters
     */
    public function search(Request $request)
    {
        try {
            $request->validate([
                'query' => 'required|string|min:2',
                'category_id' => 'nullable|exists:categories,id',
                'min_price' => 'nullable|numeric|min:0',
                'max_price' => 'nullable|numeric|min:0',
                'in_stock' => 'nullable|boolean',
                'sort_by' => 'nullable|in:relevance,price_asc,price_desc,rating,newest',
                'limit' => 'nullable|integer|min:1|max:100'
            ]);

            $query = $request->query;
            $limit = $request->get('limit', 20);
            
            $products = Product::with(['category:id,name,slug'])
                ->where('is_active', true)
                ->where(function($q) use ($query) {
                    $q->where('name', 'like', '%' . $query . '%')
                      ->orWhere('description', 'like', '%' . $query . '%')
                      ->orWhere('sku', 'like', '%' . $query . '%');
                });

            // Additional filters
            if ($request->has('category_id')) {
                $products->where('category_id', $request->category_id);
            }

            if ($request->has('min_price')) {
                $products->where('price', '>=', $request->min_price);
            }

            if ($request->has('max_price')) {
                $products->where('price', '<=', $request->max_price);
            }

            if ($request->has('in_stock') && $request->in_stock) {
                $products->where('stock_quantity', '>', 0);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'relevance');
            switch ($sortBy) {
                case 'price_asc':
                    $products->orderBy('price', 'asc');
                    break;
                case 'price_desc':
                    $products->orderBy('price', 'desc');
                    break;
                case 'rating':
                    $products->orderBy(
                        DB::raw('(SELECT AVG(rating) FROM reviews WHERE product_id = products.id AND status = "approved")'),
                        'desc'
                    );
                    break;
                case 'newest':
                    $products->orderBy('created_at', 'desc');
                    break;
                default:
                    // Relevance sorting (default) - you can implement more sophisticated relevance algorithms
                    $products->orderByRaw("
                        CASE 
                            WHEN name LIKE ? THEN 3
                            WHEN description LIKE ? THEN 2
                            ELSE 1
                        END DESC
                    ", [$query . '%', '%' . $query . '%']);
                    break;
            }

            $results = $products->paginate($limit);

            // Add average rating to each product
            $results->getCollection()->transform(function($product) {
                $product->average_rating = $product->averageRating();
                $product->total_reviews = $product->approvedReviewsCount();
                return $product;
            });

            return response()->json([
                'success' => true,
                'data' => $results,
                'search_query' => $query,
                'filters_applied' => $request->except(['query', 'page', 'per_page'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}