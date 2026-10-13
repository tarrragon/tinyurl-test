# infra/observability

服務活著沒有，由 [infra/monitoring](../monitoring/README.md) 的三層健康檢查回答。這個目錄回答另外兩個問題：每一層收到多少流量、用了多少資源。Prometheus 每 15 秒讀一次各層的 exporter，Grafana 的面板「tinyurl 資源與流量」把它們放在一起。

| 服務 | 版本 | 網址 |
| --- | --- | --- |
| Prometheus | 3.15.0 | `http://127.0.0.1:${PROMETHEUS_PORT:-9090}`，Status → Targets 看各 exporter 讀不讀得到 |
| Grafana | 13.2.3 | `http://127.0.0.1:${GRAFANA_PORT:-3000}`，帳號 `admin`、密碼是 `.env` 的 `GRAFANA_ADMIN_PASSWORD` |
| Alertmanager | 0.34.1 | `http://127.0.0.1:${ALERTMANAGER_PORT:-9093}`，看目前成立與被抑制的告警 |

三者只綁 `127.0.0.1`，不對外開放。後台的 `/admin/ops` 之後連到 Grafana（見 [docs/api.md](../../docs/api.md)〈前端的頁面路由〉）時，再決定經過入口 Nginx 的存取方式。

## 使用

### 啟動與停止

監控的服務和應用服務在同一個 compose 專案裡，`.env` 的 `COMPOSE_FILE`（預設 `docker-compose.yml:compose.host-exporters.yml:compose.monitoring.yml`）讓 `docker compose` 一次讀到三個檔：

```sh
cp .env.example .env    # 第一次：改掉 GRAFANA_ADMIN_PASSWORD、POSTGRES_EXPORTER_PASSWORD 的 change-me
docker compose up -d    # 全部起來，含監控
# 只起監控（應用服務已經在跑時）
docker compose up -d prometheus alertmanager grafana node-exporter cadvisor postgres-exporter valkey-exporter nginx-exporter
# 只停監控，應用服務不受影響
docker compose stop prometheus alertmanager grafana
```

資料存在兩個具名 volume：`promdata`（Prometheus 的時間序列，保留 15 天）與 `grafanadata`（Grafana 的使用者、密碼與介面上的設定）。`docker compose down` 會留下它們；`docker compose down -v` 會一起刪掉，下次起來是空的 Prometheus 與初始的 Grafana 帳號。

### 檔案在哪裡、改了怎麼生效

| 要改的 | 檔案 | 改完怎麼生效 |
| --- | --- | --- |
| 讀哪些 exporter（讀取清單） | `prometheus/targets/<job>.yml` | 存檔後幾秒內生效，不必重新載入 |
| 讀取設定、新增一個 job | `prometheus/prometheus.yml` | `curl -X POST http://127.0.0.1:9090/-/reload` |
| 告警規則 | `prometheus/rules/alerts.yml`，測試在 `prometheus/tests/alerts.test.yml` | 先跑測試，再 `curl -X POST http://127.0.0.1:9090/-/reload` |
| 告警的分組、抑制、通知管道 | `alertmanager/alertmanager.yml` | `curl -X POST http://127.0.0.1:9093/-/reload` |
| 面板 | `grafana/dashboards/resources.json` | 存檔後約 10 秒 Grafana 自己重讀 |
| Grafana 的資料來源 | `grafana/provisioning/datasources/prometheus.yml` | `docker compose restart grafana` |
| 各服務的映像版本、掛載、連接埠 | `compose.monitoring.yml`、`compose.host-exporters.yml`、`docker-compose.yml` | `docker compose up -d <服務>`（compose 判定定義變了才重建） |

設定檔都是以整個目錄唯讀掛進容器，改的是主機上 repo 裡的檔案。重新載入之後，在 Prometheus 的 Status → Configuration、Alertmanager 的 `GET /api/v2/status` 確認載入的是新內容；語法錯的設定會被拒絕，程式繼續用舊的，所以先檢查：

```sh
docker compose exec prometheus promtool check config /etc/prometheus/prometheus.yml   # 讀取設定與規則
docker compose exec alertmanager amtool check-config /etc/alertmanager/alertmanager.yml
# 告警規則的單元測試（在 repo 根目錄跑）
docker run --rm -v "$PWD/infra/observability/prometheus:/p" -w /p/tests \
  --entrypoint promtool prom/prometheus:v3.15.0 test rules alerts.test.yml
```

