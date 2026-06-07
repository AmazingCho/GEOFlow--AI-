<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_quotes', 'buyer_name')) {
                $table->string('buyer_name', 200)->default('')->after('title');
            }
            if (! Schema::hasColumn('crm_quotes', 'buyer_phone')) {
                $table->string('buyer_phone', 120)->default('')->after('buyer_name');
            }
            if (! Schema::hasColumn('crm_quotes', 'buyer_email')) {
                $table->string('buyer_email', 200)->default('')->after('buyer_phone');
            }
            if (! Schema::hasColumn('crm_quotes', 'buyer_address')) {
                $table->text('buyer_address')->nullable()->after('buyer_email');
            }
            if (! Schema::hasColumn('crm_quotes', 'buyer_country')) {
                $table->string('buyer_country', 100)->default('')->after('buyer_address');
            }
            if (! Schema::hasColumn('crm_quotes', 'document_language')) {
                $table->string('document_language', 20)->default('en')->after('buyer_country');
            }
            if (! Schema::hasColumn('crm_quotes', 'trade_term')) {
                $table->string('trade_term', 80)->default('')->after('currency');
            }
            if (! Schema::hasColumn('crm_quotes', 'origin_country')) {
                $table->string('origin_country', 100)->default('')->after('trade_term');
            }
            if (! Schema::hasColumn('crm_quotes', 'warranty_terms')) {
                $table->text('warranty_terms')->nullable()->after('lead_time');
            }
            if (! Schema::hasColumn('crm_quotes', 'installation_terms')) {
                $table->text('installation_terms')->nullable()->after('warranty_terms');
            }
            if (! Schema::hasColumn('crm_quotes', 'shipping_fee')) {
                $table->decimal('shipping_fee', 14, 2)->default(0)->after('total_amount');
            }
            if (! Schema::hasColumn('crm_quotes', 'discount_amount')) {
                $table->decimal('discount_amount', 14, 2)->default(0)->after('shipping_fee');
            }
            if (! Schema::hasColumn('crm_quotes', 'tax_amount')) {
                $table->decimal('tax_amount', 14, 2)->default(0)->after('discount_amount');
            }
            if (! Schema::hasColumn('crm_quotes', 'grand_total')) {
                $table->decimal('grand_total', 14, 2)->default(0)->after('tax_amount');
            }
            if (! Schema::hasColumn('crm_quotes', 'bank_account_json')) {
                $table->json('bank_account_json')->nullable()->after('grand_total');
            }
            if (! Schema::hasColumn('crm_quotes', 'seller_company_json')) {
                $table->json('seller_company_json')->nullable()->after('bank_account_json');
            }
            if (! Schema::hasColumn('crm_quotes', 'signature_notes')) {
                $table->text('signature_notes')->nullable()->after('seller_company_json');
            }
            if (! Schema::hasColumn('crm_quotes', 'contract_terms')) {
                $table->text('contract_terms')->nullable()->after('signature_notes');
            }
            if (! Schema::hasColumn('crm_quotes', 'governing_law')) {
                $table->string('governing_law', 160)->default('')->after('contract_terms');
            }
            if (! Schema::hasColumn('crm_quotes', 'dispute_resolution')) {
                $table->text('dispute_resolution')->nullable()->after('governing_law');
            }
        });

        Schema::table('crm_quote_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_quote_items', 'line_type')) {
                $table->string('line_type', 40)->default('product')->after('entity_id')->index();
            }
            if (! Schema::hasColumn('crm_quote_items', 'sku')) {
                $table->string('sku', 120)->default('')->after('line_type');
            }
            if (! Schema::hasColumn('crm_quote_items', 'model')) {
                $table->string('model', 120)->default('')->after('sku');
            }
            if (! Schema::hasColumn('crm_quote_items', 'hs_code')) {
                $table->string('hs_code', 80)->default('')->after('model');
            }
            if (! Schema::hasColumn('crm_quote_items', 'image_id')) {
                $table->unsignedBigInteger('image_id')->nullable()->after('hs_code')->index();
            }
            if (! Schema::hasColumn('crm_quote_items', 'image_path')) {
                $table->string('image_path', 500)->default('')->after('image_id');
            }
            if (! Schema::hasColumn('crm_quote_items', 'image_original_name')) {
                $table->string('image_original_name', 200)->default('')->after('image_path');
            }
            if (! Schema::hasColumn('crm_quote_items', 'package_count')) {
                $table->unsignedInteger('package_count')->default(0)->after('amount');
            }
            if (! Schema::hasColumn('crm_quote_items', 'net_weight')) {
                $table->decimal('net_weight', 14, 3)->default(0)->after('package_count');
            }
            if (! Schema::hasColumn('crm_quote_items', 'gross_weight')) {
                $table->decimal('gross_weight', 14, 3)->default(0)->after('net_weight');
            }
            if (! Schema::hasColumn('crm_quote_items', 'volume_cbm')) {
                $table->decimal('volume_cbm', 14, 3)->default(0)->after('gross_weight');
            }
        });

        if (Schema::hasColumn('crm_quotes', 'grand_total')) {
            DB::table('crm_quotes')
                ->where(function ($query): void {
                    $query->whereNull('grand_total')->orWhere('grand_total', 0);
                })
                ->update(['grand_total' => DB::raw('total_amount')]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_quote_items', function (Blueprint $table): void {
            foreach ([
                'volume_cbm',
                'gross_weight',
                'net_weight',
                'package_count',
                'image_original_name',
                'image_path',
                'image_id',
                'hs_code',
                'model',
                'sku',
                'line_type',
            ] as $column) {
                if (Schema::hasColumn('crm_quote_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('crm_quotes', function (Blueprint $table): void {
            foreach ([
                'dispute_resolution',
                'governing_law',
                'contract_terms',
                'signature_notes',
                'seller_company_json',
                'bank_account_json',
                'grand_total',
                'tax_amount',
                'discount_amount',
                'shipping_fee',
                'installation_terms',
                'warranty_terms',
                'origin_country',
                'trade_term',
                'document_language',
                'buyer_country',
                'buyer_address',
                'buyer_email',
                'buyer_phone',
                'buyer_name',
            ] as $column) {
                if (Schema::hasColumn('crm_quotes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
