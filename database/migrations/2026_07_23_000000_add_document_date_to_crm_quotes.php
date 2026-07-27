<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_quotes') || Schema::hasColumn('crm_quotes', 'document_date')) {
            return;
        }

        Schema::table('crm_quotes', function (Blueprint $table): void {
            $table->date('document_date')->nullable();
        });

        DB::table('crm_quotes')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(200, static function ($quotes): void {
                foreach ($quotes as $quote) {
                    $documentDate = $quote->created_at
                        ? Carbon::parse($quote->created_at)->toDateString()
                        : now()->toDateString();

                    DB::table('crm_quotes')
                        ->where('id', $quote->id)
                        ->update(['document_date' => $documentDate]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_quotes') || ! Schema::hasColumn('crm_quotes', 'document_date')) {
            return;
        }

        Schema::table('crm_quotes', function (Blueprint $table): void {
            $table->dropColumn('document_date');
        });
    }
};
