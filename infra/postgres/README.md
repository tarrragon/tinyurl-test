# infra/postgres

主資料庫 PostgreSQL，保存連結、使用者、角色與點擊事件。

## 預定的資料

完整的資料模型（ER 圖、主鍵、為什麼點擊數不放在計數欄位）見 [docs/system-design.md](../../docs/system-design.md) 的〈資料模型〉。摘要：

- `teams`、`users`、`refresh_tokens`：登入與權限，角色放在 `users.role`。
- `links`：短碼（唯一索引，兩個後端產生的短碼靠它判斷重複）、原始網址、管道、活動、建立者、到期與停用時間。
- `click_events`：每一次轉址一筆，依時間做 [partition](https://www.postgresql.org/docs/current/ddl-partitioning.html)，超過保留期的分割區整個刪除。
- `click_daily`：每日彙總，後台報表讀這張。

## 誰管 schema

Go 與 Laravel 共用這個資料庫，migration 只能由一邊管理，見 [services/laravel/README.md](../../services/laravel/README.md)。`initdb/` 只放資料庫第一次建立時要跑的腳本（建 database、建使用者、裝 extension），不放業務 schema。

## Docker

直接使用官方 `postgres` 映像檔，不另寫 Dockerfile：

- `initdb/` 掛載到 `/docker-entrypoint-initdb.d/`，資料目錄是空的時候才會執行。
- 資料存在 named volume，容器重建不會遺失。
- 帳號密碼由 `.env` 提供（見根目錄的 `.env.example`）。
