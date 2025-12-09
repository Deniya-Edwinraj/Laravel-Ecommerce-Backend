<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'rating',
        'comment',
        'status',
        'admin_notes'
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED
    ];

    // Relationships - MAKE SURE THIS EXISTS!
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Scope for approved reviews
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    // Scope for pending reviews
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    // Scope for user's reviews
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Check if review is approved
    public function isApproved()
    {
        return $this->status === self::STATUS_APPROVED;
    }

    // Check if review is pending
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Get status badge color
    public function getStatusColorAttribute()
    {
        $colors = [
            self::STATUS_PENDING => 'warning',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger'
        ];

        return $colors[$this->status] ?? 'secondary';
    }

    // Cast attributes
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Eager load by default
    protected $with = ['user', 'product'];
}