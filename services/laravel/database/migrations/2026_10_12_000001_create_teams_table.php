<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈teams〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->text('name');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->unique('name', 'teams_name_key');
        });
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_name_check CHECK (char_length(name) BETWEEN 1 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
