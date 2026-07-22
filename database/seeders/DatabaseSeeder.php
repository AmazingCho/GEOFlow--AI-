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

        // 仅在可证明为全新默认安装时写入 V1 预设；已有业务库的升级必须走受控同步命令。
        $this->call(PromptPresetSeeder::class);
    }
}
