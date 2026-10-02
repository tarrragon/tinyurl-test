# infra/nginx

所有外部流量的入口：反向代理、負載平衡、流量管理。

## 路由（預定）

| 路徑             | 轉給                          |
| ---------------- | ----------------------------- |
| `/`              | `web/portal` 前台靜態檔       |
| `/admin/`        | `web/admin` 後台靜態檔        |
| `/api/`          | 後端 upstream                 |
| `/{code}`        | 後端 upstream（轉址）         |

## 兩個後端怎麼分流（待決定）

- **依路徑分開**：例如 `/go/api/...` 給 Go、`/php/api/...` 給 Laravel。方便單獨測一個後端，但前端要知道自己在打哪一個。
- **同一個 upstream 輪流接**：Go 與 Laravel 放在同一個 `upstream` 區塊，Nginx 依權重分配。前端不必知道後端是誰，也能直接驗證兩個實作的行為是否一致；可以用回應 header（例如 `X-Backend: go`）標記是誰回的。

## 流量管理

- **負載平衡**：`upstream` 搭配 `least_conn` 或權重；後端掛掉時用 `max_fails` / `fail_timeout` 暫時移出。
- **限流**：`limit_req_zone` 依來源 IP 限制請求速率，建立連結的 API 與轉址路徑分開設定，轉址的門檻較高。
- **連線數限制**：`limit_conn` 限制單一 IP 的同時連線數。
- **驗證**：限流門檻是否生效、後端被移出時流量是否轉到另一個，由 [loadtest](../../loadtest/README.md) 的限流驗證與後端故障情境確認。
- **快取轉址回應**：要統計點擊就不在 Nginx 快取 `302`，否則點擊不會到後端。

## Docker

基於 `nginx:alpine`，把 `conf.d/` 複製進映像檔。目前 `conf.d/` 是空的，容器會用 Nginx 預設設定啟動。
