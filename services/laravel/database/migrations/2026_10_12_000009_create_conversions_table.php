<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈conversions〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->foreignId('team_id')->constrained('teams', 'id', 'conversions_team_id_fkey');
            $table->uuid('click_id');
            $table->text('order_ref');
            $table->decimal('amount', 12, 2);
            $table->timestampTz('converted_at');
            $table->timestampTz('received_at')->default(DB::raw('now()'));
            $table->unique(['team_id', 'order_ref'], 'conversions_team_order_key');
            $table->index('click_id', 'conversions_click_id_idx');
            $table->index('converted_at', 'conversions_converted_at_idx');
        });
        DB::statement('ALTER TABLE conversions ADD CONSTRAINT conversions_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE conversions ADD CONSTRAINT conversions_order_ref_check CHECK (char_length(order_ref) BETWEEN 1 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};
