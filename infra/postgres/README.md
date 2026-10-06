# infra/postgres

主資料庫 PostgreSQL，保存連結、使用者、角色、點擊事件、收件人對應與轉換。

## 預定的資料

資料模型的 ER 圖與設計理由見 [docs/system-design.md](../../docs/system-design.md) 的〈資料模型〉，每張表的 SQL（欄位型別、約束與索引的名稱）見 [docs/data-model.md](../../docs/data-model.md)。摘要：

- `teams`、`users`、`refresh_tokens`：登入與權限，角色放在 `users.role`。
- `links`：短碼（唯一索引，兩個後端產生的短碼靠它判斷重複）、原始網址、管道、活動、建立者、到期與停用時間。
- `click_events`：每一次轉址一筆。第一版不分割，階段八的實驗改成依時間做 [partition](https://www.postgresql.org/docs/current/ddl-partitioning.html)，超過保留期的分割區整個刪除。
- `click_hourly`：每條連結每小時（UTC）的點擊數，後台報表讀這張，依查詢的時區組成日。
- `sends`、`link_recipients`：每個收件人一條連結的發送，收件人只存 CRM 的識別碼與發送當下的客群。
- `conversions`、`api_keys`：電商用伺服器金鑰回報的轉換。
- `campaign_hourly`、`recipient_hourly`、`analytics_dirty_hours`：行銷分析的每小時彙總與待重算的小時。

## 誰管 schema

Go 與 Laravel 各自維護一套完整的 migration，兩套都能在空資料庫上建出全部業務表：Laravel 的放在 `services/laravel/database/migrations/`，Go 用 [Atlas](https://atlasgo.io/)、放在 `services/go/migrations/`。這是練習專案，兩種工具都要實際操作過，所以兩套都寫。`initdb/` 只放資料庫第一次建立時要跑的腳本（建 database、建使用者、裝 extension），不放業務 schema。

### 同一個資料庫只由一套 migration 執行

每套工具用自己的紀錄表記下跑過哪些 migration：Laravel 是 `migrations`，Atlas 是 `atlas_schema_revisions`。兩張紀錄表互相看不到，所以兩套都對同一個資料庫執行時，後跑的那一套會再建一次已經存在的表而失敗；改寫成 `IF NOT EXISTS` 跳過的話，兩邊欄位定義的差異（型別、預設值、索引名稱）會留在資料庫裡，而且不會有任何訊息。

compose 環境用根目錄 `.env` 的 `SCHEMA_OWNER`（`laravel` 或 `go`）指定由哪一套執行，另一個後端啟動時不跑 migration，只連線使用。Go 的執行映像檔是 distroless、裡面沒有 Atlas，所以 Go 那一套由 Atlas 官方映像檔以一次性的 compose 服務執行。

### 兩套建出的 schema 由 CI 比對

兩套 migration 是分開寫的兩份 schema 定義。CI 開兩個空資料庫，各跑一套，再比對兩邊的 schema（`pg_dump --schema-only` 或 `atlas schema diff`）；比對結果不一致，代表其中一套寫錯了。這跟契約測試讓兩個後端互相驗證 API 是同一個做法。比對時排除兩類表：兩套工具各自的紀錄表，以及只有 Laravel 框架自己用的表（見下一節）。

### 業務表由這兩套 migration 自己定義

使用者、團隊與 refresh token 的表照 [docs/system-design.md](../../docs/system-design.md)〈資料模型〉的欄位，在兩套 migration 裡各寫一次，不沿用框架附帶的表。

Laravel 建立專案時附帶三個預設 migration，寫第一版 migration 時這樣處理：

- `create_users_table`：刪掉。它建的 `users` 與業務的 `users` 同名，留著兩套就建不出相同的 schema；同一個檔案裡的 `password_reset_tokens` 與 `sessions` 也用不到，因為 API 用 JWT 登入（見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)）。Laravel 的 `User` model 仍然對應 `users` 表，但欄位要改成業務表的欄位，例如密碼欄位是 `password_hash`，不是預設的 `password`。
- `create_cache_table`、`create_jobs_table`：要不要留，看 Laravel 的快取與佇列放在哪裡。`services/laravel/.env.example` 目前的 `CACHE_STORE` 與 `QUEUE_CONNECTION` 都是 `database`，改成 Valkey 就用不到這兩張表；留下來的話，它們只寫在 Laravel 那一套裡，CI 比對時排除。

### 換手與從現有資料庫產生 migration

把 `SCHEMA_OWNER` 換到另一套時，接手的工具要先認得資料庫目前的狀態，不能從第一個 migration 重跑。做法是基準點（baseline）：把現況記成已經套用到某一版，不執行 DDL。Atlas 用 `atlas migrate apply --baseline <版本>`；Laravel 沒有對應的指令，要在 `migrations` 表寫入那幾個 migration 的紀錄。之後的新 migration 由接手的那一套執行。

資料庫被直接改過、沒有經過 migration 時，用反向產生找出差異：Atlas 的 `atlas schema inspect` 讀出資料庫現況，`atlas migrate diff` 把現況和 migration 目錄比對、產生差異 migration；Laravel 的 `php artisan schema:dump` 把現況匯出成 SQL 檔，要產生成 migration 類別得另裝套件。Go 這邊選 Atlas 就是為了這個能力：golang-migrate 與 goose 只照順序執行 SQL 檔，不讀資料庫的現況。

## Docker

直接使用官方 `postgres` 映像檔，不另寫 Dockerfile：

- `initdb/` 掛載到 `/docker-entrypoint-initdb.d/`，資料目錄是空的時候才會執行。
- 資料存在 named volume，容器重建不會遺失。
- 帳號密碼由 `.env` 提供（見根目錄的 `.env.example`）。
