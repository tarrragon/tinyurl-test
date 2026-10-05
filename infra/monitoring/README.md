# infra/monitoring

服務是否存活由三層回答，每一層看得到的範圍不同：

| 層 | 誰檢查 | 看得到 | 看不到 |
| --- | --- | --- | --- |
| 容器的 healthcheck | Docker，在容器裡執行 `docker-compose.yml` 寫的指令 | 這個容器自己的端點能不能回應 | 別的容器連不連得到它、入口的路由對不對 |
| Gatus | 另一個容器，從 compose 網路以服務名稱發請求 | 服務從外面打不打得通，含入口 Nginx 的路由 | 容器內部的狀態；Gatus 自己掛掉時沒有人知道 |
| `scripts/health.sh` | 人或排程，SSH 進主機時執行 | 每個容器有沒有在跑、healthcheck 的結果 | 只是把第一層的結果列出來，不另外檢查 |

## 容器的 healthcheck

各服務的檢查指令寫在 `docker-compose.yml`：

| 服務 | 檢查 |
| --- | --- |
| go | `/server -healthcheck`：映像檔是 distroless，沒有 shell 與 curl，同一支執行檔帶這個參數時改去請求自己的 `/-/healthz` |
| laravel | `curl http://127.0.0.1:8080/-/healthz`：經過容器內的 Nginx 與 PHP-FPM，三者任一個壞掉都會失敗 |
| portal、admin、nginx | `curl` 首頁（admin 是 `/admin/`） |
| cache | `valkey-cli ping` |
| postgres | `pg_isready` |

實測過、會影響怎麼用它的行為：

- **unhealthy 不會觸發重啟。** 讓前台回 403、程序照常執行，31 秒後（每 10 秒一次、連續 3 次失敗）變成 `unhealthy`，之後持續 100 秒以上 `RestartCount` 仍是 0。Docker 只在狀態改變時發出一個 `health_status: unhealthy` 事件。`restart: unless-stopped` 只在容器**結束**時重啟它：讓 Laravel 容器裡的 PHP-FPM 結束（entrypoint 會讓整個容器結束），1 秒後就被重啟。
- **修好之後自己恢復。** healthcheck 持續在跑，前台改回正常後狀態回到 `healthy`，不需要重啟。
- **`depends_on: service_healthy` 只管啟動。** 依賴的服務在啟動後變成 unhealthy，已經在跑的服務不受影響；但重建或重啟依賴它的服務時會失敗（`dependency failed to start: container ... is unhealthy`），容器停在 `created`。入口 Nginx 因此只等上游 `service_started`：前台壞掉時，入口照樣能重啟，只有前台那條路徑出錯。後端仍然等 PostgreSQL 與 Valkey `service_healthy`，因為後端沒有資料庫就無法服務。

## Gatus

設定在 `gatus/config.yaml`，儀表板在 `http://localhost:${GATUS_PORT}`（`.env` 預設 8090），結果也能從 `/api/v1/endpoints/statuses` 讀成 JSON。

- 兩個後端直接打 `go:8080` 與 `laravel:8080`：它們在同一個 upstream，經過入口的請求不保證打到哪一個。經過入口的那一條只確認入口與路由是通的。
- PostgreSQL 與 Valkey 用 TCP 檢查。TCP 連得上只代表有程序在聽那個埠，所以 Valkey 另外送 `PING`、要收到 `PONG` 才算通過。
- 告警（Slack、email 等）沒有設定，見 [開發順序](../../docs/roadmap.md) 的〈練習環境跳過的項目〉；Gatus 自己沒有被任何東西監看，也是同一個原因。

## scripts/health.sh

```sh
scripts/health.sh                                   # 在 repo 根目錄
ssh <主機> 'cd <repo 路徑> && scripts/health.sh'     # 從遠端
```

`<主機>` 是 SSH 設定裡的主機名稱，`<repo 路徑>` 是這個 repo 在那台主機上的路徑。結束碼：`0` 全部正常；`1` 有服務沒在跑或 unhealthy；`2` 沒有 `1` 的情況、但有服務還在 `starting`。部署流程可以等它回到 `0`，排程檢查可以把 `2` 當成警告。