### 各網頁看什麼

- **Grafana**（`http://127.0.0.1:3000`）：以 `admin` 和 `.env` 的 `GRAFANA_ADMIN_PASSWORD` 登入，面板在 Dashboards 的 `tinyurl` 資料夾，名稱「tinyurl 資源與流量」。頂端的「主機」選單篩選 `host`，單機時只有 `app-1`。面板由上而下是：各 exporter 讀不讀得到、每個服務的 CPU／記憶體／網路與被限流的時間、距離上次被 cAdvisor 看到的秒數、主機的 CPU 與 load、磁碟剩餘比例、PostgreSQL、Valkey、入口 Nginx。
- **Prometheus**（`http://127.0.0.1:9090`）：Status → Targets 看每個讀取對象的狀態與最後一次的錯誤訊息；Alerts 看每條規則現在是 inactive、pending（條件成立、還在等 `for`）還是 firing；Query 頁可以直接打 PromQL，寫規則時把比較運算子拿掉貼進來，看每個序列目前的值。
- **Alertmanager**（`http://127.0.0.1:9093`）：目前送進來的告警，以及哪些被抑制。還沒有通知管道，告警只在這裡看得到。
- **Gatus**（`http://localhost:8090`）：各服務從外部探測通不通，見 [infra/monitoring](../monitoring/README.md)。

三個監控網頁只綁 `127.0.0.1`，從別台電腦連不到；Gatus 綁全部介面。

### 常見操作

**暫時壓下一則告警**（例如維護時）：在 Alertmanager 的網頁按 New Silence，或用指令：

```sh
docker compose exec alertmanager amtool --alertmanager.url=http://localhost:9093 \
  silence add alertname=HostCpuSaturated host=app-1 --duration=2h --comment="維護"
```

**新增一個 exporter**：在 `docker-compose.yml`（跟著服務走的）或 `compose.host-exporters.yml`（每台主機都要的）加服務；在 `prometheus/targets/` 新增一個清單檔，每個讀取對象帶 `host`；在 `prometheus.yml` 加一個指向它的 `file_sd_configs` job，然後重新載入 Prometheus。已有的 job 多讀一個對象時，只改清單檔。

**改面板**：`allowUiUpdates: false`，被佈建的面板在介面上不能儲存。在介面上調好之後，用面板的 Export 匯出 JSON，蓋掉 `grafana/dashboards/resources.json`，Grafana 約 10 秒後重讀；只在介面上改而不匯出的，重新整理就不見了。

**改 Grafana 的 admin 密碼**：`GRAFANA_ADMIN_PASSWORD` 只在 `grafanadata` 第一次建立時生效，之後改 `.env` 不會改到登入密碼。用介面改，或：

```sh
docker compose exec grafana grafana cli admin reset-admin-password <新密碼>
```

**接通知管道**：在 `alertmanager.yml` 的 `receivers` 底下加 webhook 或 email 的設定，把 `route.receiver` 指向它，重新載入 Alertmanager。

### 排錯

