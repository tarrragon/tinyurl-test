# services/laravel

Laravel 版的短網址 API。與 [services/go](../go/README.md) 實作同一份 API 規格、連同一個資料庫與快取，負責的端點相同：轉址 `GET /{code}`、管理 API `/api/links`、統計 `/api/stats/...` 與健康檢查 `/-/healthz`。

## 與 Go 版要對齊的地方

兩個後端共用資料，所以下面幾件事要先約定好，不能各自用框架預設值：

- **資料庫 schema**：兩邊各維護一套完整的 migration，同一個資料庫只由根目錄 `.env` 的 `SCHEMA_OWNER` 指定的那一套執行，CI 比對兩套建出的 schema。業務的 `users` 表由兩套 migration 自己定義，Laravel 預設的 `create_users_table` 要刪掉。細節見 [infra/postgres](../../infra/postgres/README.md)〈誰管 schema〉。
- **快取的 key 格式與序列化**：Laravel 的 cache 預設會加前綴並用 PHP 序列化，Go 讀不懂。共用的 key 不經過 Laravel 的 cache，直接用 Redis 連線讀寫 JSON，格式見 [docs/data-model.md](../../docs/data-model.md)〈共用的快取與 stream 格式〉。Laravel 的 Redis 連線本身也會替每個 key 加前綴（`config/database.php` 的 `REDIS_PREFIX`，預設由 `APP_NAME` 組出），要設成空字串，否則 Go 寫的 `link:{code}` 在 Laravel 這邊查不到。
- **登入憑證**：見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)。
- **壓測條件**：與 Go 版比較時用同一套情境與同樣的容器資源上限，見 [loadtest](../../loadtest/README.md)；PHP-FPM 的 worker 數會直接限制同時處理的請求數，要記錄在每次結果裡。Laravel 的容器裡另外跑著一個 Nginx，它用的 CPU 與記憶體也算在同一個資源上限裡，Go 的容器沒有這一層，比較數字時要把這個差異寫進結果。
- **短碼產生規則**：兩邊都用密碼學安全的亂數產生 Base62 短碼（一般 6 碼、每個收件人一條的連結 7 碼），寫入時靠 PostgreSQL 的唯一索引擋重複，重複就重試；選擇理由見 [docs/system-design.md](../../docs/system-design.md) 的〈短碼設計〉。

## Docker

`php:8.5-fpm-alpine` 加上 `pdo_pgsql`、`redis` 擴充；OPcache 從 PHP 8.5 起是 PHP 本體的一部分，不必另外安裝。`composer install` 放在獨立階段，`composer.json` 沒變時可以沿用快取。

### 容器內同時跑 Nginx 與 PHP-FPM

PHP-FPM 只講 FastCGI，Go 講 HTTP。入口的 [infra/nginx](../../infra/nginx/README.md) 要把兩個後端放進同一個 `upstream` 輪流分配，而一個 upstream 只能用一種協定轉送（`proxy_pass` 轉 HTTP、`fastcgi_pass` 轉 FastCGI），所以 Laravel 的容器自己對外講 HTTP：

- 容器內的 Nginx 聽 `8080`（與 Go 版相同），把請求轉成 FastCGI 交給同一個容器裡的 PHP-FPM。設定在 `docker/nginx.conf`。
- PHP-FPM 只聽 `127.0.0.1:9000`（`docker/php-fpm-overrides.conf`），compose 網路上的其他容器連不到 FastCGI。
- `docker/entrypoint.sh` 同時啟動兩個程序，任一個結束就停掉另一個並讓容器結束，交給 compose 處理；只剩一半在跑的容器會讓請求失敗卻看不出原因。
- `docker stop` 時，entrypoint 把停機訊號轉成 SIGQUIT 交給兩個程序（兩者的 graceful stop）。php-fpm 官方映像檔把停機訊號設成 SIGQUIT（`STOPSIGNAL`），entrypoint 要接的是 QUIT，不只 TERM。
- PHP-FPM 的 graceful stop 與 reload 要等多久由 `process_control_timeout` 決定，預設 0 是不等、進行中的請求直接拿到 502，所以 `docker/php-fpm-overrides.conf` 設成 8 秒，比 `docker stop` 預設的 10 秒逾時短。
- entrypoint 用 bash 執行：Alpine 的 busybox sh（1.37）的 `wait -n` 在子程序被訊號殺掉時（`kill -9`、OOM）不會返回，偵測不到崩潰。
- 每個回應都由 `App\Http\Middleware\AddBackendHeader` 加上 `X-Backend: laravel`。
