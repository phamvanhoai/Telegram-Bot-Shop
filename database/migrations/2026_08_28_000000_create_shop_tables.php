<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('telegram_id')->nullable()->unique()->after('id');
            $table->string('telegram_username')->nullable()->after('email');
            $table->string('locale', 10)->default('en');
            $table->decimal('balance', 18, 8)->default(0);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_blocked')->default(false);
        });

        Schema::create('required_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id')->unique();
            $table->string('name');
            $table->string('join_url');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 18, 8);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('warranty_days')->default(0);
            $table->enum('delivery_type', ['manual', 'automatic'])->default('manual');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['deposit', 'purchase', 'refund', 'adjustment']);
            $table->decimal('amount', 18, 8);
            $table->decimal('balance_before', 18, 8);
            $table->decimal('balance_after', 18, 8);
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->index(['reference_type', 'reference_id']);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('deposit_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('verification', ['automatic', 'manual'])->default('manual');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('deposit_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposit_method_id')->constrained();
            $table->decimal('amount', 18, 8);
            $table->string('txid')->nullable()->unique();
            $table->enum('status', ['pending', 'verifying', 'approved', 'rejected', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 12)->unique();
            $table->foreignId('user_id')->constrained();
            $table->decimal('subtotal', 18, 8);
            $table->decimal('discount', 18, 8)->default(0);
            $table->decimal('total', 18, 8);
            $table->enum('status', ['pending', 'paid', 'processing', 'completed', 'cancelled', 'refunded'])->default('paid');
            $table->text('delivery_content')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name');
            $table->decimal('unit_price', 18, 8);
            $table->unsignedInteger('quantity');
            $table->decimal('total', 18, 8);
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->enum('type', ['fixed', 'percent']);
            $table->decimal('value', 18, 8);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('deposit_requests');
        Schema::dropIfExists('deposit_methods');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('products');
        Schema::dropIfExists('required_channels');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['telegram_id', 'telegram_username', 'locale', 'balance', 'is_admin', 'is_blocked']);
        });
    }
};
