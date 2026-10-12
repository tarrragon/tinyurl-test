<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈link_recipients〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_recipients', function (Blueprint $table) {
            $table->foreignId('link_id')->constrained('links', 'id', 'link_recipients_link_id_fkey');
            $table->text('recipient_ref')->nullable();
            $table->primary('link_id');
            $table->index('recipient_ref', 'link_recipients_recipient_ref_idx');
        });
        // text[] 沒有對應的 Schema Builder 型別；欄位順序照規格，所以 erased_at 在它之後才加
        DB::statement("ALTER TABLE link_recipients ADD COLUMN segments text[] NOT NULL DEFAULT '{}'");
        Schema::table('link_recipients', function (Blueprint $table) {
            $table->timestampTz('erased_at')->nullable();
        });
        DB::statement("ALTER TABLE link_recipients ADD CONSTRAINT link_recipients_ref_check CHECK (recipient_ref ~ '^[A-Za-z0-9_-]{1,100}$')");
        DB::statement('ALTER TABLE link_recipients ADD CONSTRAINT link_recipients_erased_check CHECK ((recipient_ref IS NULL) = (erased_at IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('link_recipients');
    }
};
