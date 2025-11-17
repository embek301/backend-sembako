<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if table exists
        if (Schema::hasTable('delivery_trackings')) {
            // SOLUTION 1: Drop and recreate column (MySQL)
            try {
                // First, temporarily change column to VARCHAR
                DB::statement("ALTER TABLE delivery_trackings MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending_payment'");
                
                // Then convert back to ENUM with all new values
                DB::statement("
                    ALTER TABLE delivery_trackings 
                    MODIFY COLUMN status ENUM(
                        'pending_payment',
                        'waiting_merchant', 
                        'waiting_driver',
                        'driver_assigned',
                        'on_the_way',
                        'arrived',
                        'delivered',
                        'cancelled'
                    ) DEFAULT 'pending_payment'
                ");
                
                \Log::info('✅ Successfully updated delivery_trackings status column');
            } catch (\Exception $e) {
                \Log::error('❌ Failed to update delivery_trackings: ' . $e->getMessage());
                
                // FALLBACK: If above fails, try this approach
                Schema::table('delivery_trackings', function (Blueprint $table) {
                    $table->dropColumn('status');
                });
                
                Schema::table('delivery_trackings', function (Blueprint $table) {
                    $table->enum('status', [
                        'pending_payment',
                        'waiting_merchant',
                        'waiting_driver',
                        'driver_assigned',
                        'on_the_way',
                        'arrived',
                        'delivered',
                        'cancelled'
                    ])->default('pending_payment')->after('vehicle_number');
                });
            }
        } else {
            // Create table if doesn't exist
            Schema::create('delivery_trackings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->onDelete('cascade');
                $table->foreignId('driver_id')->nullable()->constrained('users')->onDelete('set null');
                $table->string('driver_name')->nullable();
                $table->string('driver_phone')->nullable();
                $table->string('vehicle_number')->nullable();
                $table->enum('status', [
                    'pending_payment',
                    'waiting_merchant',
                    'waiting_driver',
                    'driver_assigned',
                    'on_the_way',
                    'arrived',
                    'delivered',
                    'cancelled'
                ])->default('pending_payment');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->timestamp('estimated_delivery')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('driver_id');
            });
            
            \Log::info('✅ Created delivery_trackings table');
        }

        // Ensure tracking_histories table exists with correct structure
        if (!Schema::hasTable('tracking_histories')) {
            Schema::create('tracking_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delivery_tracking_id')->constrained()->onDelete('cascade');
                $table->string('status');
                $table->text('description')->nullable();
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->timestamp('created_at');

                $table->index('delivery_tracking_id');
                $table->index('status');
            });
            
            \Log::info('✅ Created tracking_histories table');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to simple status if needed
        if (Schema::hasTable('delivery_trackings')) {
            try {
                DB::statement("ALTER TABLE delivery_trackings MODIFY COLUMN status VARCHAR(50)");
                
                DB::statement("
                    ALTER TABLE delivery_trackings 
                    MODIFY COLUMN status ENUM(
                        'waiting_driver',
                        'driver_assigned',
                        'on_the_way',
                        'arrived',
                        'delivered',
                        'cancelled'
                    ) DEFAULT 'waiting_driver'
                ");
            } catch (\Exception $e) {
                \Log::warning('Could not revert delivery_trackings status: ' . $e->getMessage());
            }
        }
    }
};