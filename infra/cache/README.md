# infra/cache

快取伺服器。`docker-compose.yml` 目前先放 Valkey，選定之後換映像檔即可，兩個後端連線的程式碼只要協定相容就不必改。

## 這個服務要快取做什麼

選型前先列出用途，因為不同選項的差別就在這些用途上：

| 用途                   | 需要的能力                                        |
| ---------------------- | ------------------------------------------------- |
| 短碼 → 原始網址        | 單純的 key-value 加過期時間，轉址路徑每次都會查   |
| 限流計數               | 原子遞增加過期（`INCR` + `EXPIRE`）               |
| 登入 session（若採用） | key-value 加過期，可主動刪除                      |
| 點擊數的暫存與批次寫入 | 原子遞增，或用佇列 / stream 暫存點擊事件再批次寫進 PostgreSQL |
| Laravel queue          | list 或 stream 資料結構                           |

## 選項

| 選項          | 協定               | 授權                                   | 特點                                                                 |
| ------------- | ------------------ | -------------------------------------- | -------------------------------------------------------------------- |
| **Valkey**    | Redis 相容（RESP） | BSD-3                                  | Redis 7.2 授權變更前分出的開源分支，由 Linux Foundation 維護；AWS、Google Cloud 的託管快取都支援 |
| **Redis**     | RESP               | 7.4 起改為 RSAL / SSPL，8.0 起加入 AGPLv3 選項 | 原版，文件與範例最多；授權變更對自架練習沒有影響，對商業託管有影響 |
| **Dragonfly** | 相容 Redis 與 Memcached | BSL                               | 多執行緒，單機吞吐量高；部分 Redis 指令與行為有差異                  |
| **KeyDB**     | RESP               | BSD-3                                  | 多執行緒的 Redis 分支，近年開發節奏放慢                              |
| **Memcached** | Memcached 協定     | BSD                                    | 只有 key-value 與遞增，沒有 list / stream / pub-sub，也不持久化      |
| **Garnet**    | RESP               | MIT                                    | Microsoft 以 .NET 實作，支援的指令是 Redis 的子集                    |

## 建議

**選 Valkey（或 Redis 8），先不選 Memcached。**

- 上表的用途裡，限流、點擊暫存與 Laravel queue 都需要 Memcached 沒有的資料結構；選 Memcached 就得再加一個 queue 服務。
- Valkey 與 Redis 協定相同，Go 的 `go-redis`（或 `valkey-go`）與 Laravel 的 `phpredis` 都直接可用，兩者之後互換不必改程式。Valkey 的授權沒有限制，選它可以避開授權問題；想跟著官方文件走就選 Redis 8。
- Dragonfly 適合之後當效能比較的練習：同一套程式換成 Dragonfly，用 [loadtest](../../loadtest/README.md) 的轉址基準與爆紅連結情境比較兩者的差異。

## 要注意的地方

- **快取不是資料來源**：短碼對照的權威在 PostgreSQL，快取掛掉時轉址要能退回查資料庫（變慢但不壞）。
- **熱門連結**：一條連結在簡訊發出後短時間湧入大量點擊，所有請求都打同一個 key；應用程式內可以再加一層短效期的記憶體快取。
- **兩個後端共用 key**：key 前綴與序列化格式要一致，見 [services/laravel/README.md](../../services/laravel/README.md)。
