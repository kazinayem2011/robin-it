<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers as records rather than free text.
 *
 * The supplier was a typed string on each delivery, so "Star Tech", "Star Tech
 * Ltd" and "star tech" were three different suppliers as far as any report was
 * concerned, and there was no way to look up who to call about a faulty batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('reference')
                ->constrained()->nullOnDelete();
        });

        // Existing deliveries carry a typed name. Promote each distinct one to
        // a real supplier and point the delivery at it, so history survives.
        $names = DB::table('stock_receipts')
            ->whereNotNull('supplier_name')
            ->where('supplier_name', '!=', '')
            ->distinct()
            ->pluck('supplier_name');

        foreach ($names as $name) {
            $id = DB::table('suppliers')->insertGetId([
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('stock_receipts')
                ->where('supplier_name', $name)
                ->update(['supplier_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
        });

        Schema::dropIfExists('suppliers');
    }
};
