<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_pc_builds', function (Blueprint $table) {
            $table->id();
            $table->string('share_code', 12)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('build_name')->default('Custom PC Rig');
            $table->json('components'); // JSON array of selected items with product_id, price, qty, specs
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_pc_builds');
    }
};
