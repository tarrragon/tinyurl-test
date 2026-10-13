# 多台主機的目標清單範例

三台被監控的主機加一台監控主機時，`targets/` 底下各檔的寫法。位址是佔位值（`10.0.1.x` 是私有網段的範例），
換成各主機的私有網路位址；每台被監控的主機以 `compose.exporters-published.yml` 把 exporter 開在那個位址上。

| host  | 跑什麼                                         | 這台的 exporter                                  |
| ----- | ---------------------------------------------- | ------------------------------------------------ |
| app-1 | 入口 Nginx、Go、Laravel、前端                  | node_exporter、cAdvisor、nginx-prometheus-exporter |
| app-2 | 同 app-1                                       | 同 app-1                                         |
| db-1  | PostgreSQL、Valkey                             | node_exporter、cAdvisor、postgres_exporter、redis_exporter |

每個 exporter 跟著它監控的服務走：資料庫在 db-1，postgres_exporter 就在 db-1，`host` 填 db-1。
清單由部署工具（Ansible、Terraform）從主機清單產生，主機增減時重新產生；Prometheus 每 30 秒重讀一次，不必重新載入。
清單是「預期要有哪些主機」的來源：一台主機停掉時它仍在清單上，`up` 變成 0，`ScrapeTargetDown` 會成立。
