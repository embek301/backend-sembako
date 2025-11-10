<?php

// app/Http/Controllers/Api/MerchantController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\MerchantPayment;
use App\Models\MerchantWithdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
class MerchantController extends Controller
{
    /**
     * Register as merchant
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'store_name' => 'required|string|max:255',
            'store_description' => 'nullable|string',
            'bank_name' => 'required|string',
            'bank_account_number' => 'required|string',
            'bank_account_name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'merchant',
            'store_name' => $request->store_name,
            'store_description' => $request->store_description,
            'bank_name' => $request->bank_name,
            'bank_account_number' => $request->bank_account_number,
            'bank_account_name' => $request->bank_account_name,
            'is_verified' => false,
            'status' => 'inactive' // Wait for admin verification
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Merchant registration successful. Please wait for admin verification.',
            'data' => $user
        ], 201);
    }

    /**
     * Get merchant profile
     */
    public function profile(Request $request)
    {
        try {
            $merchant = $request->user();

            if (!$merchant || !$merchant->isMerchant()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Calculate stats safely
            $stats = [
                'total_products' => $merchant->products()->count(),
                'active_products' => $merchant->products()->where('is_active', 1)->count(), // ← Ubah true jadi 1
                'total_sales' => $merchant->merchantPayments()
                    ->where('status', 'paid')
                    ->sum('order_amount') ?? 0,
                'total_earnings' => $merchant->merchantPayments()
                    ->where('status', 'paid')
                    ->sum('merchant_amount') ?? 0,
                'pending_balance' => $merchant->merchantPayments()
                    ->where('status', 'pending')
                    ->sum('merchant_amount') ?? 0,
            ];

            // Calculate available balance
            $totalEarnings = $stats['total_earnings'];
            $withdrawnAmount = $merchant->withdrawals()
                ->where('status', 'completed')
                ->sum('amount') ?? 0;
            $pendingWithdrawal = $merchant->withdrawals()
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount') ?? 0;

            $stats['available_balance'] = $totalEarnings - $withdrawnAmount - $pendingWithdrawal;

            // ✅ PENTING: Jangan load products dengan appended attributes
            // Load merchant without causing issues
            $merchantData = [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'email' => $merchant->email,
                'phone' => $merchant->phone,
                'store_name' => $merchant->store_name,
                'store_description' => $merchant->store_description,
                'store_logo' => $merchant->store_logo,
                'is_verified' => $merchant->is_verified,
                'verified_at' => $merchant->verified_at,
                'commission_rate' => $merchant->commission_rate,
                'status' => $merchant->status,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'merchant' => $merchantData,
                    'stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Merchant profile error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update merchant profile
     */
    public function updateProfile(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20|unique:users,phone,' . $merchant->id,
            'store_name' => 'sometimes|required|string|max:255',
            'store_description' => 'nullable|string',
            'store_logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'bank_name' => 'sometimes|required|string',
            'bank_account_number' => 'sometimes|required|string',
            'bank_account_name' => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name',
            'phone',
            'store_name',
            'store_description',
            'bank_name',
            'bank_account_number',
            'bank_account_name'
        ]);

        // Handle logo upload
        if ($request->hasFile('store_logo')) {
            if ($merchant->store_logo && file_exists(storage_path('app/public/' . $merchant->store_logo))) {
                unlink(storage_path('app/public/' . $merchant->store_logo));
            }

            $logo = $request->file('store_logo');
            $filename = 'store_' . $merchant->id . '_' . time() . '.' . $logo->getClientOriginalExtension();
            $path = $logo->storeAs('stores', $filename, 'public');
            $data['store_logo'] = $path;
        }

        $merchant->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $merchant
        ]);
    }

    /**
     * Get merchant products
     */
    public function products(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $query = Product::where('merchant_id', $merchant->id)
            ->with('category');

        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        }

        $products = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Create product
     */
    public function storeProduct(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // ✅ Ubah validasi - SKU jadi nullable
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'unit' => 'required|string',
            'sku' => 'nullable|string|unique:products,sku', // ← Changed to nullable
            'min_order' => 'required|integer|min:1',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $data['merchant_id'] = $merchant->id;
        $data['is_active'] = $request->has('is_active') ? (bool) $request->is_active : true;

        // ✅ Auto-generate SKU jika tidak ada
        if (empty($data['sku'])) {
            // Generate SKU: MER{merchant_id}-{timestamp}
            $data['sku'] = 'MER' . $merchant->id . '-' . strtoupper(substr(uniqid(), -6));

            // Pastikan unique
            while (Product::where('sku', $data['sku'])->exists()) {
                $data['sku'] = 'MER' . $merchant->id . '-' . strtoupper(substr(uniqid(), -6));
            }
        }

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

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product
        ], 201);
    }

    /**
     * Update product
     */
    public function updateProduct(Request $request, $id)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $product = Product::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'category_id' => 'sometimes|required|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'stock' => 'sometimes|required|integer|min:0',
            'unit' => 'sometimes|required|string',
            'sku' => 'sometimes|required|string|unique:products,sku,' . $id,
            'min_order' => 'sometimes|required|integer|min:1',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product
        ]);
    }

    /**
     * Delete product
     */
    public function deleteProduct(Request $request, $id)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $product = Product::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * Get merchant payments
     */
    public function payments(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $query = MerchantPayment::where('merchant_id', $merchant->id)
            ->with('order');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $payments = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Request withdrawal
     */
    public function requestWithdrawal(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:50000', // Minimum 50k
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check available balance
        $availableBalance = MerchantPayment::where('merchant_id', $merchant->id)
            ->where('status', 'paid')
            ->sum('merchant_amount');

        $withdrawnAmount = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->sum('amount');

        $currentBalance = $availableBalance - $withdrawnAmount;

        if ($request->amount > $currentBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Available: Rp ' . number_format($currentBalance, 0, ',', '.')
            ], 400);
        }

        $withdrawal = MerchantWithdrawal::create([
            'merchant_id' => $merchant->id,
            'amount' => $request->amount,
            'bank_name' => $merchant->bank_name,
            'bank_account_number' => $merchant->bank_account_number,
            'bank_account_name' => $merchant->bank_account_name,
            'notes' => $request->notes,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request submitted successfully',
            'data' => $withdrawal
        ], 201);
    }

    /**
     * Get withdrawal history
     */
    public function withdrawals(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $withdrawals = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $withdrawals
        ]);
    }

    /**
     * Get merchant balance
     */
    public function balance(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $totalEarnings = MerchantPayment::where('merchant_id', $merchant->id)
            ->where('status', 'paid')
            ->sum('merchant_amount');

        $withdrawnAmount = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->whereIn('status', ['completed'])
            ->sum('amount');

        $pendingWithdrawal = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $availableBalance = $totalEarnings - $withdrawnAmount - $pendingWithdrawal;

        return response()->json([
            'success' => true,
            'data' => [
                'total_earnings' => $totalEarnings,
                'withdrawn_amount' => $withdrawnAmount,
                'pending_withdrawal' => $pendingWithdrawal,
                'available_balance' => $availableBalance
            ]
        ]);
    }
}