| 看到的狀況 | 先查 |
| --- | --- |
| Targets 頁某個對象是 DOWN，錯誤是 `context deadline exceeded` | 讀取逾時（10 秒），多半是主機忙：在 Mac 上跑 `uptime`，load 遠高於核心數時，等負載降下來再看 |
| Targets 頁某個對象是 DOWN，錯誤是 `connection refused` 或 `no such host` | 那個 exporter 的容器沒在跑：`docker compose ps` |
| 對象都是 UP，面板上資料庫或快取的圖是空的 | exporter 連不到它監控的服務：查 `pg_up`、`redis_up`、`nginx_up` 是不是 0 |
| 每個服務的 CPU、記憶體圖都是空的 | cAdvisor 沒取得容器名稱，見下方〈cAdvisor 讀不到容器名稱時〉 |
| 改了規則或 `alertmanager.yml` 沒有效果 | 有沒有重新載入、載入的是不是新內容（見〈檔案在哪裡、改了怎麼生效〉） |
| Grafana 登入說密碼錯 | 密碼以 `grafanadata` 第一次建立時的為準，見〈常見操作〉的改密碼 |
| 面板選了時間範圍卻沒有資料 | 頂端的「主機」選單、時間範圍是否超過 15 天的保留期 |
| 一排告警同時成立（`HostCpuSaturated`、`ScrapeNearTimeout`、`ScrapeTargetDown`） | 多半是 Mac 本身過載，虛擬機與容器一起變慢，見〈在 macOS 上跑時看不到 Mac 本身〉 |
| `HostDiskLow` 對 `device="mac"` 成立 | Mac 本機的磁碟真的快滿了（那一顆的位元組數放大 256 倍，但比例是對的），見〈在 macOS 上跑時看不到 Mac 本身〉的磁碟一項 |

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
- **磁碟**：node_exporter 看得到兩顆磁碟，剩餘空間不同。`device="/dev/vdb1"`（`/var/lib/docker` 等）是虛擬機放 Docker 資料的磁碟，它是存在 Mac 磁碟上的一個映像檔；`device="mac"`（`fstype="virtiofs"`，掛在 `/Users`、`/private` 等）是 OrbStack 掛進虛擬機的 Mac 本機磁碟。後者的剩餘比例和 Mac 上的 `df` 一致，位元組數放大 256 倍（460 GiB 顯示成 126 TB）：OrbStack 2.2.3 回報的區塊數以 4 KiB 計，區塊大小欄位（`statfs` 的 `f_bsize`）卻填 1 MiB，node_exporter 以兩者相乘；`df` 用的是 `f_frsize`（4 KiB）。所以規則與儀表板的「磁碟剩餘比例」都只用比例。實測同一時間前者剩 48.9%、後者剩 2.4%：只看 Docker 那一顆會以為空間充足，而 Mac 的磁碟寫滿時虛擬機會被停掉。`HostDiskLow` 依裝置分組，兩顆都涵蓋。

CPU 的負載在虛擬機裡沒有任何指標反映，所以在 Mac 上做壓測或大量資料的實驗之前，先在 Mac 本機跑 `scripts/host-preflight.sh`（每顆核心的 load 與 Docker 資料所在磁碟的剩餘空間，超過門檻時結束碼 1）。部署到 Linux 主機時，node_exporter 看到的就是那台主機。

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

## 告警

規則在 `prometheus/rules/alerts.yml`，分組與抑制在 `alertmanager/alertmanager.yml`。改完先檢查語法（指令寫在兩個檔案的開頭），再 `curl -X POST http://127.0.0.1:9090/-/reload` 讓 Prometheus 重新載入，Alertmanager 同樣打 `curl -X POST http://127.0.0.1:9093/-/reload`。設定檔掛載的路徑沒變時，`docker compose up -d` 不會重建容器，不重新載入就一直用舊的設定（多台主機那次改動實測過：Alertmanager 的 `equal` 改了之後沒有生效，直到重新載入）。單元測試在 `prometheus/tests/alerts.test.yml`，指令寫在檔案開頭；規則改了先跑它。

| 規則 | 成立條件 | 持續 |
| --- | --- | --- |
| `EndpointDown` | Gatus 的探測失敗（只判斷可用性，見下） | 2 分鐘 |
| `ContainerStopped` | 一台主機上，一個 compose 服務的所有容器都超過 60 秒沒被 cAdvisor 看到（依 `host` 與服務分組） | 1 分鐘 |
| `ScrapeTargetDown` | 15 分鐘內讀取成功的比例低於九成（`avg_over_time(up[15m])`；過載時讀取是間歇失敗，`up == 0` 加 `for` 會被打斷）；讀取對象從清單拿掉之後不再成立（`and up`） | 2 分鐘 |
| `ExporterBackendDown` | exporter 連不到它監控的服務（`pg_up`、`redis_up`、`nginx_up`） | 2 分鐘 |
| `ScrapeNearTimeout` | 5 分鐘內讀取 exporter 的最大耗時超過 8 秒（逾時 10 秒） | 5 分鐘 |
| `ProbeSlow` | 15 分鐘內回應超過 0.5 秒的後端探測佔兩成以上（後端輸出延遲分布之前的延遲訊號；30 次探測取分位數近於取最慢的幾次，所以看比例） | 10 分鐘 |
| `HostCpuSaturated` | 5 分鐘平均的 load 超過核心數的 1.5 倍，或 steal 佔一成以上的 CPU 時間；成立後多撐 15 分鐘（`keep_firing_for`），症狀的時間窗退完之前抑制不解除 | 5 分鐘 |
| `HostDown` | 一台主機的 node_exporter 5 分鐘內沒有一次讀取成功（critical；整台停掉時 cAdvisor 的序列過期，`ContainerStopped` 不會成立） | 1 分鐘 |
| `HostDiskLow` | 任一顆磁碟剩不到 10%（依主機與裝置分組） | 10 分鐘 |
| `HostTargetsMissing` | node job 沒有任何讀取目標（`targets/node.yml` 空了）；個別主機停掉由 `ScrapeTargetDown` 處理 | 5 分鐘 |

