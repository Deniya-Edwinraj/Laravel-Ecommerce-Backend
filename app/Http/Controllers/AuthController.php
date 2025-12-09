<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    // Register new user
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'country' => $request->country,
            'zip_code' => $request->zip_code,
            'date_of_birth' => $request->date_of_birth,
            'role' => 'user' // Default role
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    // Login user
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token
        ]);
    }

    // Logout user
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    // Get authenticated user (same as profile)
    public function user(Request $request)
    {
        $user = $request->user();
        $user->loadCount(['orders', 'reviews', 'wishlists', 'carts']);
        
        return response()->json([
            'user' => $user,
            'stats' => [
                'total_orders' => $user->orders_count,
                'total_reviews' => $user->reviews_count,
                'total_wishlist_items' => $user->wishlists_count,
                'total_cart_items' => $user->carts_count
            ]
        ]);
    }

    // Get user profile (detailed)
    public function getProfile(Request $request)
    {
        $user = $request->user();
        
        // Load relationships for detailed profile
        $user->load([
            'orders' => function($query) {
                $query->latest()->take(5);
            },
            'reviews' => function($query) {
                $query->with('product')->latest()->take(5);
            }
        ]);

        return response()->json([
            'user' => $user,
            'full_address' => $user->full_address,
            'recent_orders' => $user->orders,
            'recent_reviews' => $user->reviews
        ]);
    }

    // Update user profile
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'country' => 'sometimes|nullable|string|max:100',
            'zip_code' => 'sometimes|nullable|string|max:20',
            'date_of_birth' => 'sometimes|nullable|date',
            'avatar' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $avatarPath;
        }

        // Update user data
        $user->update($request->except(['avatar', 'email', 'role']));

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
            'full_address' => $user->full_address
        ]);
    }

    // Update password
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed'
        ]);

        $user = $request->user();

        // Check current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 422);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully'
        ]);
    }

    // Get user statistics
    public function getStats(Request $request)
    {
        $user = $request->user();

        $stats = [
            'total_orders' => $user->orders()->count(),
            'pending_orders' => $user->orders()->where('status', 'pending')->count(),
            'delivered_orders' => $user->orders()->where('status', 'delivered')->count(),
            'total_reviews' => $user->reviews()->count(),
            'average_rating' => $user->reviews()->avg('rating') ?: 0,
            'wishlist_items' => $user->wishlists()->count(),
            'cart_items' => $user->carts()->count(),
            'total_spent' => $user->orders()->where('payment_status', 'paid')->sum('total_amount'),
            'member_since' => $user->created_at->diffForHumans()
        ];

        return response()->json([
            'stats' => $stats,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'joined_date' => $user->created_at->format('F d, Y')
            ]
        ]);
    }

    // ==================== ADMIN METHODS ====================

    // Admin: Get all users
    public function adminGetAllUsers(Request $request)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = User::query();

        // Filter by role
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        // Filter by status (active/inactive based on last login)
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('last_login_at', '>=', now()->subDays(30));
            } elseif ($request->status === 'inactive') {
                $query->where(function($q) {
                    $q->whereNull('last_login_at')
                      ->orWhere('last_login_at', '<', now()->subDays(30));
                });
            }
        }

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        // Filter by country/city
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        if ($request->has('city')) {
            $query->where('city', $request->city);
        }

        // Order by
        $orderBy = $request->get('order_by', 'created_at');
        $orderDirection = $request->get('order_direction', 'desc');
        $query->orderBy($orderBy, $orderDirection);

        // Select specific columns to avoid exposing sensitive data
        $query->select([
            'id',
            'name',
            'email',
            'role',
            'phone',
            'city',
            'state',
            'country',
            'avatar',
            'created_at',
            'updated_at',
            'last_login_at'
        ]);

        // Load counts
        $query->withCount(['orders', 'reviews', 'wishlists']);

        // Pagination
        $perPage = $request->get('per_page', 20);
        $users = $query->paginate($perPage);

        // Statistics
        $stats = [
            'total_users' => User::count(),
            'admin_users' => User::where('role', 'admin')->count(),
            'regular_users' => User::where('role', 'user')->count(),
            'new_users_today' => User::whereDate('created_at', today())->count(),
            'new_users_this_month' => User::whereMonth('created_at', now()->month)->count(),
            'active_users' => User::where('last_login_at', '>=', now()->subDays(30))->count(),
        ];

        // Countries distribution
        $countriesDistribution = User::select('country', DB::raw('COUNT(*) as count'))
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'users' => $users,
            'stats' => $stats,
            'countries_distribution' => $countriesDistribution,
            'filters' => $request->only(['role', 'status', 'search', 'country', 'city'])
        ]);
    }

    // Admin: Get specific user details
    public function adminGetUser(Request $request, $id)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::withCount([
            'orders',
            'reviews',
            'wishlists',
            'carts'
        ])->findOrFail($id);

        // Load recent activities
        $user->load([
            'orders' => function($query) {
                $query->select(['id', 'order_number', 'total_amount', 'status', 'created_at'])
                      ->latest()
                      ->take(5);
            },
            'reviews' => function($query) {
                $query->with(['product:id,name'])
                      ->select(['id', 'product_id', 'rating', 'status', 'created_at'])
                      ->latest()
                      ->take(5);
            },
            'wishlists' => function($query) {
                $query->with(['product:id,name,price'])
                      ->select(['id', 'product_id', 'created_at'])
                      ->latest()
                      ->take(5);
            }
        ]);

        // Get user statistics
        $orderStats = [
            'total_spent' => $user->orders()->sum('total_amount'),
            'average_order_value' => $user->orders()->avg('total_amount') ?: 0,
            'pending_orders' => $user->orders()->where('status', 'pending')->count(),
            'delivered_orders' => $user->orders()->where('status', 'delivered')->count(),
            'cancelled_orders' => $user->orders()->where('status', 'cancelled')->count(),
        ];

        $reviewStats = [
            'approved_reviews' => $user->reviews()->where('status', 'approved')->count(),
            'pending_reviews' => $user->reviews()->where('status', 'pending')->count(),
            'rejected_reviews' => $user->reviews()->where('status', 'rejected')->count(),
            'average_rating' => $user->reviews()->where('status', 'approved')->avg('rating') ?: 0,
        ];

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

        // Top purchased products
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->selectRaw('
                products.id,
                products.name,
                COUNT(*) as times_purchased,
                SUM(order_items.quantity) as total_quantity,
                SUM(order_items.quantity * order_items.price) as total_spent
            ')
            ->where('orders.user_id', $user->id)
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_quantity', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'user' => $user,
            'full_address' => $user->full_address,
            'order_stats' => $orderStats,
            'review_stats' => $reviewStats,
            'monthly_spending' => $monthlySpending,
            'top_products' => $topProducts,
            'recent_activities' => [
                'recent_orders' => $user->orders,
                'recent_reviews' => $user->reviews,
                'recent_wishlist_items' => $user->wishlists
            ]
        ]);
    }

    // Admin: Create user (for creating admin users or other users)
    public function adminCreateUser(Request $request)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,user',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'phone' => $request->phone,
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'country' => $request->country,
            'zip_code' => $request->zip_code,
            'date_of_birth' => $request->date_of_birth
        ]);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user
        ], 201);
    }

    // Admin: Update user
    public function adminUpdateUser(Request $request, $id)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
            'role' => 'sometimes|required|in:admin,user',
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'country' => 'sometimes|nullable|string|max:100',
            'zip_code' => 'sometimes|nullable|string|max:20',
            'date_of_birth' => 'sometimes|nullable|date',
            'avatar' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $avatarPath;
        }

        // Update user data (excluding password)
        $user->update($request->except(['avatar', 'password']));

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user
        ]);
    }

    // Admin: Update user password
    public function adminUpdateUserPassword(Request $request, $id)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);

        $request->validate([
            'new_password' => 'required|string|min:8|confirmed'
        ]);

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'User password updated successfully'
        ]);
    }

    // Admin: Delete user
    public function adminDeleteUser(Request $request, $id)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($id);

        // Prevent admin from deleting themselves
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account'
            ], 400);
        }

        // Delete user's avatar if exists
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }

    // Admin: Get user statistics overview
    public function adminGetUserStats(Request $request)
    {
        // Check if user is admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // User growth over time
        $userGrowth = DB::table('users')
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as new_users,
                SUM(COUNT(*)) OVER (ORDER BY DATE_FORMAT(created_at, "%Y-%m")) as total_users
            ')
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Role distribution
        $roleDistribution = DB::table('users')
            ->select('role', DB::raw('COUNT(*) as count'))
            ->groupBy('role')
            ->get();

        // Top countries
        $topCountries = DB::table('users')
            ->select('country', DB::raw('COUNT(*) as count'))
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        // Active vs inactive users (based on last login)
        $activityStats = [
            'active_users' => User::where('last_login_at', '>=', now()->subDays(30))->count(),
            'inactive_users' => User::where(function($q) {
                $q->whereNull('last_login_at')
                  ->orWhere('last_login_at', '<', now()->subDays(30));
            })->count(),
        ];

        // User acquisition sources (you can add source field to users table)
        $todayStats = [
            'new_users_today' => User::whereDate('created_at', today())->count(),
            'new_users_yesterday' => User::whereDate('created_at', today()->subDay())->count(),
            'growth_rate' => 0 // Calculate if needed
        ];

        return response()->json([
            'user_growth' => $userGrowth,
            'role_distribution' => $roleDistribution,
            'top_countries' => $topCountries,
            'activity_stats' => $activityStats,
            'today_stats' => $todayStats,
            'total_users' => User::count(),
            'date_range' => [
                'from' => now()->subMonths(12)->format('Y-m-d'),
                'to' => now()->format('Y-m-d')
            ]
        ]);
    }
}