<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewController extends Controller
{
    /**
     * Check if user is admin
     */
    private function isAdmin($user)
    {
        return $user && $user->role === 'admin';
    }

    /**
     * Get current user with admin check
     */
    private function getCurrentUser(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            throw new \Exception('Unauthenticated');
        }
        
        return $user;
    }

    // Store new review
    public function store(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'rating' => 'required|integer|between:1,5',
                'comment' => 'nullable|string|max:1000'
            ]);

            $userId = $request->user()->id;
            $productId = $request->product_id;
            
            Log::info('Attempting to create review', [
                'user_id' => $userId,
                'product_id' => $productId,
                'rating' => $request->rating
            ]);

            // Check if user already reviewed this product
            $existingReview = Review::where('user_id', $userId)
                                    ->where('product_id', $productId)
                                    ->first();

            if ($existingReview) {
                Log::warning('Duplicate review attempt', [
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'existing_review_id' => $existingReview->id
                ]);
                
                // Update existing review instead of preventing
                $existingReview->update([
                    'rating' => $request->rating,
                    'comment' => $request->comment,
                    'status' => Review::STATUS_PENDING,
                    'admin_notes' => null
                ]);
                
                $review = $existingReview;
                $message = 'Review updated successfully. It will be visible after re-approval.';
                $statusCode = 200;
            } else {
                // Create new review
                $review = Review::create([
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'rating' => $request->rating,
                    'comment' => $request->comment,
                    'status' => Review::STATUS_PENDING
                ]);
                
                Log::info('Review created successfully', ['review_id' => $review->id]);
                $message = 'Review submitted successfully. It will be visible after approval.';
                $statusCode = 201;
            }

            // Load relationships
            $review->load(['user:id,name,email', 'product:id,name,price,images']);
            
            return response()->json([
                'message' => $message,
                'review' => $review
            ], $statusCode);
            
        } catch (\Exception $e) {
            Log::error('Error in store review: ' . $e->getMessage());
            return response()->json([
                'error' => 'Internal server error',
                'message' => config('app.debug') ? $e->getMessage() : 'Please try again later'
            ], 500);
        }
    }

    // Get user's own reviews
    public function myReviews(Request $request)
    {
        try {
            $user = $this->getCurrentUser($request);
            
            $query = Review::where('user_id', $user->id)
                            ->orderBy('created_at', 'desc');

            // Load relationships
            $query->with([
                'user:id,name,email',
                'product:id,name,price,images'
            ]);

            // Filter by status
            if ($request->has('status')) {
                $validStatuses = ['pending', 'approved', 'rejected'];
                if (in_array($request->status, $validStatuses)) {
                    $query->where('status', $request->status);
                }
            }

            // Filter by product
            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            // Filter by rating
            if ($request->has('rating')) {
                $query->where('rating', $request->rating);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
            }

            $perPage = $request->get('per_page', 15);
            $reviews = $query->paginate($perPage);

            // Statistics
            $stats = [
                'total_reviews' => Review::where('user_id', $user->id)->count(),
                'approved_reviews' => Review::where('user_id', $user->id)
                                           ->where('status', Review::STATUS_APPROVED)
                                           ->count(),
                'pending_reviews' => Review::where('user_id', $user->id)
                                          ->where('status', Review::STATUS_PENDING)
                                          ->count(),
                'rejected_reviews' => Review::where('user_id', $user->id)
                                           ->where('status', Review::STATUS_REJECTED)
                                           ->count(),
                'average_rating' => Review::where('user_id', $user->id)
                                         ->where('status', Review::STATUS_APPROVED)
                                         ->avg('rating') ?: 0
            ];

            return response()->json([
                'reviews' => $reviews,
                'stats' => $stats,
                'filters' => $request->only(['status', 'product_id', 'rating'])
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in myReviews method: ' . $e->getMessage());
            return response()->json([
                'error' => 'Internal server error',
                'message' => config('app.debug') ? $e->getMessage() : 'Please try again later'
            ], 500);
        }
    }

    // Update review
    public function update(Request $request, $id)
    {
        try {
            $review = Review::findOrFail($id);

            // Check if review belongs to user
            if ($review->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // If review is already approved, can't modify
            if ($review->status === Review::STATUS_APPROVED) {
                return response()->json([
                    'message' => 'Approved reviews cannot be modified'
                ], 400);
            }

            $request->validate([
                'rating' => 'required|integer|between:1,5',
                'comment' => 'nullable|string|max:1000'
            ]);

            // Reset status to pending if updating
            $review->update([
                'rating' => $request->rating,
                'comment' => $request->comment,
                'status' => Review::STATUS_PENDING,
                'admin_notes' => null
            ]);

            return response()->json([
                'message' => 'Review updated successfully. It will be visible after re-approval.',
                'review' => $review->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating review: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error updating review',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Delete review
    public function destroy(Request $request, $id)
    {
        try {
            $user = $this->getCurrentUser($request);
            $review = Review::findOrFail($id);

            // Check if review belongs to user or user is admin
            if ($review->user_id !== $user->id && !$this->isAdmin($user)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $review->delete();

            return response()->json([
                'message' => 'Review deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting review: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error deleting review',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Admin: Get all reviews - CORRECTED
    public function adminIndex(Request $request)
    {
        try {
            $user = $this->getCurrentUser($request);
            
            // Check if user is admin
            if (!$this->isAdmin($user)) {
                return response()->json([
                    'message' => 'Unauthorized. Admin access required.',
                    'user_role' => $user->role
                ], 403);
            }

            $query = Review::orderBy('created_at', 'desc')
                          ->with(['user:id,name,email', 'product:id,name']);

            // Filter by status
            if ($request->has('status')) {
                $validStatuses = ['pending', 'approved', 'rejected'];
                if (in_array($request->status, $validStatuses)) {
                    $query->where('status', $request->status);
                }
            }

            // Filter by user
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            // Filter by product
            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            // Filter by rating
            if ($request->has('rating')) {
                $query->where('rating', $request->rating);
            }

            // Search by comment
            if ($request->has('search')) {
                $query->where('comment', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            $reviews = $query->paginate($perPage);

            // Statistics
            $stats = [
                'total_reviews' => Review::count(),
                'pending_reviews' => Review::where('status', Review::STATUS_PENDING)->count(),
                'approved_reviews' => Review::where('status', Review::STATUS_APPROVED)->count(),
                'rejected_reviews' => Review::where('status', Review::STATUS_REJECTED)->count(),
                'average_rating' => Review::where('status', Review::STATUS_APPROVED)->avg('rating') ?: 0
            ];

            return response()->json([
                'success' => true,
                'message' => 'Reviews retrieved successfully',
                'reviews' => $reviews,
                'stats' => $stats,
                'filters' => $request->only(['status', 'user_id', 'product_id', 'rating', 'search'])
            ]);
        } catch (\Exception $e) {
            Log::error('Error in adminIndex: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => config('app.debug') ? $e->getMessage() : 'Please try again later'
            ], 500);
        }
    }

    // Admin: Approve review
    public function approve(Request $request, $id)
    {
        try {
            $user = $this->getCurrentUser($request);
            
            // Check if user is admin
            if (!$this->isAdmin($user)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $review = Review::findOrFail($id);

            // Check if already approved
            if ($review->status === Review::STATUS_APPROVED) {
                return response()->json(['message' => 'Review is already approved'], 400);
            }

            $request->validate([
                'admin_notes' => 'nullable|string|max:500'
            ]);

            $review->update([
                'status' => Review::STATUS_APPROVED,
                'admin_notes' => $request->admin_notes
            ]);

            return response()->json([
                'message' => 'Review approved successfully',
                'review' => $review->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving review: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error approving review',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Admin: Reject review
    public function reject(Request $request, $id)
    {
        try {
            $user = $this->getCurrentUser($request);
            
            if (!$this->isAdmin($user)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $review = Review::findOrFail($id);

            // Check if already rejected
            if ($review->status === Review::STATUS_REJECTED) {
                return response()->json(['message' => 'Review is already rejected'], 400);
            }

            $request->validate([
                'admin_notes' => 'required|string|max:500'
            ]);

            $review->update([
                'status' => Review::STATUS_REJECTED,
                'admin_notes' => $request->admin_notes
            ]);

            return response()->json([
                'message' => 'Review rejected successfully',
                'review' => $review->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error('Error rejecting review: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error rejecting review',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Admin: Bulk approve reviews
    public function bulkApprove(Request $request)
    {
        try {
            $user = $this->getCurrentUser($request);
            
            if (!$this->isAdmin($user)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $request->validate([
                'review_ids' => 'required|array',
                'review_ids.*' => 'exists:reviews,id',
                'admin_notes' => 'nullable|string|max:500'
            ]);

            $approvedCount = 0;
            $failedReviews = [];

            foreach ($request->review_ids as $reviewId) {
                try {
                    $review = Review::find($reviewId);
                    
                    if ($review && $review->status !== Review::STATUS_APPROVED) {
                        $review->update([
                            'status' => Review::STATUS_APPROVED,
                            'admin_notes' => $request->admin_notes
                        ]);
                        $approvedCount++;
                    }
                } catch (\Exception $e) {
                    $failedReviews[] = [
                        'id' => $reviewId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'message' => "{$approvedCount} reviews approved successfully",
                'approved_count' => $approvedCount,
                'failed_reviews' => $failedReviews
            ]);
        } catch (\Exception $e) {
            Log::error('Error in bulkApprove: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error bulk approving reviews',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Admin: Bulk reject reviews
    public function bulkReject(Request $request)
    {
        try {
            $user = $this->getCurrentUser($request);
            
            if (!$this->isAdmin($user)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $request->validate([
                'review_ids' => 'required|array',
                'review_ids.*' => 'exists:reviews,id',
                'admin_notes' => 'required|string|max:500'
            ]);

            $rejectedCount = 0;
            $failedReviews = [];

            foreach ($request->review_ids as $reviewId) {
                try {
                    $review = Review::find($reviewId);
                    
                    if ($review && $review->status !== Review::STATUS_REJECTED) {
                        $review->update([
                            'status' => Review::STATUS_REJECTED,
                            'admin_notes' => $request->admin_notes
                        ]);
                        $rejectedCount++;
                    }
                } catch (\Exception $e) {
                    $failedReviews[] = [
                        'id' => $reviewId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'message' => "{$rejectedCount} reviews rejected successfully",
                'rejected_count' => $rejectedCount,
                'failed_reviews' => $failedReviews
            ]);
        } catch (\Exception $e) {
            Log::error('Error in bulkReject: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error bulk rejecting reviews',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Get product reviews (only approved for public, all for admin)
    public function productReviews(Request $request, $productId)
    {
        try {
            $product = Product::findOrFail($productId);
            
            $query = Review::where('product_id', $productId);

            // For non-admin users, only show approved reviews
            $user = $request->user();
            if ($user && !$this->isAdmin($user)) {
                $query->where('status', Review::STATUS_APPROVED);
            }

            // For admin, allow filtering
            if ($user && $this->isAdmin($user)) {
                if ($request->has('status')) {
                    $validStatuses = ['pending', 'approved', 'rejected'];
                    if (in_array($request->status, $validStatuses)) {
                        $query->where('status', $request->status);
                    }
                }
            }

            // Load user relationship
            $query->with(['user:id,name'])
                  ->orderBy('created_at', 'desc');

            // Filter by rating
            if ($request->has('rating')) {
                $query->where('rating', $request->rating);
            }

            // Sort options
            if ($request->has('sort_by')) {
                $sortBy = $request->sort_by;
                $sortOrder = $request->get('sort_order', 'desc');
                
                if (in_array($sortBy, ['rating', 'created_at'])) {
                    $query->orderBy($sortBy, $sortOrder);
                }
            }

            $perPage = $request->get('per_page', 10);
            $reviews = $query->paginate($perPage);

            // Rating distribution
            $ratingDistribution = DB::table('reviews')
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

            return response()->json([
                'reviews' => $reviews,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'average_rating' => $product->averageRating(),
                    'total_reviews' => $product->approvedReviewsCount()
                ],
                'rating_distribution' => $ratingDistribution
            ]);
        } catch (\Exception $e) {
            Log::error('Error in productReviews: ' . $e->getMessage());
            return response()->json([
                'error' => 'Internal server error',
                'message' => config('app.debug') ? $e->getMessage() : 'Please try again later'
            ], 500);
        }
    }

    // Debug endpoint to check user role
    public function checkUserRole(Request $request)
    {
        try {
            $user = $this->getCurrentUser($request);
            
            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_admin' => $this->isAdmin($user)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}