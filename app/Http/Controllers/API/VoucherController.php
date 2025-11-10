<?php




// app/Http/Controllers/Api/VoucherController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    /**
     * Get available vouchers
     */
    public function index()
    {
        $vouchers = Voucher::active()->get();

        return response()->json([
            'success' => true,
            'data' => $vouchers
        ]);
    }

    /**
     * Validate voucher
     */
    public function validate(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0'
        ]);

        $voucher = Voucher::where('code', $request->code)
            ->active()
            ->first();

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired voucher code'
            ], 404);
        }

        // Check minimum purchase
        if ($request->subtotal < $voucher->min_purchase) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum purchase requirement not met. Required: ' . number_format($voucher->min_purchase ?? 0, 0, ',', '.')

            ], 400);
        }

        // Calculate discount
        $discount = $voucher->calculateDiscount($request->subtotal);

        return response()->json([
            'success' => true,
            'message' => 'Voucher is valid',
            'data' => [
                'voucher' => $voucher,
                'discount' => $discount,
                'final_amount' => $request->subtotal - $discount
            ]
        ]);
    }
}