<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 點擊事件、轉換與伺服器金鑰。點擊只產生在保留期（90 天）內；一部分是預覽或掃描工具的 User-Agent，
// 一部分落在連結到期之後（expired = true，轉到首頁）。轉換對到同一個團隊的點擊，成交時間在點擊後 7 天內。
class ClickSeeder extends Seeder
{
    private const AGENTS = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
        '',
    ];

    public function run(): void
    {
        $now = SeedSupport::now();
        $retentionStart = $now->copy()->subDays(90);

        $links = DB::table('links')->get(['id', 'send_id', 'team_id', 'created_at', 'expires_at']);
        $clicks = [];
        foreach ($links as $link) {
            // 收件人連結約一成被點；一般連結是活動共用的，點擊多
            $n = $link->send_id ? (mt_rand(1, 10) === 1 ? mt_rand(1, 3) : 0) : mt_rand(0, 40);
            $from = max(strtotime($link->created_at), $retentionStart->getTimestamp());
            for ($i = 0; $i < $n; $i++) {
                $at = $from + mt_rand(0, max(0, $now->getTimestamp() - $from));
                $clickedAt = \Illuminate\Support\Carbon::createFromTimestampUTC($at)->addMilliseconds(mt_rand(0, 999));
                $agent = self::AGENTS[mt_rand(1, 10) <= 8 ? mt_rand(0, 2) : mt_rand(3, 5)];
                $clicks[] = [
                    'click_id' => (string) Str::uuid7($clickedAt),
                    'link_id' => $link->id,
                    'clicked_at' => SeedSupport::ts($clickedAt),
                    'expired' => $at >= strtotime($link->expires_at),
                    'ip' => '203.0.113.'.mt_rand(1, 254),
                    'user_agent' => $agent === '' ? null : $agent,
                    'referer' => null,
                    'team_id' => $link->team_id,
                ];
            }
        }

        foreach (array_chunk($clicks, 1000) as $chunk) {
            DB::table('click_events')->insert(array_map(fn ($c) => array_diff_key($c, ['team_id' => 0]), $chunk));
        }

        // 約 3% 的點擊帶來一筆訂單；沒有團隊的連結（admin 建立）對不到轉換，不產生
        $conversions = [];
        foreach ($clicks as $k => $c) {
            if ($c['team_id'] === null || mt_rand(1, 33) !== 1) {
                continue;
            }
            $convertedAt = \Illuminate\Support\Carbon::parse($c['clicked_at'])->addMinutes(mt_rand(1, 7 * 24 * 60));
            if ($convertedAt->greaterThan($now)) {
                continue;
            }
            $conversions[] = [
                'team_id' => $c['team_id'],
                'click_id' => $c['click_id'],
                'order_ref' => 'ORD-'.str_pad((string) $k, 8, '0', STR_PAD_LEFT),
                'amount' => mt_rand(300, 30000) / 10,
                'converted_at' => SeedSupport::ts($convertedAt),
            ];
        }
        foreach (array_chunk($conversions, 1000) as $chunk) {
            DB::table('conversions')->insert($chunk);
        }

        // 每個團隊一把伺服器金鑰；只存雜湊值，原文在種子資料裡丟掉
        foreach (DB::table('users')->where('role', 'marketing_lead')->get(['id', 'team_id']) as $lead) {
            DB::table('api_keys')->insert([
                'team_id' => $lead->team_id,
                'name' => 'shop-backend',
                'key_hash' => DB::raw("'\\x".hash('sha256', Str::random(40))."'::bytea"),
                'created_by' => $lead->id,
            ]);
        }
    }
}
