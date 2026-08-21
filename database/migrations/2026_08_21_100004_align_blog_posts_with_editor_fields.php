<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The blog editor and every public blog view read excerpt / author_name / author_role,
 * but the table only had summary / author. Creating or editing an article from the
 * admin failed outright, and the public article cards rendered blank author lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('blog_posts', 'excerpt')) {
                $table->text('excerpt')->nullable()->after('category');
            }
            if (! Schema::hasColumn('blog_posts', 'author_name')) {
                $table->string('author_name')->nullable()->after('link_url');
            }
            if (! Schema::hasColumn('blog_posts', 'author_role')) {
                $table->string('author_role')->nullable()->after('author_name');
            }
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->text('summary')->nullable()->change();
            $table->string('author')->nullable()->change();
        });

        // Carry existing articles onto the new columns.
        DB::table('blog_posts')->whereNull('excerpt')->update(['excerpt' => DB::raw('summary')]);
        DB::table('blog_posts')->whereNull('author_name')->update(['author_name' => DB::raw('author')]);
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(['excerpt', 'author_name', 'author_role']);
        });
    }
};
