<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Historical migration intentionally left empty.
        // Collection is now planned as a dedicated top-level container,
        // so tags no longer create task-level business scope columns.
    }

    public function down(): void
    {
        //
    }
};
