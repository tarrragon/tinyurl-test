-- 開發、壓測與 CI 共用的種子資料，照 docs/data-model.md 的規則產生；兩個後端讀同一個資料庫，資料只產生這一份。
-- 先清空全部業務表再重建，所以可以重複執行；資料庫的約束擋下任何不合規格的列。
--
-- 量由 session 參數調整，沒設時用括號裡的預設值（用法見 scripts/seed.sh）：
--   seed.links       一般連結的條數（300）
--   seed.sends       發送的次數（6）
--   seed.recipients  每次發送的收件人數（2000）
--
-- 亂數種子固定，同一組參數、同一個 PostgreSQL 主版本產生相同的資料。時間以執行當下的整點為基準往前推，
-- 點擊才會落在 90 天的保留期內，所以時間欄位隨執行的時間平移、其餘的值相同。

BEGIN;

TRUNCATE teams, users, refresh_tokens, sends, links, click_events, click_hourly, link_recipients,
         conversions, api_keys, campaign_hourly, recipient_hourly, analytics_dirty_hours,
         analytics_recompute_state RESTART IDENTITY CASCADE;

SELECT setseed(0.20261012);

CREATE TEMP TABLE seed_params ON COMMIT DROP AS
SELECT coalesce(nullif(current_setting('seed.links', true), '')::int, 300)       AS links,
       coalesce(nullif(current_setting('seed.sends', true), '')::int, 6)         AS sends,
       coalesce(nullif(current_setting('seed.recipients', true), '')::int, 2000) AS recipients,
       date_trunc('hour', now())                                                 AS now;

-- 短碼：id 乘上與 62 互質的常數、取 62^width 的餘數再轉 Base62。乘法在這個餘數下是一對一的，
-- 所以不同的 id 一定得到不同的短碼；一般連結 6 碼、收件人連結 7 碼（docs/system-design.md〈長度〉），長度不同也不會撞
CREATE FUNCTION pg_temp.code(id bigint, width int) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
  n bigint := (id * 1580030173) % power(62, width)::bigint;
  s text := '';
BEGIN
  FOR k IN 1 .. width LOOP
    s := substr('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', (n % 62)::int + 1, 1) || s;
    n := n / 62;
  END LOOP;
  RETURN s;
END
$$;

-- 兩個行銷團隊；每個團隊一位 marketing_lead、兩位 marketing；另有沒有團隊的 admin 與 engineer。
-- 密碼都是 password123（bcrypt cost 12，docs/auth-and-roles.md〈密碼〉），雜湊值固定，資料才每次相同
INSERT INTO teams (id, name, created_at)
SELECT id, name, (SELECT now FROM seed_params) FROM (VALUES (1, 'growth'), (2, 'crm')) t(id, name);

INSERT INTO users (id, email, password_hash, role, team_id, created_at)
SELECT id, email, '$2y$12$S8vo880Mo.pv.R5dxiyGCOQ19dPmS47UVerF6SMo3eCsWpv5PfTJy', role, team_id, (SELECT now FROM seed_params)
FROM (VALUES
  (1, 'admin@example.com',       'admin',          NULL),
  (2, 'engineer@example.com',    'engineer',       NULL),
  (3, 'growth-lead@example.com', 'marketing_lead', 1),
  (4, 'growth-a@example.com',    'marketing',      1),
  (5, 'growth-b@example.com',    'marketing',      1),
  (6, 'crm-lead@example.com',    'marketing_lead', 2),
  (7, 'crm-a@example.com',       'marketing',      2),
  (8, 'crm-b@example.com',       'marketing',      2)
) u(id, email, role, team_id);

-- 一般連結：建立者是行銷人員與 admin（admin 建立的沒有團隊），分散在三個管道與四個活動；
-- 建立時間在最近 150 天內，預設 90 天到期，所以有一部分已到期；約 5% 已停用
INSERT INTO links (id, code, original_url, channel, campaign, created_by, team_id, created_at, expires_at, disabled_at)
SELECT i, pg_temp.code(i, 6),
       format('https://shop.example.com/p/%s?utm_source=%s&utm_medium=%s&utm_campaign=%s', 100 + (r1 * 900)::int, ch, ch, cp),
       ch, cp, u.id, u.team_id, g.created_at, g.created_at + interval '90 days',
       CASE WHEN r2 < 0.05 THEN g.created_at + interval '1 day' END
FROM (
  SELECT i, random() AS r1, random() AS r2,
         (ARRAY['sms', 'email', 'ad'])[1 + floor(random() * 3)::int] AS ch,
         (ARRAY['autumn', 'double11', 'member_day', 'newsletter_w42'])[1 + floor(random() * 4)::int] AS cp,
         (ARRAY[1, 3, 4, 5, 6, 7, 8])[1 + floor(random() * 7)::int] AS creator,
         (SELECT now FROM seed_params) - random() * interval '150 days' AS created_at
  FROM generate_series(1, (SELECT links FROM seed_params)) i
) g JOIN users u ON u.id = g.creator;

-- 發送：輪流由兩位 marketing_lead 建立，管道是簡訊，網址、活動、管道只存在 sends
INSERT INTO sends (id, team_id, campaign, channel, original_url, created_by, created_at)
SELECT s, u.team_id, cp, 'sms',
       format('https://shop.example.com/sale/%s?utm_source=sms&utm_medium=sms&utm_campaign=%s&utm_content=send%s', cp, cp, s),
       u.id, (SELECT now FROM seed_params) - interval '1 day' - random() * interval '40 days'
