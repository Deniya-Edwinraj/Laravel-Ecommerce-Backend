<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'address',
        'city',
        'state',
        'country',
        'zip_code',
        'avatar',
        'date_of_birth'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'date_of_birth' => 'date',
    ];

    // Relationships
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Helper methods
    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    // Get full address
    public function getFullAddressAttribute()
    {
        $addressParts = [];
        if ($this->address) $addressParts[] = $this->address;
        if ($this->city) $addressParts[] = $this->city;
        if ($this->state) $addressParts[] = $this->state;
        if ($this->country) $addressParts[] = $this->country;
        if ($this->zip_code) $addressParts[] = $this->zip_code;
        
        return implode(', ', $addressParts);
    }
}