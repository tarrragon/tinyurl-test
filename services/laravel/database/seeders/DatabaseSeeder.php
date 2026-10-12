<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// 開發與驗證用的資料：照 docs/data-model.md 的規則產生，資料庫的約束擋下任何不合規格的列。
// 亂數種子固定，每次產生的資料相同；量由環境變數調整（見 SendSeeder）。
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(20261012);

        $this->call([
            TeamUserSeeder::class,
            LinkSeeder::class,
            SendSeeder::class,
            ClickSeeder::class,
        ]);
    }
}
