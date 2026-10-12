-- 驗證 schema 的約束照 docs/data-model.md 運作：每一筆違規的寫入都要被指名的約束擋下，合規的寫入要成功。
-- 對任何一套 migration 建出的資料庫都能跑（空的或已有種子資料的都可以）；自己建立需要的列，結束時全部回滾。
-- 有任何一條不符時以錯誤結束，psql 帶 -v ON_ERROR_STOP=1 時結束碼不是 0。用法見 scripts/check-constraints.sh。

BEGIN;

DO $$
DECLARE
  -- 每一列：說明、SQL、預期被哪一個約束擋下（NULL 代表預期寫入成功）
  cases text[][] := ARRAY[
    ['一般連結三個欄位齊全',         $q$INSERT INTO links (code, original_url, channel, campaign, created_by, team_id, expires_at) VALUES ('Vr0001', 'https://shop.example.com/a', 'sms', 'v', 900001, 900001, now())$q$, NULL],
    ['發送連結不帶網址與活動',       $q$INSERT INTO links (code, send_id, created_by, team_id, expires_at) VALUES ('Vr00001', 900001, 900001, 900001, now())$q$, NULL],
    ['發送連結自己帶網址',           $q$INSERT INTO links (code, send_id, original_url, created_by, team_id, expires_at) VALUES ('Vr00002', 900001, 'https://shop.example.com/p', 900001, 900001, now())$q$, NULL],
    ['發送連結帶活動',               $q$INSERT INTO links (code, send_id, campaign, created_by, team_id, expires_at) VALUES ('Vr00003', 900001, 'v', 900001, 900001, now())$q$, 'links_send_fields_check'],
    ['一般連結沒有網址',             $q$INSERT INTO links (code, channel, campaign, created_by, expires_at) VALUES ('Vr0002', 'sms', 'v', 900001, now())$q$, 'links_send_fields_check'],
    ['短碼含連字號',                 $q$INSERT INTO links (code, original_url, channel, campaign, created_by, expires_at) VALUES ('Vr-003', 'https://a', 'sms', 'v', 900001, now())$q$, 'links_code_check'],
    ['短碼只有 5 碼',                $q$INSERT INTO links (code, original_url, channel, campaign, created_by, expires_at) VALUES ('Vr004', 'https://a', 'sms', 'v', 900001, now())$q$, 'links_code_check'],
    ['短碼重複',                     $q$INSERT INTO links (code, original_url, channel, campaign, created_by, expires_at) VALUES ('Vr0001', 'https://a', 'sms', 'v', 900001, now())$q$, 'links_code_key'],
    ['短碼只差大小寫可以並存',       $q$INSERT INTO links (code, original_url, channel, campaign, created_by, expires_at) VALUES ('vR0001', 'https://a', 'sms', 'v', 900001, now())$q$, NULL],
    ['網址超過 2048 字元',           $q$INSERT INTO links (code, original_url, channel, campaign, created_by, expires_at) VALUES ('Vr0005', repeat('a', 2049), 'sms', 'v', 900001, now())$q$, 'links_url_check'],
    ['連結的管道不在清單內',         $q$INSERT INTO links (code, original_url, channel, campaign, created_by, expires_at) VALUES ('Vr0006', 'https://a', 'line', 'v', 900001, now())$q$, 'links_channel_check'],
    ['行銷帳號沒有團隊',             $q$INSERT INTO users (email, password_hash, role) VALUES ('v-m@example.com', 'h', 'marketing')$q$, 'users_team_check'],
    ['engineer 沒有團隊',            $q$INSERT INTO users (email, password_hash, role) VALUES ('v-e@example.com', 'h', 'engineer')$q$, NULL],
    ['email 有大寫',                 $q$INSERT INTO users (email, password_hash, role) VALUES ('V-A@example.com', 'h', 'admin')$q$, 'users_email_check'],
    ['角色不在清單內',               $q$INSERT INTO users (email, password_hash, role) VALUES ('v-x@example.com', 'h', 'owner')$q$, 'users_role_check'],
    ['團隊名稱重複',                 $q$INSERT INTO teams (name) VALUES ('verify-team')$q$, 'teams_name_key'],
    ['發送的管道不在清單內',         $q$INSERT INTO sends (team_id, campaign, channel, original_url, created_by) VALUES (900001, 'v', 'line', 'https://a', 900001)$q$, 'sends_channel_check'],
    ['收件人識別碼含 @',             $q$INSERT INTO link_recipients (link_id, recipient_ref) SELECT id, 'a@b' FROM links WHERE code = 'Vr00002'$q$, 'link_recipients_ref_check'],
    ['清空收件人但沒記時間',         $q$UPDATE link_recipients SET recipient_ref = NULL WHERE link_id = (SELECT id FROM links WHERE code = 'Vr00001')$q$, 'link_recipients_erased_check'],
    ['清空收件人並記下時間',         $q$UPDATE link_recipients SET recipient_ref = NULL, erased_at = now() WHERE link_id = (SELECT id FROM links WHERE code = 'Vr00001')$q$, NULL],
    ['點擊識別碼重複',               $q$INSERT INTO click_events (click_id, link_id, clicked_at, expired) SELECT '01900000-0000-7000-8000-000000000001', id, now(), false FROM links WHERE code = 'Vr0001'$q$, 'click_events_click_id_key'],
    ['同團隊訂單編號重複',           $q$INSERT INTO conversions (team_id, click_id, order_ref, amount, converted_at) VALUES (900001, gen_random_uuid(), 'V-ORDER', 1, now())$q$, 'conversions_team_order_key'],
    ['不同團隊可以用同一個訂單編號', $q$INSERT INTO conversions (team_id, click_id, order_ref, amount, converted_at) VALUES (900002, gen_random_uuid(), 'V-ORDER', 1, now())$q$, NULL],
    ['轉換金額為負',                 $q$INSERT INTO conversions (team_id, click_id, order_ref, amount, converted_at) VALUES (900001, gen_random_uuid(), 'V-NEG', -1, now())$q$, 'conversions_amount_check'],
    ['每小時點擊數為負',             $q$INSERT INTO click_hourly (link_id, hour, clicks) SELECT id, date_trunc('hour', now()), -1 FROM links WHERE code = 'Vr0001'$q$, 'click_hourly_clicks_check']
  ];
  got text;
  failures int := 0;
