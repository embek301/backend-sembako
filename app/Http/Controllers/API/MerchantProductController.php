<?php

// app/Http/Controllers/Api/MerchantProductController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MerchantProductController extends Controller
{
    /**
     * Get merchant products
     */
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $query = Product::where('merchant_id', $merchant->id)
            ->with('category');

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Search
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get single product
     */
    public function show(Request $request, $id)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $product = Product::where('merchant_id', $merchant->id)
            ->with('category')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $product
        ]);
    }

    /**
     * Create product
     */
    public function store(Request $request)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        if ($merchant->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your merchant account is not active'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'unit' => 'required|string|max:50',
            'sku' => 'nullable|string|unique:products,sku',
            'min_order' => 'required|integer|min:1',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except(['images']);
        $data['merchant_id'] = $merchant->id;

        // Handle image uploads
        $imagesPaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $filename = 'product_' . time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');
                $imagesPaths[] = $path;
            }
        }
        $data['images'] = $imagesPaths;

        $product = Product::create($data);

        // Update merchant stats
        $merchant->increment('total_products');

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product
        ], 201);
    }

    /**
     * Update product
     */
    public function update(Request $request, $id)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $product = Product::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'category_id' => 'sometimes|required|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'price' => 'sometimes|required|numeric|min:0',
            'stock' => 'sometimes|required|integer|min:0',
            'unit' => 'sometimes|required|string|max:50',
            'sku' => 'nullable|string|unique:products,sku,' . $id,
            'min_order' => 'sometimes|required|integer|min:1',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except(['images']);

        // Handle new image uploads
        if ($request->hasFile('images')) {
            // Delete old images
            if ($product->images) {
                foreach ($product->images as $oldImage) {
                    if (file_exists(storage_path('app/public/' . $oldImage))) {
                        unlink(storage_path('app/public/' . $oldImage));
                    }
                }
            }

            $imagesPaths = [];
            foreach ($request->file('images') as $image) {
                $filename = 'product_' . time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');
                $imagesPaths[] = $path;
            }
            $data['images'] = $imagesPaths;
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product
        ]);
    }

    /**
     * Delete product
     */
    public function destroy(Request $request, $id)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $product = Product::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        // Delete images
        if ($product->images) {
            foreach ($product->images as $image) {
                if (file_exists(storage_path('app/public/' . $image))) {
                    unlink(storage_path('app/public/' . $image));
                }
            }
        }

        $product->delete();

        // Update merchant stats
        $merchant->decrement('total_products');

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * Toggle product active status
     */
    public function toggleActive(Request $request, $id)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $product = Product::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        $product->update(['is_active' => !$product->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Product status updated',
            'data' => $product
        ]);
    }

    /**
     * Update stock
     */
    public function updateStock(Request $request, $id)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $product = Product::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        $request->validate([
            'stock' => 'required|integer|min:0'
        ]);

        $product->update(['stock' => $request->stock]);

        return response()->json([
            'success' => true,
            'message' => 'Stock updated successfully',
            'data' => $product
        ]);
    }
}