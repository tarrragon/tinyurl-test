<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈sends〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sends', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->foreignId('team_id')->constrained('teams', 'id', 'sends_team_id_fkey');
            $table->text('campaign');
            $table->text('channel');
            $table->text('original_url');
            $table->foreignId('created_by')->constrained('users', 'id', 'sends_created_by_fkey');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->index(['team_id', 'campaign'], 'sends_team_campaign_idx');
        });
        DB::statement('ALTER TABLE sends ADD CONSTRAINT sends_url_check CHECK (char_length(original_url) <= 2048)');
        DB::statement("ALTER TABLE sends ADD CONSTRAINT sends_channel_check CHECK (channel IN ('sms', 'email', 'ad'))");
        DB::statement('ALTER TABLE sends ADD CONSTRAINT sends_campaign_check CHECK (char_length(campaign) BETWEEN 1 AND 100)');
        DB::statement('CREATE INDEX sends_created_by_idx ON sends (created_by, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sends');
    }
};