- **可用性與延遲分開**：Gatus 對兩個後端的探測只判斷回應碼。回應時間放進條件時，主機過載讓 Laravel 回應 1 到 6 秒，探測被判失敗，而容器照樣 healthy、原因在主機。延遲改由 `ProbeSlow` 以時間窗內慢探測的比例判斷；後端輸出延遲分布之後再加依 histogram 的 `ServiceSlow`。
- **間歇的條件先在時間窗內聚合**：讀取耗時與讀取失敗在過載時是間歇的，原始值加 `for` 每次評估都要成立，兩次尖峰之間就被打斷。實測近 6 小時，`scrape_duration_seconds > 8` 加 `for: 5m` 沒有成立過，改成 5 分鐘內的最大值之後，cadvisor 那一組條件連續成立最長 32 分鐘。主機飽和的規則同樣如此：`node_load1` 直接加 `for` 在 12 小時裡最長連續成立 2 分鐘，改成 5 分鐘平均後最長 9 分鐘。
- **`ContainerStopped` 用 `min`**：服務重建之後，cAdvisor 同時留著舊容器與新容器兩條序列，舊的停止前進。實測重建 `nginx-exporter` 之後 150 秒，`max` 是 161 秒（會誤報），`min` 是 10 秒。
- **抑制**：`HostCpuSaturated` 成立時壓下 warning 級的 `ScrapeNearTimeout`、`ProbeSlow`（之後也壓 `ServiceSlow`）；會通知值班的症狀不抑制。它算的是虛擬機的 load 與 steal，Mac 上的負載不會讓它成立，見〈在 macOS 上跑時看不到 Mac 本身〉。抑制規則寫 `equal: [host]`，一台主機飽和只壓下同一台主機上的症狀；`host` 標籤由 `prometheus/targets/` 的清單加上（`instance` 是讀取對象的位址，同一台主機上每個 exporter 都不同，不能代替它）。
- **Watchdog 與主機外的訊號還沒做**：開發環境沒有主機外的機器與外部的心跳服務，Prometheus 自己停掉時沒有人知道；部署到正式環境時要補。
- **通知管道還沒設**：告警只在 Alertmanager 的頁面上看得到。要通知時在 `alertmanager.yml` 加 receiver（例如 webhook、email），並決定誰會收到、多久內要回應。

第一次接上時的驗收：停掉 `portal` 約 3.5 分鐘後，`EndpointDown`（前台）與 `ContainerStopped`（portal）成立並送到 Alertmanager；對 Alertmanager 送入合成的 `HostCpuSaturated` 與 `ScrapeNearTimeout`，後者的狀態是 `suppressed`；`HostDiskLow` 對 Mac 的磁碟（剩 2.4%）成立。

## 多台主機

監控的元件分成三組：每一台主機（含監控主機）都要跑的 node_exporter 與 cAdvisor 在 `compose.host-exporters.yml`；跟著服務走的 postgres_exporter、redis_exporter、nginx-prometheus-exporter 和服務一起在 `docker-compose.yml`；只在監控主機跑的 Prometheus、Alertmanager、Grafana 在 `compose.monitoring.yml`。三種主機各自用 `.env` 的 `COMPOSE_FILE` 決定讀哪些檔（分隔符在 Linux、macOS 是 `:`，Windows 是 `;`）：

