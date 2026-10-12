<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈recipient_hourly〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_hourly', function (Blueprint $table) {
            $table->foreignId('link_id')->constrained('links', 'id', 'recipient_hourly_link_id_fkey');
            $table->timestampTz('hour');
            $table->timestampTz('first_click_at')->nullable();
            $table->bigInteger('human_clicks');
            $table->bigInteger('conversions');
            $table->decimal('revenue', 14, 2);
            $table->primary(['link_id', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_hourly');
    }
};
