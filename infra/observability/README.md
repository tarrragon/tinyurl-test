# infra/observability

服務活著沒有，由 [infra/monitoring](../monitoring/README.md) 的三層健康檢查回答。這個目錄回答另外兩個問題：每一層收到多少流量、用了多少資源。Prometheus 每 15 秒讀一次各層的 exporter，Grafana 的面板「tinyurl 資源與流量」把它們放在一起。

| 服務 | 版本 | 網址 |
| --- | --- | --- |
| Prometheus | 3.15.0 | `http://127.0.0.1:${PROMETHEUS_PORT:-9090}`，Status → Targets 看各 exporter 讀不讀得到 |
| Grafana | 13.2.3 | `http://127.0.0.1:${GRAFANA_PORT:-3000}`，帳號 `admin`、密碼是 `.env` 的 `GRAFANA_ADMIN_PASSWORD` |

兩者只綁 `127.0.0.1`，不對外開放。後台的 `/admin/ops` 之後連到 Grafana（見 [docs/api.md](../../docs/api.md)〈前端的頁面路由〉）時，再決定經過入口 Nginx 的存取方式。

## 各層的 exporter 與它看不到的範圍

| 層 | exporter | 看得到 | 看不到 |
| --- | --- | --- | --- |
| 入口 Nginx | nginx-prometheus-exporter 1.5.0，讀 `nginx:8081/stub_status` | 連線數、累計請求數 | 依網域、狀態碼、上游分開的請求數：要從 access log 算，階段三接 log 系統時補上 |
| 容器 | cAdvisor v0.55.1，讀 cgroup | 每個容器的 CPU、記憶體、網路、磁碟讀寫，以及被 CPU 配額限流的時間 | 容器裡是哪個行程、哪個查詢在用資源 |
| 主機 | node_exporter 1.12.1 | CPU、記憶體、load、磁碟空間與讀寫 | 網路（它在 compose 網路裡，網路介面是自己容器的，所以關掉 `netdev`、`netstat`、`sockstat`，網路看 cAdvisor）；以及 Docker 所在的虛擬機以外的機器，見下一節 |
| PostgreSQL | postgres_exporter 0.20.1，用唯讀帳號 `postgres_exporter`（`pg_monitor`） | 連線數與狀態、交易數、各表的統計 | 個別查詢的耗時：要裝 `pg_stat_statements`，目前沒有 |
| Valkey | redis_exporter 1.93.0 | 記憶體、命中與未命中、被淘汰的鍵、連線數 | 應用程式那一端等 Valkey 的時間 |
| 外部探測 | Gatus 的 `metrics: true` | 探測成功與否、回應時間 | 真實使用者的流量 |
| 應用程式 | 兩個後端的 `/-/metrics`，階段三接上 | 依路由的延遲、快取命中、業務事件 | — |

## 在 macOS 上跑時看不到 Mac 本身

macOS 上的 Docker（OrbStack、Docker Desktop、Colima）跑在一台 Linux 虛擬機裡，node_exporter 與 cAdvisor 都在這台虛擬機裡，回報的是虛擬機：

- **CPU**：Mac 上其他程式把 CPU 吃滿時，虛擬機分到的時間變少，容器全面變慢，而虛擬機的 CPU 使用率與 load 都很低。實測同一個時間點，node_exporter 的 load 是 2.89，Mac 本機是 55。
- **磁碟**：虛擬機的磁碟是存在 Mac 磁碟上的一個映像檔，虛擬機回報的可用空間（實測 34.6 GB）比 Mac 實際剩下的多（同時 15 GB）。Mac 的磁碟寫滿時虛擬機會被停掉，面板上看不到這個風險。

所以在 Mac 上做壓測或大量資料的實驗之前，先在 Mac 本機看一次 `uptime` 與 `df -h ~`。部署到 Linux 主機時，node_exporter 看到的就是那台主機。

## cAdvisor 讀不到容器名稱時

