<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_record_entity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_record_id')->constrained('case_records')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['case_record_id', 'entity_id'], 'case_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_record_entity');
    }
};
