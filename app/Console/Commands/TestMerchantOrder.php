<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserAddress;
use App\Models\MerchantPayment;

class TestMerchantOrder extends Command
{
    protected $signature = 'test:merchant-order';
    protected $description = 'Create a test order for merchant flow';

    public function handle()
    {
        $this->info("🔍 Creating test merchant order...\n");

        // 1. Get merchant
        $merchant = User::where('role', 'merchant')->first();

        if (!$merchant) {
            $this->error("❌ No merchant found! Please create a merchant first.");
            return;
        }

        $this->info("✅ Merchant: {$merchant->store_name} (ID: {$merchant->id})");

        // 2. Get product
        $product = Product::where('merchant_id', $merchant->id)->first();

        if (!$product) {
            $this->error("❌ No products found for this merchant!");
            return;
        }

        $this->info("✅ Product: {$product->name} (ID: {$product->id}, Price: Rp " . number_format($product->price) . ")");

        // 3. Get customer
        $customer = User::where('role', 'member')->first();

        if (!$customer) {
            $this->error("❌ No customer found! Please create a member first.");
            return;
        }

        $this->info("✅ Customer: {$customer->name} (ID: {$customer->id})");

        // 4. Get or create address
        $address = UserAddress::where('user_id', $customer->id)->first();

        if (!$address) {
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
            $this->info("✅ Created test address");
        } else {
            $this->info("✅ Address: {$address->address}");
        }

        // 5. Calculations
        $quantity = 2;
        $itemAmount = $product->price * $quantity;
        $shippingCost = 10000;
        $totalPrice = $itemAmount + $shippingCost;
        $commissionRate = $merchant->commission_rate ?? 5;
        $commissionAmount = $itemAmount * ($commissionRate / 100);
        $merchantAmount = $itemAmount - $commissionAmount;

        $this->info("\n💰 Order Calculation:");
        $this->line("   Product Price: Rp " . number_format($product->price));
        $this->line("   Quantity: {$quantity}");
        $this->line("   Subtotal: Rp " . number_format($itemAmount));
        $this->line("   Shipping: Rp " . number_format($shippingCost));
        $this->line("   Total: Rp " . number_format($totalPrice));
        $this->line("   Commission ({$commissionRate}%): Rp " . number_format($commissionAmount));
        $this->line("   Merchant Amount: Rp " . number_format($merchantAmount));

        // 6. Create order
        $orderNumber = 'ORD-TEST-' . time();

        $order = Order::create([
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'order_number' => $orderNumber,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'merchant_status' => 'pending',
            'payment_method' => 'midtrans',
            'subtotal' => $itemAmount,
            'shipping_cost' => $shippingCost,
            'discount' => 0,
            'total_price' => $totalPrice,
        ]);

        $this->info("\n✅ Order created: {$order->order_number} (ID: {$order->id})");

        // 7. Create order item
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'price' => $product->price,
            'subtotal' => $itemAmount,
        ]);

        $this->info("✅ Order item created: {$orderItem->product_name}");

        // 8. Merchant payment
        $merchantPayment = MerchantPayment::create([
            'merchant_id' => $merchant->id,
            'order_id' => $order->id,
            'order_amount' => $itemAmount,
            'commission_amount' => $commissionAmount,
            'merchant_amount' => $merchantAmount,
            'status' => 'pending',
        ]);

        $this->info("\n✅ Merchant payment created:");
        $this->line("   Order Amount: Rp " . number_format($merchantPayment->order_amount));
        $this->line("   Commission: Rp " . number_format($merchantPayment->commission_amount));
        $this->line("   Merchant Amount: Rp " . number_format($merchantPayment->merchant_amount));

        // 9. Verification
        $verifyOrder = Order::with(['items', 'user'])->find($order->id);

        $this->info("\n🔍 Verification:");
        $this->line("   Order exists: " . ($verifyOrder ? 'YES' : 'NO'));
        $this->line("   Has items: " . ($verifyOrder->items->count() > 0 ? 'YES' : 'NO'));
        $this->line("   Item has merchant_id: " . ($verifyOrder->items->first()->merchant_id ? 'YES' : 'NO'));

        $this->info("\n✅ TEST COMPLETED!");
        $this->info("📱 Now login as merchant and check Orders screen!");
        $this->info("   Merchant Email: {$merchant->email}");

        return 0;
    }
}
