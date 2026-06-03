<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_material_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('linkable_type', 160);
            $table->unsignedBigInteger('linkable_id');
            $table->string('link_role', 50)->default('related');
            $table->timestamps();

            $table->unique(['entity_id', 'linkable_type', 'linkable_id'], 'entity_material_unique');
            $table->index(['linkable_type', 'linkable_id'], 'entity_material_linkable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_material_links');
    }
};
