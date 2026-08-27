<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pages the shop writes itself.
 *
 * About, privacy, terms and the return policy were links in the footer with
 * nothing behind them, and About's copy was written into the JSX — so changing
 * a word about the business meant a developer and a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            // Shown under the title on the page itself.
            $table->string('subtitle')->nullable();
            $table->longText('body')->nullable();

            // What search engines and a shared link show.
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();

            $table->boolean('is_published')->default(true)->index();

            /*
             * A page the shop should not be able to delete, because the footer
             * links to it and the law expects it to exist. The text is theirs;
             * the page's existence is not.
             */
            $table->boolean('is_system')->default(false);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
