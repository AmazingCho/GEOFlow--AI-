<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('entity_type', 80)->default('');
            $table->text('aliases')->nullable();
            $table->text('description')->nullable();
            $table->text('attributes_json')->nullable();
            $table->string('source_url', 500)->default('');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index('name');
            $table->index('entity_type');
        });

        Schema::create('case_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('title', 200);
            $table->string('case_type', 100)->default('');
            $table->text('summary')->nullable();
            $table->text('challenge')->nullable();
            $table->text('solution')->nullable();
            $table->text('result')->nullable();
            $table->text('metrics')->nullable();
            $table->string('source_url', 500)->default('');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index('title');
            $table->index('case_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_records');
        Schema::dropIfExists('entities');
    }
};
