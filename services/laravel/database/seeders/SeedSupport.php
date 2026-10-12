<?php

namespace Database\Seeders;

use Illuminate\Support\Carbon;

// 各 seeder 共用的產生規則
final class SeedSupport
{
    private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** @var array<string, true> 已發出的短碼，種子資料裡不重複，不必靠唯一約束重試 */
    private static array $codes = [];

    // 一般連結 6 碼、收件人連結 7 碼（docs/system-design.md〈長度〉）
    public static function code(int $length): string
    {
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= self::BASE62[mt_rand(0, 61)];
            }
        } while (isset(self::$codes[$code]));
        self::$codes[$code] = true;

        return $code;
    }

    // 產生資料時固定的「現在」，整點對齊，資料與 UTC 小時的對應比較好檢查
    public static function now(): Carbon
    {
        static $now;

        return $now ??= Carbon::now('UTC')->startOfHour();
    }

    public static function ts(Carbon $t): string
    {
        return $t->format('Y-m-d H:i:s.uP');
    }
}
