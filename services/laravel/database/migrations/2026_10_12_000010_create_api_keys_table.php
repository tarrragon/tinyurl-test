<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈api_keys〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->foreignId('team_id')->constrained('teams', 'id', 'api_keys_team_id_fkey');
            $table->text('name');
            $table->binary('key_hash');
            $table->foreignId('created_by')->constrained('users', 'id', 'api_keys_created_by_fkey');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->timestampTz('revoked_at')->nullable();
            $table->unique('key_hash', 'api_keys_key_hash_key');
        });
        DB::statement('ALTER TABLE api_keys ADD CONSTRAINT api_keys_name_check CHECK (char_length(name) BETWEEN 1 AND 50)');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
