<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telling every customer about something at once.
 *
 * The shop could email one person a reply and text one customer about one
 * order, and had no way at all to say "the Eid sale starts Thursday" to
 * everybody. That is the thing a mailing list is collected for, and this shop
 * had been collecting one with nowhere to send it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            // For the shop's own list; never sent to anybody.
            $table->string('title');

            $table->string('subject')->nullable();
            $table->text('body');

            /*
             * Email costs nothing and can be long; an SMS costs money per 160
             * characters and is read. Most shops want one or the other for a
             * given message, so it is chosen per campaign.
             */
            $table->string('channel', 10)->default('email');

            // subscribers | customers | all
            $table->string('audience', 20)->default('subscribers');

            /*
             * draft   — being written
             * sending — the queue is working through it
             * sent    — every recipient has been attempted
             * failed  — it stopped before it finished
             */
            $table->string('status', 20)->default('draft');

            // Counted from the recipient rows rather than incremented as it
            // goes, so a crashed worker cannot leave the figure wrong.
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // What the SMS half was expected to cost, worked out before
            // sending. A blast is the one message where the bill is worth
            // seeing in advance.
            $table->unsignedInteger('sms_parts')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('created_by_name')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            // The address or number, as it was when the campaign was built.
            $table->string('contact');
            $table->string('channel', 10);

            // pending | sent | failed
            $table->string('status', 12)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            /*
             * One row per person per channel, and the reason this table exists
             * rather than a running counter: a campaign that fell over half
             * way can be picked up again without sending twice to the people
             * who already had it.
             */
            $table->unique(['campaign_id', 'channel', 'contact']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
