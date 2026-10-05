# tinyurl-test

電商用的短網址服務，給行銷團隊在簡訊、Email 與網路廣告裡放縮短後的連結，並在後台看點擊數據。這個 repo 是練習用的 monorepo：同一套短網址 API 分別用 Go 與 Laravel 各實作一次，兩者放在 Nginx 的同一個 upstream 輪流接請求，作為同樣功能的實作比較；Nginx 同時負責負載平衡與流量管理，資料存在 PostgreSQL，熱資料放 Valkey。

目前只有資料夾結構、各元件的 README 與 Dockerfile，還沒有任何程式碼。

## 業務範圍

需求、容量估算與各項設計取捨的完整說明在 [docs/system-design.md](docs/system-design.md)；實作的先後順序與每個階段的完成條件在 [docs/roadmap.md](docs/roadmap.md)。

這是練習專案，系統設計裡的每一項都會實作，順序是先做最簡單的版本、用壓測量出瓶頸，再逐項加上優化並重量一次。TLS、備份、秘密管理、高可用這類上線服務需要的項目刻意跳過，清單見 roadmap 的〈練習環境跳過的項目〉。

- **使用者**：電商的行銷團隊（建立與管理連結、看活動成效）與工程師（看系統狀態與 log）。
- **連結用途**：簡訊（字數有限，連結越短越好）、Email、網路廣告。建立連結時標記管道與活動，後台才能依管道比較成效。
- **兩個網域**：短網址用獨立的短網域（本機 `s.localhost`），只負責轉址；前台、後台與 API 在另一個網域（本機 `app.localhost`）。
- **轉址**：訪客點短網址，服務查出原始網址後回傳轉址。要統計點擊就用 `302`，因為 `301` 會被瀏覽器快取，第二次點擊不會再回到服務。
- **登入**：前台與後台都要登入，用 JWT（兩個後端共用金鑰），角色不同看到的東西不同，見 [docs/auth-and-roles.md](docs/auth-and-roles.md)。
- **壓測（伺服器能力評估）**：在練習用的機器上量出轉址路徑大概能承受多少流量、瓶頸在哪一層，並在同一套情境下比較 Go 與 Laravel、以及不同的快取伺服器；點擊數要和送出的請求數對得上。情境與指標見 [loadtest/README.md](loadtest/README.md)。

## 架構

```text
  點短網址的訪客                     行銷 / 工程師
        │                                  │
   SHORT_DOMAIN                        APP_DOMAIN
        │                                  │
  ┌─────┴──────────────────────────────────┴─────┐
  │        Nginx（負載平衡、限流、TLS）          │
  └──┬──────────────┬──────────────┬─────────┬───┘
     │              │              │         │
  /{code}        / 前台      /admin/ 後台   /api/
     │          web/portal     web/admin     │
     │                                       │
     └─────────────────┬─────────────────────┘
                       │
              ┌────────┴─────────┐
              │ services/go      │  同一份 API 規格，
              │ services/laravel │  同一個 upstream
              └───┬──────────┬───┘
                  │          │
               Valkey    PostgreSQL
              （快取）  （主資料庫）
```

## 資料夾

| 路徑                                       | 內容                                                    |
| ------------------------------------------ | ------------------------------------------------------- |
| [web/](web/README.md)                      | 前端網頁（Vue 3 + Vite），分前台與後台                  |
| [web/portal/](web/portal/README.md)        | 前台：登入後建立、管理短網址                            |
| [web/admin/](web/admin/README.md)          | 後台：點擊數據、log、使用者與權限管理                   |
| [services/go/](services/go/README.md)      | Go 版短網址 API                                         |
| [services/laravel/](services/laravel/README.md) | Laravel 版短網址 API                               |
| [infra/nginx/](infra/nginx/README.md)      | 反向代理、負載平衡、限流                                |
| [infra/cache/](infra/cache/README.md)      | 快取伺服器 Valkey，以及選型時的比較                     |
| [infra/postgres/](infra/postgres/README.md) | 主資料庫 PostgreSQL、初始化腳本                        |
| [loadtest/](loadtest/README.md)            | 壓測情境、測試資料與結果紀錄                            |
| [docs/](docs/)                             | 跨元件的設計文件：[系統設計](docs/system-design.md)（需求、容量估算、短碼、資料模型、API）、[開發順序](docs/roadmap.md)、[CI 設計](docs/ci.md)、[服務監看](infra/monitoring/README.md)、[登入與權限](docs/auth-and-roles.md) |

## Docker

各應用元件（前台、後台、Go、Laravel、Nginx）各有自己的 `Dockerfile`；快取與 PostgreSQL 直接用官方映像檔，設定由 `docker-compose.yml` 掛載。程式碼寫好之前 `docker compose build` 會失敗，因為 Dockerfile 要複製的 `go.mod`、`composer.json`、`package.json` 都還不存在。

```bash
cp .env.example .env
docker compose up -d postgres cache   # 目前只有這兩個起得來
```

## 待決定

- API 錯誤格式、錯誤碼與分頁格式、metrics 名稱、契約測試工具：在 [開發順序](docs/roadmap.md) 的〈階段一：定案與骨架〉決定。
- 壓測工具，見 [loadtest/README.md](loadtest/README.md)。
