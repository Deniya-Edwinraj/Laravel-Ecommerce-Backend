# Simple Laravel E-Commerce API (Laravel 12 + Sanctum)

<p align="center">
    <a href="https://laravel.com" target="_blank">
        <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="350" alt="Laravel Logo">
    </a>
</p>

<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel">
    <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php">
    <img src="https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql">
    <img src="https://img.shields.io/badge/Auth-Laravel%20Sanctum-4A5568?style=for-the-badge">
    <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge">
</p>

A simple E-Commerce REST API built with Laravel 12 and Laravel Sanctum.  
Includes authentication, admin/user roles, categories, products, reviews, wishlist, cart, orders, and order history.

---

# Features

## Authentication
- Register
- Login
- Logout
- Sanctum token-based authentication
- Get authenticated user details

## User Roles
- `admin` – manages categories & products  
- `user` – shopping features (wishlist, cart, orders)

## E-Commerce Modules
- Categories (CRUD for admin)
- Products (CRUD for admin)
- Reviews (create, update, delete)
- Wishlist (add/list/remove)
- Cart (add/update/remove/clear)
- Orders (place, view, cancel)
- Order history

---

# Tech Stack
- Laravel 12
- PHP 8.2+
- MySQL 8+
- Laravel Sanctum

---

# Installation

## 1. Clone & Install
```bash
git clone <your-repo-url>
cd <your-project-folder>
composer install
cp .env.example .env
php artisan key:generate
```

## 2. Configure .env
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ecommerce_api
DB_USERNAME=root
DB_PASSWORD=
```

## 3. Migrate
```bash
php artisan migrate
```

## 4. Run Server
```bash
php artisan serve
```

---

# Authentication Flow

1. Register → `/api/register`
2. Login → `/api/login` (returns Sanctum token)
3. Pass token in headers:
```
Authorization: Bearer YOUR_TOKEN
```
4. Logout → `/api/logout`

---

# API Routes

These routes match exactly your `routes/api.php`.

---

## Public Auth Routes
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/register | Register |
| POST | /api/login | Login |

---

## Public Catalog Routes

### Categories
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/categories | List categories |
| GET | /api/categories/{id} | Show category |

### Products
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/products | List products |
| GET | /api/products/{id} | Show product |

---

## Protected Routes (Require Token)

### Authenticated User
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/logout | Logout |
| GET | /api/user | Get authenticated user |

---

## Category Management (Admin Intended)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/categories | Create category |
| PUT | /api/categories/{id} | Update category |
| DELETE | /api/categories/{id} | Delete category |

---

## Product Management (Admin Intended)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/products | Create product |
| PUT | /api/products/{id} | Update product |
| DELETE | /api/products/{id} | Delete product |

---

## Reviews
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/reviews | Create review |
| PUT | /api/reviews/{id} | Update review |
| DELETE | /api/reviews/{id} | Delete review |

---

## Wishlist
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/wishlist | Get wishlist |
| POST | /api/wishlist | Add to wishlist |
| DELETE | /api/wishlist/{id} | Remove from wishlist |

---

## Cart
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/cart | View cart |
| POST | /api/cart | Add to cart |
| PUT | /api/cart/{id} | Update cart item |
| DELETE | /api/cart/{id} | Remove cart item |
| DELETE | /api/cart | Clear cart |

---

## Orders
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/orders | Order history |
| POST | /api/orders | Place order |
| GET | /api/orders/{id} | View order |
| POST | /api/orders/{id}/cancel | Cancel order |

---

# Example API Requests

## Register
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"user@example.com","password":"Password@123","password_confirmation":"Password@123"}'
```

## Login
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"Password@123"}'
```

## Add to Cart
```bash
curl -X POST http://localhost:8000/api/cart \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"product_id":1,"quantity":2}'
```

## Place Order
```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"shipping_address":"123 Street","payment_method":"cod"}'
```

---

# Database Overview

Tables commonly used:
- users
- categories
- products
- reviews
- wishlists
- carts or cart_items
- orders
- order_items

---

# License

This project is open-sourced under the MIT License.
