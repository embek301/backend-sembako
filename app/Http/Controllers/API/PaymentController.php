<?php
// app/Http/Controllers/Api/PaymentController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Notification;
use Midtrans\Transaction;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Set Midtrans configuration
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');
    }

    /**
     * Create payment
     */
    public function createPayment(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id'
        ]);

        try {
            // Load order with all relations
            $order = Order::with(['items', 'user', 'address'])
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->order_id);

            // Check if order is payable
            if ($order->payment_status !== 'unpaid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order already paid or cannot be paid'
                ], 400);
            }

            // COD doesn't need payment gateway
            if ($order->payment_method === 'cod') {
                return response()->json([
                    'success' => false,
                    'message' => 'COD payment doesn\'t require online payment'
                ], 400);
            }

            // Check if address exists
            if (!$order->address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order address not found'
                ], 400);
            }

            // Generate unique transaction ID
            $transactionId = $order->order_number . '-' . time() . '-' . strtoupper(\Illuminate\Support\Str::random(4));

            // Prepare items for Midtrans
            $items = [];
            foreach ($order->items as $item) {
                $items[] = [
                    'id' => $item->product_id,
                    'price' => (int) $item->price,
                    'quantity' => $item->quantity,
                    'name' => $item->product_name
                ];
            }

            // Add shipping cost
            if ($order->shipping_cost > 0) {
                $items[] = [
                    'id' => 'shipping',
                    'price' => (int) $order->shipping_cost,
                    'quantity' => 1,
                    'name' => 'Shipping Cost'
                ];
            }

            // ✅ FIXED: Add discount as negative item
            if ($order->discount > 0) {
                $items[] = [
                    'id' => 'discount',
                    'price' => -(int) $order->discount, // Negative value for discount
                    'quantity' => 1,
                    'name' => 'Voucher Discount'
                ];
            }

            // Get user data safely
            $userName = $order->user->name ?? 'Customer';
            $userEmail = $order->user->email ?? 'customer@example.com';

            // Get address data safely
            $recipientName = $order->address->recipient_name ?? $userName;
            $phone = $order->address->phone ?? '08123456789';
            $address = $order->address->address ?? '';
            $city = $order->address->city ?? '';
            $province = $order->address->province ?? '';
            $postalCode = $order->address->postal_code ?? '';

            // ✅ Calculate final amount (should match total_price in order)
            $finalAmount = (int) $order->total_price;

            // Midtrans transaction parameters
            $params = [
                'transaction_details' => [
                    'order_id' => $transactionId,
                    'gross_amount' => $finalAmount, // ✅ Use final amount after discount
                ],
                'item_details' => $items,
                'customer_details' => [
                    'first_name' => $userName,
                    'email' => $userEmail,
                    'phone' => $phone,
                    'billing_address' => [
                        'address' => $address,
                        'city' => $city,
                        'postal_code' => $postalCode,
                    ],
                    'shipping_address' => [
                        'first_name' => $recipientName,
                        'phone' => $phone,
                        'address' => $address,
                        'city' => $city,
                        'postal_code' => $postalCode,
                    ]
                ],
            ];

            // Log untuk debugging
            \Log::info('Payment Request Details', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transactionId,
                'subtotal' => $order->subtotal,
                'shipping_cost' => $order->shipping_cost,
                'discount' => $order->discount,
                'total_price' => $order->total_price,
                'final_amount' => $finalAmount,
                'items_count' => count($items),
            ]);

            // Get Snap Token
            $snapToken = Snap::getSnapToken($params);

            // Create or update payment record
            $payment = Payment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_gateway' => 'midtrans',
                    'transaction_id' => $transactionId,
                    'amount' => $finalAmount, // ✅ Save final amount
                    'status' => 'pending'
                ]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'snap_token' => $snapToken,
                    'order_number' => $order->order_number,
                    'transaction_id' => $transactionId,
                    'amount' => $finalAmount,
                    'discount' => $order->discount,
                    'original_amount' => $order->subtotal + $order->shipping_cost,
                ]
            ]);

        } catch (\Exception $e) {
            // Detailed error logging
            \Log::error('Payment creation failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'order_id' => $request->order_id ?? null,
                'user_id' => $request->user()->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Midtrans webhook callback
     */
   public function webhook(Request $request)
    {
        try {
            $notification = new Notification();

            $transactionId = $notification->order_id;
            $transactionStatus = $notification->transaction_status;
            $fraudStatus = $notification->fraud_status;
            $paymentType = $notification->payment_type;

            \Log::info('Midtrans webhook received', [
                'transaction_id' => $transactionId,
                'status' => $transactionStatus,
                'payment_type' => $paymentType
            ]);

            $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();
            $order = Order::findOrFail($payment->order_id);

            if ($transactionStatus == 'capture') {
                if ($fraudStatus == 'accept') {
                    $this->updateOrderStatus($order, $payment, 'success', $paymentType, $notification);
                }
            } elseif ($transactionStatus == 'settlement') {
                $this->updateOrderStatus($order, $payment, 'success', $paymentType, $notification);
            } elseif ($transactionStatus == 'pending') {
                $payment->update([
                    'status' => 'pending',
                    'payment_type' => $paymentType,
                    'metadata' => json_decode(json_encode($notification), true)
                ]);
            } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                $this->updateOrderStatus($order, $payment, 'failed', $paymentType, $notification);
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            \Log::error('Webhook error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check payment status
     */
    public function checkStatus(Request $request, $orderId)
    {
        try {
            $order = Order::with('payment')
                ->where('user_id', $request->user()->id)
                ->findOrFail($orderId);

            if (!$order->payment || !$order->payment->transaction_id) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'order_status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'payment' => $order->payment
                    ]
                ]);
            }

            // Get status from Midtrans API
            $transactionId = $order->payment->transaction_id;
            
            try {
                $status = Transaction::status($transactionId);

                // Normalize status to an array
                $statusArray = [];
                if (is_array($status)) {
                    $statusArray = $status;
                } elseif (is_object($status)) {
                    $statusArray = json_decode(json_encode($status), true);
                }

                \Log::info('Midtrans status check', [
                    'transaction_id' => $transactionId,
                    'status' => $statusArray
                ]);

                // Update based on Midtrans response
                $transactionStatus = $statusArray['transaction_status'] ?? null;
                $fraudStatus = $statusArray['fraud_status'] ?? 'accept';
                $paymentType = $statusArray['payment_type'] ?? null;

                if ($transactionStatus == 'capture') {
                    if ($fraudStatus == 'accept') {
                        $this->updateOrderStatus($order, $order->payment, 'success', $paymentType, $statusArray);
                    }
                } elseif ($transactionStatus == 'settlement') {
                    $this->updateOrderStatus($order, $order->payment, 'success', $paymentType, $statusArray);
                } elseif ($transactionStatus == 'pending') {
                    $order->payment->update([
                        'status' => 'pending',
                        'payment_type' => $paymentType,
                        'metadata' => $statusArray
                    ]);
                } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                    $this->updateOrderStatus($order, $order->payment, 'failed', $paymentType, $statusArray);
                }

                // Reload order to get updated data
                $order->refresh();
                $order->load('payment');

            } catch (\Exception $e) {
                \Log::warning('Midtrans status check failed: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'order_status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'payment' => $order->payment
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Check status error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status'
            ], 500);
        }
    }

    /**
     * Helper: Update order and payment status
     */
    private function updateOrderStatus($order, $payment, $status, $paymentType, $notification)
    {
        if ($status === 'success') {
            $order->update([
                'payment_status' => 'paid',
                'status' => 'paid',
                'paid_at' => now()
            ]);

            $payment->update([
                'status' => 'success',
                'payment_type' => $paymentType,
                'paid_at' => now(),
                'metadata' => json_decode(json_encode($notification), true)
            ]);
        } else {
            $order->update([
                'payment_status' => 'failed'
            ]);

            $payment->update([
                'status' => 'failed',
                'payment_type' => $paymentType,
                'metadata' => json_decode(json_encode($notification), true)
            ]);
        }
    }
}