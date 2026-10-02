# infra/postgres

主資料庫 PostgreSQL，保存連結、使用者、角色與點擊事件。

## 預定的資料

- `users`、`roles`：登入與權限。
- `links`：短碼、原始網址、管道、活動、建立者、到期時間、狀態。短碼加唯一索引。
- `click_events`：每一次轉址一筆（時間、短碼、來源、User-Agent）。這張表成長最快，可以依時間做 [partition](https://www.postgresql.org/docs/current/ddl-partitioning.html)，舊資料彙總成每日統計後刪除。

## 誰管 schema

Go 與 Laravel 共用這個資料庫，migration 只能由一邊管理，見 [services/laravel/README.md](../../services/laravel/README.md)。`initdb/` 只放資料庫第一次建立時要跑的腳本（建 database、建使用者、裝 extension），不放業務 schema。

## Docker

直接使用官方 `postgres` 映像檔，不另寫 Dockerfile：

- `initdb/` 掛載到 `/docker-entrypoint-initdb.d/`，資料目錄是空的時候才會執行。
- 資料存在 named volume，容器重建不會遺失。
- 帳號密碼由 `.env` 提供（見根目錄的 `.env.example`）。
