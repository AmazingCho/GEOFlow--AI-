<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relation_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('forward_label', 50);
            $table->string('reverse_label', 50);
            $table->boolean('bidirectional')->default(false);
            $table->string('description', 200)->default('');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relation_types');
    }
};