cAdvisor 要連上 containerd 才知道每個 cgroup 是哪個容器。找不到時，指標裡只有 `id="/docker/<雜湊>"`，沒有 `name` 與 `container_label_com_docker_compose_service`，面板上依服務分組的圖全部是空的，而 Targets 頁面照樣顯示 `UP`。日誌會寫：

```text
Registration of the docker container factory failed: unable to create containerd client: ...
```

Linux 主機直接跑 Docker 時，預設的 `/run/containerd/containerd.sock` 就對。把 Docker 跑在虛擬機裡的環境位置不同，用這一行找出來，填進 `.env` 的 `CADVISOR_CONTAINERD_SOCKET`：

```sh
docker run --rm -v /var/run:/host-run:ro alpine find /host-run -name 'containerd*.sock'
# 找到 /host-run/docker/containerd/containerd.sock 時，填 /var/run/docker/containerd/containerd.sock
```

## 既有的資料庫補建監控帳號

`postgres_exporter` 的帳號由 `infra/postgres/initdb/10-monitoring-role.sh` 建立，而 `initdb/` 只在資料目錄是空的時候執行。資料庫已經存在時補建一次：

```sh
set -a; . ./.env; set +a
docker compose exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
  -v pw="$POSTGRES_EXPORTER_PASSWORD" <<'SQL'
CREATE ROLE postgres_exporter LOGIN PASSWORD :'pw';
GRANT pg_monitor TO postgres_exporter;
SQL
```

## 驗收

每接上或改動一個 exporter，三件事都要確認：

1. **讀得到，而且 exporter 連得到它監控的服務**：Prometheus 的 Targets 頁面上是 `UP`，或查 `up` 每一個 job 都是 1。`up` 只代表 Prometheus 連得到 exporter；exporter 連不上 PostgreSQL、Valkey 或 Nginx 時 `up` 照樣是 1，要另外看 `pg_up`、`redis_up`、`nginx_up`。
2. **每個預期的對象都在標籤裡**：`count by (container_label_com_docker_compose_service) (container_memory_working_set_bytes)` 每個 compose 服務一列。讀得到而缺標籤時，數字看起來正常，依服務分組的圖卻是空的。
3. **數字會反應**：刻意製造一次它該看到的狀況。第一次接上時實測的結果：

   | 製造的狀況 | 看哪個指標 | 實測 |
   | --- | --- | --- |
   | 在 `cache` 容器裡跑一個空轉的迴圈 | 該服務的 CPU | 0.98 顆核心 |
   | 開 5 個連線各自 `select pg_sleep(90)` | `pg_stat_activity_count{state="active"}` | 5 |
   | 停掉 `portal` | Gatus 的 `gatus_results_endpoint_success{name="前台"}` | 0 |
   | 停掉 `portal` | `time() - container_last_seen` | 每 15 秒增加 15，3 分鐘後仍在增加 |

   **停掉的容器在 cAdvisor 的指標裡不會消失**：cAdvisor 繼續輸出它最後的 CPU 與記憶體數值，依服務分組的圖上那一條線維持在停掉前的值。偵測容器停了要看 `container_last_seen` 有沒有停止前進（面板上「距離上次被 cAdvisor 看到的秒數」），寫成「序列消失就告警」的話永遠不會響。

   監控只在出事時被讀，平常沒有人會發現它讀不到或讀錯。

## 讀數字時

- **CPU 看時間窗內的平均，不看單次取樣**：`docker stats` 一次只取一個瞬間，同一個容器幾秒內可以從 0.1% 跳到 27%。面板用 `rate(...[1m])` 算一分鐘的平均。
- **CPU 配額限流**那張圖只有設了 CPU 上限的容器才有資料；壓測時兩個後端要設相同的上限（見 [loadtest](../../loadtest/README.md)〈公平比較〉），這張圖才用得上。
- **Gatus 的探測與容器的 healthcheck 判斷的條件不同**：Laravel 的探測條件是回應碼 200 且回應時間低於 500 ms，容器的 healthcheck 只看回應碼。主機過載時 Laravel 回應要 1 到 6 秒，Gatus 判失敗、容器照樣 healthy；兩者不一致時，代表服務活著但變慢了。
