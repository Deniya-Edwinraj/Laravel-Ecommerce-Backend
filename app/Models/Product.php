<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'stock_quantity',
        'sku',
        'images',
        'is_active',
        'category_id'
    ];

    protected $casts = [
        'images' => 'array',
        'is_active' => 'boolean'
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    // Get only approved reviews
    public function approvedReviews()
    {
        return $this->hasMany(Review::class)->where('status', Review::STATUS_APPROVED);
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Helper methods - Only consider approved reviews for average rating
    public function averageRating()
    {
        return $this->approvedReviews()->avg('rating') ?: 0;
    }

    // Get approved reviews count
    public function approvedReviewsCount()
    {
        return $this->approvedReviews()->count();
    }

    // Get pending reviews count
    public function pendingReviewsCount()
    {
        return $this->reviews()->where('status', Review::STATUS_PENDING)->count();
    }

    // Get rating distribution
    public function ratingDistribution()
    {
        return $this->approvedReviews()
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->get();
    }
}