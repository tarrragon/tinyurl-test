<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 種子資料只有一份產生器：infra/postgres/seed/seed.sql，Go 與 Laravel 讀同一個資料庫，資料只產生一次。
// 這個 seeder 只負責執行它，讓 php artisan db:seed 與 scripts/seed.sh 得到相同的資料。
// 量由 SEED_LINKS、SEED_SENDS、SEED_RECIPIENTS 調整，意思見 seed.sql 的開頭。
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 預設路徑是 repo 的目錄結構；Laravel 的映像檔只包含 services/laravel，在容器裡執行時要掛載 infra/postgres/seed
        $path = env('SEED_SQL_PATH', base_path('../../infra/postgres/seed/seed.sql'));
        if (! is_file($path)) {
            throw new RuntimeException("找不到種子資料的 SQL：{$path}。在 repo 根目錄改用 scripts/seed.sh，或以 SEED_SQL_PATH 指定檔案位置。");
        }

        foreach (['links' => 'SEED_LINKS', 'sends' => 'SEED_SENDS', 'recipients' => 'SEED_RECIPIENTS'] as $name => $variable) {
            $value = env($variable);
            DB::statement(sprintf("SET seed.%s = '%s'", $name, $value === null ? '' : (int) $value));
        }

        DB::unprepared(file_get_contents($path));
    }
}
