<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->after('product_id');
            $table->foreign('merchant_id')->references('id')->on('users');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('merchant_status', ['pending', 'approved', 'rejected'])->default('pending')->after('status');
        });
    }
};
