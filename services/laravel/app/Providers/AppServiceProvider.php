<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // timestampTz() 預設建出 timestamp(0)，秒以下被捨去；規格是不指定精度的 timestamptz（微秒），
        // 和 Go 那一套建出的 schema 一致（見 docs/data-model.md〈資料表的共通約定〉）
        Schema::defaultTimePrecision(null);
    }
}
