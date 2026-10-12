<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// 發送：每次發送建立 sends 一列、每位收件人一條 7 碼連結與一列 link_recipients。
// 收件人連結的網址、活動、管道只存在 sends，連結上這三欄是 NULL（docs/data-model.md〈links〉）。
// SEED_SENDS 次發送、每次 SEED_RECIPIENTS 位收件人；預設 6 × 2,000。
class SendSeeder extends Seeder
{
    public function run(): void
    {
        $now = SeedSupport::now();
        $sends = (int) env('SEED_SENDS', 6);
        $recipients = (int) env('SEED_RECIPIENTS', 2000);
        $leads = DB::table('users')->where('role', 'marketing_lead')->get(['id', 'team_id']);
        $segments = ['vip', 'dormant_90d', 'new_member', 'app_user'];

        for ($s = 0; $s < $sends; $s++) {
            $lead = $leads[$s % count($leads)];
            $createdAt = $now->copy()->subDays(mt_rand(1, 40))->subMinutes(mt_rand(0, 1440));
            $campaign = ['autumn', 'double11', 'member_day'][$s % 3];

            $sendId = DB::table('sends')->insertGetId([
                'team_id' => $lead->team_id,
                'campaign' => $campaign,
                'channel' => 'sms',
                'original_url' => "https://shop.example.com/sale/{$campaign}?utm_source=sms&utm_medium=sms&utm_campaign={$campaign}&utm_content=send{$s}",
                'created_by' => $lead->id,
                'created_at' => SeedSupport::ts($createdAt),
            ]);

            // 先寫連結、取回 id，再寫收件人對應；和建立發送的程式同樣的順序（docs/data-model.md〈短碼重複的判斷〉）
            for ($offset = 0; $offset < $recipients; $offset += 1000) {
                $n = min(1000, $recipients - $offset);
                $links = [];
                for ($i = 0; $i < $n; $i++) {
                    $links[] = [
                        'code' => SeedSupport::code(7),
                        'send_id' => $sendId,
                        'created_by' => $lead->id,
                        'team_id' => $lead->team_id,
                        'created_at' => SeedSupport::ts($createdAt),
                        'expires_at' => SeedSupport::ts($createdAt->copy()->addDays(90)),
                    ];
                }
                // 建立發送的寫法：ON CONFLICT (code) DO NOTHING RETURNING id, code，撞號的短碼不在回傳裡、
                // 由程式重新產生再寫（種子資料的短碼本身不重複，所以不會發生）。回傳的順序沒有保證，依短碼對回
                $ids = DB::table('links')->insertOrIgnoreReturning($links, ['id', 'code'], 'code')->pluck('id', 'code');

                $map = [];
                foreach (array_column($links, 'code') as $k => $code) {
                    $linkId = $ids[$code];
                    $picked = array_filter($segments, fn () => mt_rand(1, 4) === 1);
                    $map[] = [
                        'link_id' => $linkId,
                        'recipient_ref' => sprintf('C%07d', $s * $recipients + $offset + $k),
                        'segments' => '{'.implode(',', $picked).'}',
                    ];
                }
                DB::table('link_recipients')->insert($map);
            }
        }

        // 一位收件人提出個資刪除：只清空 recipient_ref，列與客群保留（docs/data-model.md〈link_recipients〉）
        DB::table('link_recipients')->where('recipient_ref', 'C0000007')
            ->update(['recipient_ref' => null, 'erased_at' => SeedSupport::ts($now)]);
    }
}
