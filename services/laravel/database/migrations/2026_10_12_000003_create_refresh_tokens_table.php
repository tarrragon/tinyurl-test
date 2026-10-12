<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈refresh_tokens〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->foreignId('user_id')->constrained('users', 'id', 'refresh_tokens_user_id_fkey');
            $table->binary('token_hash');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->timestampTz('expires_at');
            $table->unique('token_hash', 'refresh_tokens_token_hash_key');
            $table->index('user_id', 'refresh_tokens_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
