<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who carries the parcel, and the number the customer can chase it with.
 *
 * An order could be marked shipped and that was the end of it — no carrier, no
 * consignment number, nothing for a customer ringing up to ask where their
 * delivery is.
 *
 * The couriers most Bangladeshi shops use are seeded, each with the shape of
 * its tracking link. Those links are editable on purpose: carriers change
 * their URLs, and a shop should be able to correct one without waiting for a
 * deploy. They are worth checking against the merchant panel before relying on
 * them.
 */
return new class extends Migration
{
    /** name, slug, tracking URL template, phone */
    private const SEEDED = [
        ['Pathao Courier', 'pathao', 'https://merchant.pathao.com/tracking?consignment_id={tracking}', '09678100800'],
        ['Steadfast Courier', 'steadfast', 'https://steadfast.com.bd/t/{tracking}', '09610000559'],
        ['RedX', 'redx', 'https://redx.com.bd/track-parcel/?trackingId={tracking}', '09610016725'],
        ['Paperfly', 'paperfly', 'https://go.paperfly.com.bd/', '09678242424'],
        ['eCourier', 'ecourier', 'https://ecourier.com.bd/track-shipment/?tracking={tracking}', '09612444999'],
        ['Sundarban Courier Service', 'sundarban', null, '09610994488'],
        ['SA Paribahan', 'sa-paribahan', null, '09604111666'],
        ['Karatoa Courier Service', 'karatoa', null, '09638100100'],
        ['Janani Express', 'janani', null, null],
        ['Own delivery', 'own-delivery', null, null],
    ];

    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            // {tracking} is replaced with the consignment number. Null where
            // the carrier has no public lookup — the number still gets printed
            // and quoted down the phone.
            $table->string('tracking_url_template', 500)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (self::SEEDED as $position => [$name, $slug, $url, $phone]) {
            DB::table('couriers')->insert([
                'name' => $name,
                'slug' => $slug,
                'tracking_url_template' => $url,
                'phone' => $phone,
                'sort_order' => $position,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('courier_id')->nullable()->after('status')->constrained()->nullOnDelete();
            $table->string('tracking_number', 100)->nullable()->after('courier_id');
            // When it left, which is not the same as when the row was updated.
            $table->timestamp('dispatched_at')->nullable()->after('tracking_number');
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
            $table->dropConstrainedForeignId('courier_id');
            $table->dropColumn(['tracking_number', 'dispatched_at']);
        });

        Schema::dropIfExists('couriers');
    }
};
