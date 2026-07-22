<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prompts', 'intent_key')) {
            Schema::table('prompts', function (Blueprint $table): void {
                $table->string('intent_key', 64)->nullable()->after('type')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prompts', 'intent_key')) {
            Schema::table('prompts', function (Blueprint $table): void {
                $table->dropColumn('intent_key');
            });
        }
    }
};
