<?php

// routes/api.php - FIXED
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\MerchantController;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Sembako Koperasi API',
        'version' => '1.0.0'
    ]);
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Categories
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);

// Products
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products/{id}/related', [ProductController::class, 'related']);

// Payment webhook (public)
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // Address Management
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::get('/addresses/{id}', [AddressController::class, 'show']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);
    Route::post('/addresses/{id}/set-default', [AddressController::class, 'setDefault']);
    
    // Cart
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::put('/cart/{id}', [CartController::class, 'update']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    Route::delete('/cart', [CartController::class, 'clear']);
    Route::get('/cart/count', [CartController::class, 'count']);
    
    // Wishlist
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{productId}', [WishlistController::class, 'destroy']);
    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle']);
    
    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    
    // Payment
    Route::post('/payments/create', [PaymentController::class, 'createPayment']);
    Route::get('/payments/{orderId}/status', [PaymentController::class, 'checkStatus']);
    
    // Tracking
    Route::get('/orders/{orderId}/tracking', [TrackingController::class, 'show']);
    Route::get('/orders/{orderId}/location', [TrackingController::class, 'location']);
    
    // Reviews
    Route::get('/reviews/product/{productId}', [ReviewController::class, 'productReviews']);
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{id}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
    
    // Vouchers
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::post('/vouchers/validate', [VoucherController::class, 'validate']);
    
    // Driver routes
    Route::middleware('role:driver')->group(function () {
        Route::get('/driver/orders', [TrackingController::class, 'driverOrders']);
        Route::post('/tracking/{orderId}/update-location', [TrackingController::class, 'updateLocation']);
    });
    
    // ✅ MERCHANT ROUTES - FIXED
    Route::prefix('merchant')->group(function () {
        // Public - Registration (outside auth middleware)
        Route::post('/register', [MerchantController::class, 'register'])
            ->withoutMiddleware('auth:sanctum');
        
        // Protected routes
        Route::get('/profile', [MerchantController::class, 'profile']);
        Route::post('/profile', [MerchantController::class, 'updateProfile']);
        
        // ✅ Products - Fixed method names
        Route::get('/products', [MerchantController::class, 'products']); // ← Changed
        Route::post('/products', [MerchantController::class, 'storeProduct']); // ← Changed
        Route::put('/products/{id}', [MerchantController::class, 'updateProduct']);
        Route::delete('/products/{id}', [MerchantController::class, 'deleteProduct']);
        
        // ✅ Financial - Fixed method names
        Route::get('/balance', [MerchantController::class, 'balance']); // ← Changed
        Route::get('/payments', [MerchantController::class, 'payments']); // ← Changed
        Route::get('/withdrawals', [MerchantController::class, 'withdrawals']); // ← Changed
        Route::post('/withdrawals', [MerchantController::class, 'requestWithdrawal']);
    });
    
    // Admin routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::post('/tracking/{orderId}/assign-driver', [TrackingController::class, 'assignDriver']);
        
        // Merchant management
        Route::get('/merchants', [MerchantController::class, 'index']);
        Route::post('/merchants/{id}/verify', [MerchantController::class, 'verify']);
        Route::get('/withdrawals', [MerchantController::class, 'allWithdrawals']);
        Route::post('/withdrawals/{id}/process', [MerchantController::class, 'processWithdrawal']);
    });
});