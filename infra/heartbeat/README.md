# infra/heartbeat

自架的心跳監看服務（[healthchecks](https://github.com/healthchecks/healthchecks) 4.4）。Prometheus、Alertmanager 或整台監控主機停掉時，其他告警都送不出來；這個服務在「該收到心跳卻沒收到」時自己推播，補的是監控堆疊看不到自己停掉的那一段。Alertmanager 這一側的路由與規則見 [infra/observability](../observability/README.md)〈通知管道〉。

| 檢查項目 | 誰送來 | 週期／寬限 | 何時推播 |
| --- | --- | --- | --- |
| `heartbeat` | Alertmanager 每 5 分鐘把 `Watchdog` 送到它的 ping 網址 | 5 分鐘／5 分鐘 | 最後一次心跳之後 10 分鐘沒有下一次：DOWN；之後收到心跳：UP |
| `notify-fail` | Alertmanager 送通知失敗時，把 `AlertmanagerNotificationsFailing` 送到它的 `/fail` | 1 天／1 小時（不使用） | 收到 `/fail` 立即 DOWN |

兩者都推播到 `.env` 的 `HC_NTFY_TOPIC`（設成 Alertmanager 的 critical topic，DOWN 以最高優先度、會響鈴）。網頁在 `http://localhost:8000`，以 `.env` 的 `HC_ADMIN_EMAIL` 與 `HC_ADMIN_PASSWORD` 登入。

## 這一份不獨立於監控主機

它與 Alertmanager 跑在同一台機器上：機器整台停掉、Docker 停掉時兩者一起停，沒有人推播。它驗得到的是心跳、`/fail` 與推播這條機制，不是獨立性。正式環境把它換成另一個失效域的觀察者：託管版 healthchecks.io（把 `alertmanager/secrets/` 的兩個網址換成它給的 ping 網址），或另一個業者的主機上跑同一份 compose。

另一個共用的依賴是 ntfy.sh：Alertmanager 的兩個 topic 與這裡的推播都經過它，ntfy.sh 連不上時三者一起斷。

## 第一次啟動

```sh
cd infra/heartbeat
cp .env.example .env    # 填 HC_SECRET_KEY（openssl rand -hex 32）、HC_ADMIN_PASSWORD、HC_NTFY_TOPIC
./bootstrap.sh          # 起服務、建帳號／ntfy 管道／兩個檢查項目，寫入 ../observability/alertmanager/secrets/ 的兩個網址
curl -X POST http://127.0.0.1:9093/-/reload   # Alertmanager 第一次加上 notify-fail 的路由時才需要；只換網址不必
```

`bootstrap.sh` 可以重複執行：已有的檢查項目沿用（UUID 就是 ping 網址，重建會讓 Alertmanager 手上的網址失效），`.env` 改過的密碼與 topic 會更新上去。Alertmanager 在容器裡，以 `host.docker.internal` 連到這台機器上發佈的 8000 埠；這是 macOS 的 Docker（OrbStack、Docker Desktop）提供的名稱，Linux 上要另外處理。

資料在具名 volume `tinyurl-heartbeat_hcdata`（SQLite）。`docker compose down -v` 會刪掉它，重建之後檢查項目的 UUID 都會換，要重跑 `bootstrap.sh`。

## notify-fail 推播之後

通知送不出去的問題處理完、`AlertmanagerNotificationsFailing` 解除之後，到網頁把 `notify-fail` **暫停**（Pause），讓它回到等待的狀態。不要對它送一次一般的 ping：ping 會讓它開始按週期等下一次心跳，而它平時不收心跳，1 天加 1 小時之後必定誤報一次 DOWN。暫停中的 `notify-fail` 收到下一次 `/fail` 照常推播。

## 驗證

（2026-10-14，macOS 上的 OrbStack，Alertmanager 0.34.1）

- Alertmanager 換上 heartbeat 網址之後，`heartbeat` 收到第一次心跳、狀態 up。
- 加上 notify-fail 的路由並重新載入時，`AlertmanagerNotificationsFailing` 正在成立（換網址之前的送失敗），重新載入的同一秒送到 `/fail`，`notify-fail` 變成 down，3 秒後推播到 critical topic（優先度 5）。復原時以 `manage.py shell` 直接把狀態改成 `paused`，代替網頁上的 Pause 按鈕，網頁那一條路徑沒有實際按過。
- 停機演練：最後一次心跳 07:49:42，07:51:41 停掉 Alertmanager，07:59:44 推播 `heartbeat is DOWN`（優先度 5），是最後一次心跳之後 10 分 2 秒。08:00:19 重新啟動，Prometheus 重新送出告警之後 08:00:35 收到心跳，08:00:37 推播 `heartbeat is UP`（優先度 3）。
- 演練的副作用：停機期間 Prometheus 讀不到 Alertmanager，重新啟動後 `ScrapeTargetDown{job="alertmanager"}`（15 分鐘內讀取成功率低於九成）成立一段時間，時間窗過完自己解除。
