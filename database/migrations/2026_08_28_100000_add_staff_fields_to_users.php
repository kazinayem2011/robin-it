<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff accounts beyond a single all-powerful admin.
 *
 * There were two roles, so anyone let into the admin could do everything in
 * it — a storekeeper recording a delivery could also read the accounts or
 * change the SMTP password.
 *
 * Existing admins are untouched: 'admin' is still the owner role and still
 * carries every ability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A member of staff attached to one branch works that branch's
            // stock. Null means the whole shop, which is what an owner or a
            // head-office manager needs.
            $table->foreignId('store_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();
            // Suspending someone must not delete them: their name is on
            // deliveries, adjustments and refunds going back years.
            $table->boolean('is_active')->default(true)->after('store_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn(['is_active', 'last_login_at']);
        });
    }
};
