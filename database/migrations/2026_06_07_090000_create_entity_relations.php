<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('relation_type_id')->constrained('relation_types')->cascadeOnDelete();
            $table->foreignId('target_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->unsignedTinyInteger('strength')->default(80);
            $table->unsignedBigInteger('source_chunk_id')->nullable();
            $table->string('source_type', 20)->default('manual');
            $table->string('notes', 500)->default('');
            $table->timestamps();

            $table->unique(['source_entity_id', 'relation_type_id', 'target_entity_id'], 'unique_entity_relation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_relations');
    }
};
