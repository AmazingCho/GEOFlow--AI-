<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        // 后台默认管理员（账号与密码由 GEOFLOW_ADMIN_* 环境变量控制，见 AdminUserSeeder）
        $this->call(AdminUserSeeder::class);

        // 当前发布包内置提示词预设；会按 type + name 更新，不覆盖用户自建的其他提示词。
        $this->call(PromptPresetSeeder::class);
    }
}
