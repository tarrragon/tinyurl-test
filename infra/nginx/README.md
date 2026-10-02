# infra/nginx

所有外部流量的入口：反向代理、負載平衡、流量管理。

## 路由（預定）

| 路徑             | 轉給                          |
| ---------------- | ----------------------------- |
| `/`              | `web/portal` 前台靜態檔       |
| `/admin/`        | `web/admin` 後台靜態檔        |
| `/api/`          | 後端 upstream                 |
| `/{code}`        | 後端 upstream（轉址）         |

## 兩個後端放在同一個 upstream

Go 與 Laravel 是同一份 API 規格的兩種實作，放在同一個 `upstream` 區塊，由 Nginx 分配請求。前端不知道、也不需要知道是哪一個後端回的；兩個實作只要有一處行為不一致，同一個操作就會時好時壞，問題會直接浮現。

- **標記回應來源**：兩個後端都在回應加上 `X-Backend: go` 或 `X-Backend: laravel`，Nginx 的 access log 也記下 `$upstream_addr`，排查與壓測時才分得出是誰回的。
- **權重**：預設兩邊相同；要單獨評估其中一個時，用另一份設定只放一個後端（見 [loadtest](../../loadtest/README.md) 的〈執行條件〉）。
- **登入狀態**：同一個使用者的連續請求可能先後落在 Go 與 Laravel，所以登入憑證必須兩邊都能驗證，見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)。

## 流量管理

- **負載平衡**：`upstream` 搭配 `least_conn` 或權重；後端掛掉時用 `max_fails` / `fail_timeout` 暫時移出。
- **限流**：`limit_req_zone` 依來源 IP 限制請求速率，建立連結的 API 與轉址路徑分開設定，轉址的門檻較高。
- **連線數限制**：`limit_conn` 限制單一 IP 的同時連線數。
- **驗證**：限流門檻是否生效、後端被移出時流量是否轉到另一個，由 [loadtest](../../loadtest/README.md) 的限流驗證與後端故障情境確認。
- **快取轉址回應**：要統計點擊就不在 Nginx 快取 `302`，否則點擊不會到後端。

## Docker

基於 `nginx:alpine`，把 `conf.d/` 複製進映像檔。目前 `conf.d/` 是空的，容器會用 Nginx 預設設定啟動。
