<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Voucher;
use Carbon\Carbon;

class VoucherSeeder extends Seeder
{
    public function run()
    {
        $vouchers = [
            [
                'code' => 'WELCOME10',
                'name' => 'Welcome Discount 10%',
                'type' => 'percentage',
                'value' => 10,
                'min_purchase' => 50000,
                'max_discount' => 20000,
                'usage_limit' => 100,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonths(3),
                'is_active' => true
            ],
            [
                'code' => 'DISKON50K',
                'name' => 'Discount Rp 50.000',
                'type' => 'fixed',
                'value' => 50000,
                'min_purchase' => 200000,
                'max_discount' => null,
                'usage_limit' => 50,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonths(1),
                'is_active' => true
            ],
            [
                'code' => 'MEMBER20',
                'name' => 'Member Discount 20%',
                'type' => 'percentage',
                'value' => 20,
                'min_purchase' => 100000,
                'max_discount' => 50000,
                'usage_limit' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addYears(1),
                'is_active' => true
            ],
        ];

        foreach ($vouchers as $voucher) {
            Voucher::create($voucher);
        }
    }
}