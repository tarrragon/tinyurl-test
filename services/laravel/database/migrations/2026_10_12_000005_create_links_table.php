<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈links〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->text('code');
            $table->foreignId('send_id')->nullable()->constrained('sends', 'id', 'links_send_id_fkey');
            $table->text('original_url')->nullable();
            $table->text('channel')->nullable();
            $table->text('campaign')->nullable();
            $table->foreignId('created_by')->constrained('users', 'id', 'links_created_by_fkey');
            $table->foreignId('team_id')->nullable()->constrained('teams', 'id', 'links_team_id_fkey');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->timestampTz('expires_at');
            $table->timestampTz('disabled_at')->nullable();
            $table->unique('code', 'links_code_key');
        });
        DB::statement("ALTER TABLE links ADD CONSTRAINT links_code_check CHECK (code ~ '^[0-9A-Za-z]{6,7}$')");
        DB::statement('ALTER TABLE links ADD CONSTRAINT links_url_check CHECK (char_length(original_url) <= 2048)');
        DB::statement("ALTER TABLE links ADD CONSTRAINT links_channel_check CHECK (channel IN ('sms', 'email', 'ad'))");
        DB::statement('ALTER TABLE links ADD CONSTRAINT links_campaign_check CHECK (char_length(campaign) BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE links ADD CONSTRAINT links_send_fields_check CHECK (
            (send_id IS NULL AND original_url IS NOT NULL AND channel IS NOT NULL AND campaign IS NOT NULL)
            OR (send_id IS NOT NULL AND channel IS NULL AND campaign IS NULL))');
        // 列表只列一般連結（send_id IS NULL），索引都只收這些列
        DB::statement('CREATE INDEX links_id_idx ON links (id DESC) WHERE send_id IS NULL');
        DB::statement('CREATE INDEX links_created_by_id_idx ON links (created_by, id DESC) WHERE send_id IS NULL');
        DB::statement('CREATE INDEX links_team_id_id_idx ON links (team_id, id DESC) WHERE send_id IS NULL');
        DB::statement('CREATE INDEX links_campaign_id_idx ON links (campaign, id DESC) WHERE send_id IS NULL');
        DB::statement('CREATE INDEX links_created_by_campaign_idx ON links (created_by, campaign, id DESC) WHERE send_id IS NULL');
        DB::statement('CREATE INDEX links_team_campaign_idx ON links (team_id, campaign, id DESC) WHERE send_id IS NULL');
        // 發送的收件人匯出依發送找連結
        DB::statement('CREATE INDEX links_send_id_idx ON links (send_id) WHERE send_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
