<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use Illuminate\Http\Request;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Categories (public)
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);

// Products (public)
Route::get('/products', [ProductController::class, 'index']);

// Most sold and most reviewed products (public)
Route::get('/products/most-sold', [ProductController::class, 'mostSold']);
Route::get('/products/most-reviewed', [ProductController::class, 'mostReviewed']);

// These parameterized routes MUST COME AFTER specific routes
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products/{id}/reviews', [ReviewController::class, 'productReviews']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Profile routes
    Route::get('/profile', [AuthController::class, 'getProfile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'updatePassword']);
    Route::get('/profile/stats', [AuthController::class, 'getStats']);
    
    // Categories (admin only)
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    
    // Products (admin only)
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    
    // Reviews - User routes
    Route::get('/reviews/my-reviews', [ReviewController::class, 'myReviews']);
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{id}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
    
    // Reviews - Admin routes
    Route::get('/admin/reviews', [ReviewController::class, 'adminIndex']);
    Route::post('/admin/reviews/{id}/approve', [ReviewController::class, 'approve']);
    Route::post('/admin/reviews/{id}/reject', [ReviewController::class, 'reject']);
    Route::post('/admin/reviews/bulk-approve', [ReviewController::class, 'bulkApprove']);
    Route::post('/admin/reviews/bulk-reject', [ReviewController::class, 'bulkReject']);
    
    // Wishlist
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{id}', [WishlistController::class, 'destroy']);
    
    // Cart
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::put('/cart/{id}', [CartController::class, 'update']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    Route::delete('/cart', [CartController::class, 'clear']);
    
    // Orders - User routes
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/history', [OrderController::class, 'history']);
    Route::get('/orders/recent', [OrderController::class, 'recentOrders']);
    Route::get('/orders/statistics', [OrderController::class, 'statistics']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    
    // Orders - Admin routes
    Route::get('/admin/orders', [OrderController::class, 'adminIndex']);
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::put('/orders/{id}/payment-status', [OrderController::class, 'updatePaymentStatus']);

    // Admin users - STATIC ROUTES FIRST
    Route::get('/admin/users', [AuthController::class, 'adminGetAllUsers']);
    Route::get('/admin/users/stats', [AuthController::class, 'adminGetUserStats']);
    Route::post('/admin/users', [AuthController::class, 'adminCreateUser']);
    
    // Admin users - PARAMETERIZED ROUTES LAST
    Route::get('/admin/users/{id}', [AuthController::class, 'adminGetUser']);
    Route::put('/admin/users/{id}', [AuthController::class, 'adminUpdateUser']);
    Route::put('/admin/users/{id}/password', [AuthController::class, 'adminUpdateUserPassword']);
    Route::delete('/admin/users/{id}', [AuthController::class, 'adminDeleteUser']);
    
});