FROM (SELECT s, (ARRAY['autumn', 'double11', 'member_day'])[1 + (s - 1) % 3] AS cp, CASE WHEN s % 2 = 1 THEN 3 ELSE 6 END AS lead
      FROM generate_series(1, (SELECT sends FROM seed_params)) s) g
JOIN users u ON u.id = g.lead;

-- 收件人連結：每次發送每位收件人一條 7 碼連結，網址、活動、管道為 NULL（docs/data-model.md〈links〉）
INSERT INTO links (id, code, send_id, created_by, team_id, created_at, expires_at)
SELECT g.link_id, pg_temp.code(g.link_id, 7), s.id, s.created_by, s.team_id, s.created_at, s.created_at + interval '90 days'
FROM sends s CROSS JOIN seed_params p
CROSS JOIN LATERAL (SELECT p.links + (s.id - 1) * p.recipients + r AS link_id FROM generate_series(1, p.recipients) r) g
ORDER BY g.link_id;

-- 每位收件人的 CRM 識別碼與發送當下的客群（每個客群各 1/4 的機率）
INSERT INTO link_recipients (link_id, recipient_ref, segments)
SELECT l.id, format('C%s', lpad((l.id - (SELECT links FROM seed_params))::text, 7, '0')),
       ARRAY(SELECT seg FROM unnest(ARRAY['vip', 'dormant_90d', 'new_member', 'app_user']) seg WHERE random() < 0.25 AND l.id > 0)
FROM links l WHERE l.send_id IS NOT NULL ORDER BY l.id;

-- 一位收件人提出個資刪除：只清空 recipient_ref，列與客群保留（docs/data-model.md〈link_recipients〉）
UPDATE link_recipients SET recipient_ref = NULL, erased_at = (SELECT now FROM seed_params)
WHERE link_id = (SELECT links FROM seed_params) + 7;

-- 點擊：只落在保留期（90 天）內、連結建立之後。一般連結是活動共用的，每條 0 到 40 次；收件人連結約一成被點、各 1 到 3 次。
-- 兩成的點擊來自預覽與掃描工具或沒有 User-Agent；落在到期之後的點擊 expired = true（轉到首頁）。
-- click_id 照 UUIDv7 的格式由點擊時間組成（前 48 bits 是毫秒時間戳），PostgreSQL 17 沒有內建的產生函式
INSERT INTO click_events (click_id, link_id, clicked_at, expired, ip, user_agent, referer)
SELECT (lpad(to_hex((extract(epoch FROM clicked_at) * 1000)::bigint), 12, '0') || '7'
        || lpad(to_hex((random() * 4095)::int), 3, '0') || '8'
        || lpad(to_hex((random() * 4095)::int), 3, '0') || lpad(to_hex((random() * 281474976710655)::bigint), 12, '0'))::uuid,
       link_id, clicked_at, clicked_at >= expires_at, ('203.0.113.' || (1 + (random() * 253)::int))::inet,
       CASE WHEN random() < 0.8
            THEN (ARRAY['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
                        'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36',
                        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36'])[1 + floor(random() * 3)::int]
            ELSE (ARRAY['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                        'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', NULL])[1 + floor(random() * 3)::int] END,
       NULL
FROM (
  SELECT l.id AS link_id, l.expires_at,
         date_trunc('milliseconds', greatest(l.created_at, p.now - interval '90 days')
           + random() * (p.now - greatest(l.created_at, p.now - interval '90 days'))) AS clicked_at
  FROM links l CROSS JOIN seed_params p
  CROSS JOIN LATERAL generate_series(1, CASE WHEN l.send_id IS NULL THEN floor(random() * 41)::int
                                             WHEN random() < 0.1 THEN 1 + floor(random() * 3)::int ELSE 0 END) n
  WHERE l.created_at < p.now
  ORDER BY l.id, n
) c;

-- 轉換：約 3% 的點擊帶來一筆訂單，成交在點擊後 7 天內、不晚於現在；沒有團隊的連結（admin 建立）對不到轉換，不產生
INSERT INTO conversions (team_id, click_id, order_ref, amount, converted_at)
SELECT team_id, click_id, format('ORD-%s', lpad(e_id::text, 8, '0')), round((30 + random() * 2970)::numeric, 2), converted_at
FROM (
  SELECT e.id AS e_id, e.click_id, l.team_id, e.clicked_at + random() * interval '7 days' AS converted_at, random() AS r
  FROM click_events e JOIN links l ON l.id = e.link_id
  WHERE l.team_id IS NOT NULL ORDER BY e.id
) c
WHERE r < 0.03 AND converted_at <= (SELECT now FROM seed_params);

-- 每個團隊一把伺服器金鑰；只存雜湊值
INSERT INTO api_keys (team_id, name, key_hash, created_by)
SELECT team_id, 'shop-backend', sha256(convert_to('seed-key-' || team_id, 'UTF8')), id
FROM users WHERE role = 'marketing_lead';

-- 指定了 id 的表，序號推到最大值之後，程式建立的下一列才不會撞號
SELECT setval(pg_get_serial_sequence('teams', 'id'), (SELECT max(id) FROM teams));
SELECT setval(pg_get_serial_sequence('users', 'id'), (SELECT max(id) FROM users));
SELECT setval(pg_get_serial_sequence('sends', 'id'), (SELECT max(id) FROM sends));
SELECT setval(pg_get_serial_sequence('links', 'id'), (SELECT max(id) FROM links));

COMMIT;
