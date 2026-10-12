<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈click_events〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('click_events', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->uuid('click_id');
            $table->foreignId('link_id')->constrained('links', 'id', 'click_events_link_id_fkey');
            $table->timestampTz('clicked_at');
            $table->boolean('expired');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('referer')->nullable();
            $table->index('clicked_at', 'click_events_clicked_at_idx');
            $table->index(['link_id', 'clicked_at'], 'click_events_link_id_idx');
        });
        // 規格寫的是唯一索引而不是唯一約束；Schema Builder 的 unique() 建的是約束
        DB::statement('CREATE UNIQUE INDEX click_events_click_id_key ON click_events (click_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('click_events');
    }
};
