<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', function (Blueprint $table): void {
            $table->string('packing_mode', 40)->default('item_level')->index();
            $table->string('packing_status', 40)->default('draft')->index();
            $table->timestamp('packing_applied_at')->nullable();
            $table->foreignId('packing_applied_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('packing_invalid_reason', 500)->default('');
        });

        Schema::table('crm_quote_items', function (Blueprint $table): void {
            $table->boolean('packing_exempt')->default(false)->index();
            $table->unique(['id', 'quote_id'], 'crm_quote_items_id_quote_unique');
        });

        Schema::create('crm_quote_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('crm_quotes')->cascadeOnDelete();
            $table->string('package_no', 80);
            $table->string('package_type', 40)->default('wooden_case');
            $table->decimal('package_length', 12, 1)->default(0);
            $table->decimal('package_width', 12, 1)->default(0);
            $table->decimal('package_height', 12, 1)->default(0);
            $table->decimal('net_weight', 14, 3)->default(0);
            $table->decimal('gross_weight', 14, 3)->default(0);
            $table->decimal('volume_cbm', 14, 3)->default(0);
            $table->boolean('volume_is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['quote_id', 'package_no'], 'crm_quote_packages_quote_no_unique');
            $table->unique(['id', 'quote_id'], 'crm_quote_packages_id_quote_unique');
            $table->index(['quote_id', 'sort_order']);
        });

        Schema::create('crm_quote_package_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('quote_item_id');
            $table->decimal('allocated_quantity', 14, 2);
            $table->timestamps();

            $table->unique(['package_id', 'quote_item_id'], 'crm_quote_package_item_unique');
            $table->index('quote_item_id');
            $table->foreign(['package_id', 'quote_id'], 'crm_package_items_package_quote_fk')
                ->references(['id', 'quote_id'])
                ->on('crm_quote_packages')
                ->cascadeOnDelete();
            $table->foreign(['quote_item_id', 'quote_id'], 'crm_package_items_item_quote_fk')
                ->references(['id', 'quote_id'])
                ->on('crm_quote_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quote_package_items');
        Schema::dropIfExists('crm_quote_packages');

        Schema::table('crm_quote_items', function (Blueprint $table): void {
            $table->dropUnique('crm_quote_items_id_quote_unique');
            $table->dropColumn('packing_exempt');
        });

        Schema::table('crm_quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('packing_applied_by_admin_id');
            $table->dropColumn([
                'packing_mode',
                'packing_status',
                'packing_applied_at',
                'packing_invalid_reason',
            ]);
        });
    }
};
