<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update users table - add merchant role
        Schema::table('users', function (Blueprint $table) {
            // Drop existing enum and recreate with merchant
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'member', 'driver', 'merchant') DEFAULT 'member'");
            
            // Add merchant-specific fields
            $table->string('store_name')->nullable()->after('address');
            $table->text('store_description')->nullable()->after('store_name');
            $table->string('store_logo')->nullable()->after('store_description');
            $table->string('bank_name')->nullable()->after('store_logo');
            $table->string('bank_account_number')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_account_number');
            $table->decimal('commission_rate', 5, 2)->default(10)->after('bank_account_name'); // Platform commission %
            $table->boolean('is_verified')->default(false)->after('commission_rate');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
        });

        // Update products table - add merchant_id
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->after('id')->constrained('users')->onDelete('cascade');
        });

        // Create merchant_payments table for tracking payments to merchants
        Schema::create('merchant_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->decimal('order_amount', 15, 2); // Total order amount
            $table->decimal('commission_amount', 15, 2); // Platform commission
            $table->decimal('merchant_amount', 15, 2); // Amount for merchant
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Create merchant_withdrawals table
        Schema::create('merchant_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('bank_name');
            $table->string('bank_account_number');
            $table->string('bank_account_name');
            $table->enum('status', ['pending', 'processing', 'completed', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_withdrawals');
        Schema::dropIfExists('merchant_payments');
        
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropColumn('merchant_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'store_name',
                'store_description',
                'store_logo',
                'bank_name',
                'bank_account_number',
                'bank_account_name',
                'commission_rate',
                'is_verified',
                'verified_at'
            ]);
            
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'member', 'driver') DEFAULT 'member'");
        });
    }
};