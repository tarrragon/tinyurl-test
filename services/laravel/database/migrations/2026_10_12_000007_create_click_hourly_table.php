<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈click_hourly〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('click_hourly', function (Blueprint $table) {
            $table->foreignId('link_id')->constrained('links', 'id', 'click_hourly_link_id_fkey');
            $table->timestampTz('hour');
            $table->bigInteger('clicks');
            $table->primary(['link_id', 'hour']);
        });
        DB::statement('ALTER TABLE click_hourly ADD CONSTRAINT click_hourly_clicks_check CHECK (clicks >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('click_hourly');
    }
};
