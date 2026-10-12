<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// 一般連結：行銷人員各自建立，分散在三個管道與幾個活動；涵蓋已到期、已停用與 admin 建立的沒有團隊的連結。
class LinkSeeder extends Seeder
{
    public function run(): void
    {
        $now = SeedSupport::now();
        $count = (int) env('SEED_LINKS', 300);
        $creators = DB::table('users')->whereIn('role', ['marketing', 'marketing_lead', 'admin'])->get(['id', 'team_id', 'role']);
        $campaigns = ['autumn', 'double11', 'member_day', 'newsletter_w42'];
        $channels = ['sms', 'email', 'ad'];

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $creator = $creators[mt_rand(0, count($creators) - 1)];
            $createdAt = $now->copy()->subMinutes(mt_rand(0, 150 * 24 * 60));
            $campaign = $campaigns[mt_rand(0, count($campaigns) - 1)];
            $channel = $channels[mt_rand(0, 2)];
            $rows[] = [
                'code' => SeedSupport::code(6),
                'original_url' => "https://shop.example.com/p/".mt_rand(100, 999)."?utm_source={$channel}&utm_medium={$channel}&utm_campaign={$campaign}",
                'channel' => $channel,
                'campaign' => $campaign,
                'created_by' => $creator->id,
                'team_id' => $creator->team_id,   // admin 建立的連結沒有團隊
                'created_at' => SeedSupport::ts($createdAt),
                'expires_at' => SeedSupport::ts($createdAt->copy()->addDays(90)),  // 預設 90 天；150 天前建立的已到期
                'disabled_at' => mt_rand(1, 20) === 1 ? SeedSupport::ts($createdAt->copy()->addDays(1)) : null,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('links')->insert($chunk);
        }
    }
}