| 主機 | `COMPOSE_FILE` | 另外要設 |
| --- | --- | --- |
| 單機（服務與監控在同一台） | `docker-compose.yml:compose.host-exporters.yml:compose.monitoring.yml` | 無 |
| 被監控的主機 | `docker-compose.yml:compose.host-exporters.yml:compose.exporters-published.yml` | `EXPORTER_BIND_ADDR`：這台的私有網路位址，exporter 的連接埠只開在這個位址上，再用 security group 或主機防火牆限制只有監控主機能連（exporter 沒有認證；Linux 上 Docker 發佈的連接埠不經過 ufw 的規則，主機防火牆要寫在 `DOCKER-USER` 鏈） |
| 監控主機 | `compose.host-exporters.yml:compose.monitoring.yml` | `prometheus/targets/` 換成全部主機的清單，自己的 node_exporter 與 cAdvisor 以服務名稱列在裡面 |

**讀哪些主機寫在清單裡**：`prometheus/targets/` 每個 job 一個檔（file_sd），每個目標帶 `host` 標籤，代表它回報的是哪台主機；單機時全部是 `app-1`，多台主機的寫法在 `prometheus/targets.multi-host.example/`。清單由部署工具從主機清單產生，Prometheus 監看這些檔案，存檔後幾秒內生效，不必重新載入（`refresh_interval: 30s` 是監看失效時的備援）。清單同時是「預期要有哪些主機」的來源：一台主機停掉時它仍在清單上，`up` 變成 0，由 `ScrapeTargetDown` 依 `host` 各自成立；清單整個空了由 `HostTargetsMissing` 處理。改用雲端的服務發現（例如 `ec2_sd_configs`）時，主機被刪掉會從清單上消失、`up` 序列跟著消失，那時要另外比對預期的主機數。

**面板與告警都依主機分開**：面板頂端的「主機」下拉選單篩選 `host`，每條線的圖例前面是主機名稱；告警的分組（`group_by: [alertname, host]`）、抑制（`equal: [host]`）、`ContainerStopped` 與 `HostDiskLow` 的聚合都保留 `host`。

**驗證**（在同一台 Mac 上另外跑一個 node_exporter 當作 host-b，經由 `host.docker.internal` 的連接埠讀它，模擬跨主機的讀取）：清單加上 host-b 之後 13 秒出現在 Targets 頁面，沒有重新載入；停掉 host-b 的 exporter，2 分 21 秒後 `ScrapeTargetDown{host="host-b"}` 成立，app-1 的不受影響；對 Alertmanager 送入合成的 `HostCpuSaturated{host="host-b"}` 與兩則 `ScrapeNearTimeout`（host-b、host-c），只有 host-b 的那則被抑制。規則的單元測試涵蓋同一個服務在兩台主機時只有停掉的那台成立、一台主機停掉時只對它成立、清單空了時成立；把 `ContainerStopped` 的 `host` 從分組拿掉，測試會失敗。host-b 的資料其實來自同一台虛擬機，所以這次驗的是標籤、清單與規則的機制，不是兩台主機的數字。

**還沒做**：

- **服務本身還沒拆到多台**：`docker-compose.yml` 仍把全部服務放在一起；資料庫與快取放到另一台時，要依主機的角色拆出各自的 compose 檔，exporter 跟著它監控的服務走（範例清單的 db-1 就是這種配置）。
- **Gatus**：它以 compose 的服務名稱探測同一台上的服務，服務分到多台時探測網址要改成各主機的位址，並移到被監控的主機以外；`prometheus.yml` 裡把探測結果的 `host` 改寫成端點所在主機的那一條，要依端點名稱分開寫。
- **exporter 的 TLS 與認證**：目前靠「只綁私有位址＋防火牆」限制誰能讀；跨不可信的網段時，要在 exporter 前面加 TLS 與 basic auth（exporter-toolkit 的 `--web.config.file`），Prometheus 的讀取設定加對應的 `tls_config` 與 `basic_auth`。
- **Watchdog 與主機外的訊號**：同〈告警〉。

## 讀數字時

- **CPU 看時間窗內的平均，不看單次取樣**：`docker stats` 一次只取一個瞬間，同一個容器幾秒內可以從 0.1% 跳到 27%。面板用 `rate(...[1m])` 算一分鐘的平均。
- **CPU 配額限流**那張圖只有設了 CPU 上限的容器才有資料；壓測時兩個後端要設相同的上限（見 [loadtest](../../loadtest/README.md)〈公平比較〉），這張圖才用得上。
- **回應時間看面板，不看單次探測**：Gatus 記錄的 `gatus_results_duration_seconds` 是單次探測的耗時，包含探測程式自己在過載主機上的等待；看趨勢可以，拿來判斷服務變慢要等後端的延遲分布。
