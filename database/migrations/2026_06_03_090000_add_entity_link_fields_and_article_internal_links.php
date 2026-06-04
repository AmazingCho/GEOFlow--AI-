<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            if (! Schema::hasColumn('entities', 'canonical_url')) {
                $table->string('canonical_url', 500)->default('')->after('source_url');
            }
            if (! Schema::hasColumn('entities', 'link_anchor_text')) {
                $table->string('link_anchor_text', 160)->default('')->after('canonical_url');
            }
            if (! Schema::hasColumn('entities', 'link_policy')) {
                $table->string('link_policy', 20)->default('disabled')->index()->after('link_anchor_text');
            }
        });

        if (! Schema::hasTable('article_internal_links')) {
            Schema::create('article_internal_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
                $table->string('anchor_text', 160);
                $table->string('canonical_url', 500);
                $table->string('matched_text', 200)->default('');
                $table->string('status', 20)->default('applied')->index();
                $table->string('applied_by', 120)->default('');
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['article_id', 'entity_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_internal_links');

        Schema::table('entities', function (Blueprint $table): void {
            foreach (['link_policy', 'link_anchor_text', 'canonical_url'] as $column) {
                if (Schema::hasColumn('entities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
