# infra/nginx

所有外部流量的入口：反向代理、負載平衡、流量管理。

## 兩個網域

短網址與前後台分成兩個網域，各是一個 `server` 區塊，依 `server_name` 分開。網域名稱由環境變數 `SHORT_DOMAIN`、`APP_DOMAIN` 提供，本機預設 `s.localhost` 與 `app.localhost`。

| 網域             | 路徑       | 轉給                                         |
| ---------------- | ---------- | -------------------------------------------- |
| `SHORT_DOMAIN`   | `/{code}`  | 後端 upstream（轉址）                        |
| `SHORT_DOMAIN`   | `/`        | 轉址到電商首頁                               |
| `APP_DOMAIN`     | `/`        | `web/portal` 前台                            |
| `APP_DOMAIN`     | `/admin/`  | `web/admin` 後台                             |
| `APP_DOMAIN`     | `/api/`    | 後端 upstream                                |

分開的理由：

- **短網域只做轉址**：根路徑以下全部是短碼，不必保留 `app`、`admin`、`api` 這類字，前台的 SPA 路由也不會和短碼撞名。
- **短網址越短越好**：簡訊按字數計費，正式環境的短網域會選一個比主網域短的名字。
- **轉址請求不帶登入 cookie**：登入 cookie 只屬於 `APP_DOMAIN`，訪客點短網址時請求裡沒有任何 token（見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)）。
- **限流分開設定**：短網域的轉址量大、門檻高；`APP_DOMAIN` 的 API 量小、門檻低，尤其是登入端點。

`SHORT_DOMAIN` 上不開放 `/api/`，管理 API 只從 `APP_DOMAIN` 進來。

## 兩個後端放在同一個 upstream

Go 與 Laravel 是同一份 API 規格的兩種實作，放在同一個 `upstream` 區塊，由 Nginx 分配請求。前端不知道、也不需要知道是哪一個後端回的；兩個實作只要有一處行為不一致，同一個操作就會時好時壞，問題會直接浮現。

- **標記回應來源**：兩個後端都在回應加上 `X-Backend: go` 或 `X-Backend: laravel`，Nginx 的 access log 也記下 `$upstream_addr`，排查與壓測時才分得出是誰回的。
- **權重**：預設兩邊相同；要單獨評估其中一個時，用另一份設定只放一個後端（見 [loadtest](../../loadtest/README.md) 的〈執行條件〉）。
- **登入狀態**：同一個使用者的連續請求可能先後落在 Go 與 Laravel，所以登入憑證必須兩邊都能驗證，見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)。

## 流量管理

- **負載平衡**：`upstream` 搭配 `least_conn` 或權重；後端掛掉時用 `max_fails` / `fail_timeout` 暫時移出。
- **限流**：`limit_req_zone` 依來源 IP 限制請求速率，兩個網域分開設定，轉址的門檻較高。
- **連線數限制**：`limit_conn` 限制單一 IP 的同時連線數。
- **驗證**：限流門檻是否生效、後端被移出時流量是否轉到另一個，由 [loadtest](../../loadtest/README.md) 的限流驗證與後端故障情境確認。
- **快取轉址回應**：要統計點擊就不在 Nginx 快取 `302`，否則點擊不會到後端。

## Docker

基於 `nginx:alpine`，把 `conf.d/` 複製進映像檔。目前 `conf.d/` 是空的，容器會用 Nginx 預設設定啟動。

網域名稱要從環境變數帶入設定檔時，用官方映像檔的 template 機制：設定檔放在 `/etc/nginx/templates/*.conf.template`，容器啟動時以 `envsubst` 代入 `${SHORT_DOMAIN}` 這類變數後輸出到 `conf.d/`。
