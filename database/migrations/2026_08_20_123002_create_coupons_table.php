<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->enum('discount_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('discount_value', 10, 2); // e.g. 10 (%) or 500 (BDT)
            $table->decimal('min_spend', 10, 2)->default(0);
            $table->decimal('max_discount', 10, 2)->nullable(); // Cap for percentage discounts
            $table->integer('usage_limit')->nullable(); // Total times can be used
            $table->integer('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
