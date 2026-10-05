# services/laravel

Laravel 版的短網址 API。與 [services/go](../go/README.md) 實作同一份 API 規格、連同一個資料庫與快取，負責的端點相同：轉址 `GET /{code}`、管理 API `/api/links`、統計 `/api/stats/...` 與健康檢查 `/healthz`。

## 與 Go 版要對齊的地方

兩個後端共用資料，所以下面幾件事要先約定好，不能各自用框架預設值：

- **資料庫 schema 由誰管**：Laravel migration 與 Go 的 migration 工具只能選一個當權威，另一個只讀不改。
- **快取的 key 格式與序列化**：Laravel 的 cache 預設會加前綴並用 PHP 序列化，Go 讀不懂；共用的 key 要用 JSON 與固定前綴。
- **登入憑證**：見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)。
- **壓測條件**：與 Go 版比較時用同一套情境與同樣的容器資源上限，見 [loadtest](../../loadtest/README.md)；PHP-FPM 的 worker 數會直接限制同時處理的請求數，要記錄在每次結果裡。
- **短碼產生規則**：兩邊都用密碼學安全的亂數產生 Base62 短碼（一般 6 碼、每個收件人一條的連結 7 碼），寫入時靠 PostgreSQL 的唯一索引擋重複，重複就重試；選擇理由見 [docs/system-design.md](../../docs/system-design.md) 的〈短碼設計〉。

## Docker

`php:8.5-fpm-alpine` 加上 `pdo_pgsql`、`redis` 擴充；OPcache 從 PHP 8.5 起是 PHP 本體的一部分，不必另外安裝。PHP-FPM 只聽 FastCGI（port `9000`），HTTP 由 [infra/nginx](../../infra/nginx/README.md) 轉成 FastCGI 送進來。`composer install` 放在獨立階段，`composer.json` 沒變時可以沿用快取。
