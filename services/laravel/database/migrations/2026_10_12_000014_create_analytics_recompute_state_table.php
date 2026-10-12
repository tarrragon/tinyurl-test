<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈analytics_recompute_state〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_recompute_state', function (Blueprint $table) {
            $table->text('name');
            $table->timestampTz('last_completed_hour');
            $table->timestampTz('last_success_at');
            $table->primary('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_recompute_state');
    }
};
