<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('collections')) {
            Schema::create('collections', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 160)->unique();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->timestamps();
            });
        }

        $this->seedDefaultCollections();

        foreach ($this->materialTables() as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'collection_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('collection_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('collections')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->materialTables()) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'collection_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('collection_id');
            });
        }

        Schema::dropIfExists('collections');
    }

    /**
     * @return list<string>
     */
    private function materialTables(): array
    {
        return [
            'knowledge_bases',
            'entities',
            'case_records',
            'keyword_libraries',
            'title_libraries',
            'image_libraries',
        ];
    }

    private function seedDefaultCollections(): void
    {
        $now = now();
        foreach ([
            ['name' => 'Automation Equipment', 'description' => 'Automation equipment products, applications, and content assets.', 'sort_order' => 10],
            ['name' => 'Industrial Cooling', 'description' => 'Industrial cooling products, applications, and content assets.', 'sort_order' => 20],
            ['name' => 'Color Sorting', 'description' => 'Color sorting products, applications, and content assets.', 'sort_order' => 30],
            ['name' => 'Lighting', 'description' => 'Lighting products, applications, and content assets.', 'sort_order' => 40],
            ['name' => 'General', 'description' => 'Shared or uncategorized business content assets.', 'sort_order' => 999],
        ] as $item) {
            $slug = Str::slug((string) $item['name']);
            if ($slug === '') {
                $slug = Str::lower(Str::random(12));
            }

            $values = [
                'name' => $item['name'],
                'description' => $item['description'],
                'status' => 'active',
                'sort_order' => $item['sort_order'],
                'updated_at' => $now,
            ];

            $exists = DB::table('collections')->where('slug', $slug)->exists();
            if ($exists) {
                DB::table('collections')->where('slug', $slug)->update($values);

                continue;
            }

            DB::table('collections')->insert($values + [
                'slug' => $slug,
                'created_at' => $now,
            ]);
        }
    }
};
