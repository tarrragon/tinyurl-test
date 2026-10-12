<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// 兩個行銷團隊；每個團隊一位 marketing_lead、兩位 marketing；另有沒有團隊的 engineer 與 admin。
// 所有帳號的密碼都是 password123（bcrypt cost 12，docs/auth-and-roles.md〈密碼〉）。
class TeamUserSeeder extends Seeder
{
    public function run(): void
    {
        $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
        $now = SeedSupport::ts(SeedSupport::now());

        DB::table('teams')->insert([
            ['id' => 1, 'name' => 'growth', 'created_at' => $now],
            ['id' => 2, 'name' => 'crm', 'created_at' => $now],
        ]);

        $users = [
            ['admin@example.com', 'admin', null],
            ['engineer@example.com', 'engineer', null],
        ];
        foreach ([1 => 'growth', 2 => 'crm'] as $teamId => $team) {
            $users[] = ["{$team}-lead@example.com", 'marketing_lead', $teamId];
            $users[] = ["{$team}-a@example.com", 'marketing', $teamId];
            $users[] = ["{$team}-b@example.com", 'marketing', $teamId];
        }

        DB::table('users')->insert(array_map(fn ($u) => [
            'email' => $u[0],
            'password_hash' => $hash,
            'role' => $u[1],
            'team_id' => $u[2],
            'created_at' => $now,
        ], $users));

        // 指定了 id 的列不會推進 identity 的序號，之後由程式建立的團隊會從 1 開始而撞號
        DB::statement("SELECT setval(pg_get_serial_sequence('teams', 'id'), (SELECT max(id) FROM teams))");
    }
}
