# services/go

Go 版的短網址 API。與 [services/laravel](../laravel/README.md) 實作同一份 API 規格、連同一個資料庫與快取，所以 Nginx 把請求交給哪一個後端，結果都應該相同。

## 負責的事

- `GET /{code}`：查出原始網址並回 `302` 轉址，同時記錄一筆點擊事件。這是流量最大、最需要快的路徑：先查快取，查不到才查 PostgreSQL，再寫回快取。
- `POST /api/links` 等管理 API：建立、列出、停用連結，需要登入並依角色檢查權限（見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)）。
- `GET /api/stats/...`：給後台的點擊統計。
- `GET /healthz`：給 Nginx 與容器編排做健康檢查。

與 Laravel 版的效能比較在 [loadtest](../../loadtest/README.md)，兩邊用同一套情境、同樣的容器資源上限。

## 預定的目錄結構

```text
services/go/
├── cmd/server/      # main 套件，Dockerfile 建置這裡
├── internal/        # 業務邏輯，外部套件無法 import
├── migrations/      # 若由 Go 端管理資料庫 schema
├── go.mod
└── Dockerfile
```

## Docker

兩階段建置：`golang` 映像檔編譯出靜態執行檔，執行階段用 `distroless/static`（沒有 shell 與套件管理器，攻擊面小）並以非 root 使用者執行。服務聽 `8080`。
