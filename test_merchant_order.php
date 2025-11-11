<?php

// test_merchant_order.php
// Run: php artisan tinker < test_merchant_order.php

use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserAddress;
use App\Models\MerchantPayment;

echo "🔍 Creating test merchant order...\n\n";

// 1. Get merchant
$merchant = User::where('role', 'merchant')->first();

if (!$merchant) {
    echo "❌ No merchant found! Please create a merchant account first.\n";
    exit;
}

echo "✅ Merchant: {$merchant->store_name} (ID: {$merchant->id})\n";

// 2. Get merchant's product
$product = Product::where('merchant_id', $merchant->id)->first();

if (!$product) {
    echo "❌ No products found for this merchant! Please create a product first.\n";
    exit;
}

echo "✅ Product: {$product->name} (ID: {$product->id}, Price: Rp " . number_format($product->price, 0, ',', '.') . ")\n";

// 3. Get a customer
$customer = User::where('role', 'member')->first();

if (!$customer) {
    echo "❌ No customer found! Please create a customer account first.\n";
    exit;
}

echo "✅ Customer: {$customer->name} (ID: {$customer->id})\n";

// 4. Get customer address
$address = UserAddress::where('user_id', $customer->id)->first();

if (!$address) {
    // Create a test address
    $address = UserAddress::create([
        'user_id' => $customer->id,
        'recipient_name' => $customer->name,
        'phone' => $customer->phone ?? '081234567890',
        'address' => 'Jl. Test No. 123',
        'province' => 'Jawa Timur',
        'city' => 'Surabaya',
        'district' => 'Wonokromo',
        'postal_code' => '60243',
        'label' => 'home',
        'is_default' => true,
    ]);
    echo "✅ Created test address\n";
} else {
    echo "✅ Address: {$address->address}\n";
}

// 5. Calculate amounts
$quantity = 2;
$itemAmount = $product->price * $quantity;
$shippingCost = 10000;
$totalPrice = $itemAmount + $shippingCost;
$commissionRate = $merchant->commission_rate ?? 5;
$commissionAmount = $itemAmount * ($commissionRate / 100);
$merchantAmount = $itemAmount - $commissionAmount;

echo "\n💰 Order Calculation:\n";
echo "   Product Price: Rp " . number_format($product->price, 0, ',', '.') . "\n";
echo "   Quantity: {$quantity}\n";
echo "   Subtotal: Rp " . number_format($itemAmount, 0, ',', '.') . "\n";
echo "   Shipping: Rp " . number_format($shippingCost, 0, ',', '.') . "\n";
echo "   Total: Rp " . number_format($totalPrice, 0, ',', '.') . "\n";
echo "   Commission ({$commissionRate}%): Rp " . number_format($commissionAmount, 0, ',', '.') . "\n";
echo "   Merchant Amount: Rp " . number_format($merchantAmount, 0, ',', '.') . "\n\n";

// 6. Create order
$orderNumber = 'ORD-TEST-' . time();

$order = Order::create([
    'user_id' => $customer->id,
    'address_id' => $address->id,
    'order_number' => $orderNumber,
    'status' => 'pending',
    'payment_status' => 'unpaid',
    'merchant_status' => 'pending', // ✅ IMPORTANT
    'payment_method' => 'midtrans',
    'subtotal' => $itemAmount,
    'shipping_cost' => $shippingCost,
    'discount' => 0,
    'total_price' => $totalPrice,
]);

echo "✅ Order created: {$order->order_number} (ID: {$order->id})\n";
echo "   Status: {$order->status}\n";
echo "   Payment Status: {$order->payment_status}\n";
echo "   Merchant Status: {$order->merchant_status}\n\n";

// 7. Create order item
$orderItem = OrderItem::create([
    'order_id' => $order->id,
    'product_id' => $product->id,
    'merchant_id' => $merchant->id, // ✅ IMPORTANT
    'product_name' => $product->name,
    'quantity' => $quantity,
    'price' => $product->price,
    'subtotal' => $itemAmount,
]);

echo "✅ Order item created:\n";
echo "   Product: {$orderItem->product_name}\n";
echo "   Merchant ID: {$orderItem->merchant_id}\n";
echo "   Quantity: {$orderItem->quantity}\n";
echo "   Subtotal: Rp " . number_format($orderItem->subtotal, 0, ',', '.') . "\n\n";

// 8. Create merchant payment
$merchantPayment = MerchantPayment::create([
    'merchant_id' => $merchant->id,
    'order_id' => $order->id,
    'order_amount' => $itemAmount,
    'commission_amount' => $commissionAmount,
    'merchant_amount' => $merchantAmount,
    'status' => 'pending',
]);

echo "✅ Merchant payment created:\n";
echo "   Merchant: {$merchant->store_name}\n";
echo "   Order Amount: Rp " . number_format($merchantPayment->order_amount, 0, ',', '.') . "\n";
echo "   Commission: Rp " . number_format($merchantPayment->commission_amount, 0, ',', '.') . "\n";
echo "   Merchant Amount: Rp " . number_format($merchantPayment->merchant_amount, 0, ',', '.') . "\n";
echo "   Status: {$merchantPayment->status}\n\n";

// 9. Verify in database
$verifyOrder = Order::with(['items', 'user'])->find($order->id);
$merchantOrders = Order::whereHas('items', function($q) use ($merchant) {
    $q->where('merchant_id', $merchant->id);
})->get();

echo "🔍 Verification:\n";
echo "   Order exists: " . ($verifyOrder ? 'YES' : 'NO') . "\n";
echo "   Has items: " . ($verifyOrder->items->count() > 0 ? 'YES' : 'NO') . "\n";
echo "   Item has merchant_id: " . ($verifyOrder->items->first()->merchant_id ? 'YES' : 'NO') . "\n";
echo "   Merchant can see order: " . ($merchantOrders->count() > 0 ? 'YES' : 'NO') . "\n\n";

echo "✅ TEST COMPLETED!\n";
echo "📱 Now login as merchant and check Orders screen!\n";
echo "   Merchant Email: {$merchant->email}\n\n";