<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop says in an email and a text message, kept where it can be changed.
 *
 * Both were in code: seven Blade templates and nine strings in SmsTemplates. A
 * shop that wants to reword its own order confirmation has to ask a developer
 * and wait for a deploy, which in practice means the wording never changes.
 *
 * What is NOT moving here is the layout. The email templates are table-based
 * with inline styles because that is what Outlook understands, and handing that
 * markup to a rich text editor would destroy it the first time anybody pressed
 * a key. The master layout stays in Blade; the rows below hold the words inside
 * it, and the pieces that must keep their structure — an order's line items —
 * stay a placeholder that can be moved but not broken.
 *
 * `variables` is what each template declares it fills in, which is both what the
 * editor offers as chips and what the save is checked against: a placeholder
 * damaged mid-word is invisible until a customer receives it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();

            /* The event, not a display name: `order_placed`, `welcome`. */
            $table->string('key')->unique();

            $table->string('name');
            $table->string('group')->index();
            $table->string('subject');
            $table->longText('body');

            /* What it says about itself in the editor, beside the wording. */
            $table->string('hint')->nullable();

            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('group')->index();

            /*
             * No subject, and text rather than longText: a message worth more
             * than a few hundred characters is a message nobody should be
             * sending. The gateway charges per 70 characters once a single
             * Bengali letter is present, and every one of these is in Bengali
             * because the gateway requires it.
             */
            $table->text('body');

            $table->string('hint')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('email_templates');
    }
};
