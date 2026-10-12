<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈campaign_hourly〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_hourly', function (Blueprint $table) {
            $table->bigInteger('team_id');
            $table->bigInteger('created_by');
            $table->text('campaign');
            $table->text('channel');
            $table->bigInteger('send_id');
            $table->text('segment');
            $table->timestampTz('hour');
            $table->bigInteger('clicks');
            $table->bigInteger('human_clicks');
            $table->bigInteger('unique_clicks');
            $table->bigInteger('conversions');
            $table->decimal('revenue', 14, 2);
            $table->primary(['team_id', 'created_by', 'campaign', 'channel', 'send_id', 'segment', 'hour']);
            $table->index(['campaign', 'hour'], 'campaign_hourly_campaign_hour_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_hourly');
    }
};
