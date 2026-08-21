<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number')->unique(); // e.g. RMA-849201
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->string('product_name');
            $table->string('serial_number')->index();
            $table->string('invoice_number')->nullable()->index();
            $table->date('purchase_date')->nullable();
            $table->string('issue_type')->default('Hardware Malfunction');
            $table->text('issue_description');
            $table->string('dropoff_branch')->nullable();
            $table->string('status')->default('received'); // received, diagnosing, repairing, ready_for_pickup, completed, rejected
            $table->text('diagnostic_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
