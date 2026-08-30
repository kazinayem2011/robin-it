<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the warranty actually says, as opposed to how long it runs.
 *
 * `warranty_months` is an integer because SerialService and WarrantyController
 * do arithmetic with it — a serial plus a sale date plus a number of months is
 * an expiry date, and a claim is either inside it or outside it. That has to
 * stay a number and stays exactly as it is.
 *
 * It cannot, however, state the terms. Real laptop warranties are not one
 * number:
 *
 *     2 Years warranty (Battery & Adapter 1 Year)
 *
 * The chassis and the battery run for different periods, and a customer
 * deciding whether to buy needs the sentence, not the integer. Storing "24"
 * and rendering "24 months" quietly promises two years of battery cover that
 * the manufacturer does not give.
 *
 * So: months for the claims system, text for the customer. Where both are set
 * the text is what the product page shows, because it is the more specific
 * truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('warranty_text', 255)->nullable()->after('warranty_months');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('warranty_text');
        });
    }
};
