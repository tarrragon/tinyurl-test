<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 欄位、約束與索引的名稱照 docs/data-model.md〈users〉；Schema Builder 寫不出的 CHECK、
// 部分索引與遞減索引用 DB::statement，名稱與規格的 SQL 逐字相同，CI 才比對得過。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->text('email');
            $table->text('password_hash');
            $table->text('role');
            $table->foreignId('team_id')->nullable()->constrained('teams', 'id', 'users_team_id_fkey');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->timestampTz('disabled_at')->nullable();
            $table->unique('email', 'users_email_key');
            $table->index('team_id', 'users_team_id_idx');
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_check CHECK (email = lower(email) AND char_length(email) <= 254)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('marketing', 'marketing_lead', 'engineer', 'admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_check CHECK (role NOT IN ('marketing', 'marketing_lead') OR team_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
