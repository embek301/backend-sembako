<?php
// app/Http/Controllers/Api/ProductController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Get all products with filtering
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'reviews.user'])
            ->active();

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Filter by price range
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        
        if ($sortBy === 'rating') {
            // Sort by average rating (requires subquery)
            $query->withAvg('reviews', 'rating')
                ->orderBy('reviews_avg_rating', $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get product detail
     */
    public function show($id)
    {
        $product = Product::with([
            'category',
            'reviews' => function($query) {
                $query->latest()->with('user')->limit(10);
            }
        ])->findOrFail($id);

        // Check if user authenticated and product in wishlist
        $inWishlist = false;
        if (auth()->check()) {
            $inWishlist = auth()->user()->wishlist()
                ->where('product_id', $id)
                ->exists();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product,
                'in_wishlist' => $inWishlist
            ]
        ]);
    }

    /**
     * Get featured products
     */
    public function featured()
    {
        $products = Product::with('category')
            ->active()
            ->inStock()
            ->withAvg('reviews', 'rating')
            ->orderBy('reviews_avg_rating', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get related products
     */
    public function related($id)
    {
        $product = Product::findOrFail($id);

        $relatedProducts = Product::with('category')
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $id)
            ->active()
            ->inStock()
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $relatedProducts
        ]);
    }
}