BEGIN
  -- 驗證用的前置資料，id 取 900001 起，不和種子資料撞號
  INSERT INTO teams (id, name) VALUES (900001, 'verify-team'), (900002, 'verify-team-2');
  INSERT INTO users (id, email, password_hash, role, team_id) VALUES (900001, 'verify-lead@example.com', 'h', 'marketing_lead', 900001);
  INSERT INTO sends (id, team_id, campaign, channel, original_url, created_by) VALUES (900001, 900001, 'v', 'sms', 'https://shop.example.com/s', 900001);
  INSERT INTO links (id, code, original_url, channel, campaign, created_by, team_id, expires_at)
    VALUES (900001, 'Vq0001', 'https://shop.example.com/q', 'sms', 'v', 900001, 900001, now());
  INSERT INTO click_events (click_id, link_id, clicked_at, expired)
    VALUES ('01900000-0000-7000-8000-000000000001', 900001, now(), false);
  INSERT INTO conversions (team_id, click_id, order_ref, amount, converted_at) VALUES (900001, gen_random_uuid(), 'V-ORDER', 1, now());

  FOR i IN 1 .. array_length(cases, 1) LOOP
    got := NULL;
    BEGIN
      EXECUTE cases[i][2];
      -- 收件人對應要等發送連結建立之後才寫得進去
      IF cases[i][1] = '發送連結不帶網址與活動' THEN
        INSERT INTO link_recipients (link_id, recipient_ref) SELECT id, 'V0001' FROM links WHERE code = 'Vr00001';
      END IF;
    EXCEPTION WHEN integrity_constraint_violation THEN
      GET STACKED DIAGNOSTICS got = CONSTRAINT_NAME;
    END;
    IF got IS NOT DISTINCT FROM cases[i][3] THEN
      RAISE NOTICE 'ok    %（%）', cases[i][1], coalesce(got, '寫入成功');
    ELSE
      failures := failures + 1;
      RAISE NOTICE 'FAIL  %：預期 %，實際 %', cases[i][1], coalesce(cases[i][3], '寫入成功'), coalesce(got, '寫入成功');
    END IF;
  END LOOP;

  IF failures > 0 THEN
    RAISE EXCEPTION '% 條約束驗證不符', failures;
  END IF;
END $$;

ROLLBACK;
