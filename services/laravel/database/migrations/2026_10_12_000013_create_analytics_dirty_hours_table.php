<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈analytics_dirty_hours〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_dirty_hours', function (Blueprint $table) {
            $table->timestampTz('hour');
            $table->bigInteger('version')->default(DB::raw('1'));  // default(1) 會寫成 '1'::bigint，和規格的 dump 不同
            $table->primary('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_dirty_hours');
    }
};
