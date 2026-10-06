# API 與路由規格

這份文件定下各服務的路由與 API：入口 Nginx 在兩個網域上把哪些路徑交給誰、後端每個端點的 method 與行為、錯誤格式、分頁，以及兩個前端的頁面路由。它是[開發順序](roadmap.md)〈階段一：定案與骨架〉的產物，Go 與 Laravel 都照這份實作，契約測試也照這份寫。

Go 與 Laravel 在同一個 upstream 輪流接請求，同一個使用者的連續請求可能先後落在兩邊。所以這份規格除了端點，還定死了所有會被客戶端拿回來再送出的值（分頁游標、時間格式），兩個後端必須產生逐字相同的結果。

## 共通約定

| 項目 | 約定 |
| --- | --- |
| 網域 | 管理 API 只在 `APP_DOMAIN` 的 `/api/` 底下；`SHORT_DOMAIN` 只做轉址 |
| 版本 | 網址不帶版本號（沒有 `/v1`）。消費者只有自己的兩個前端，跟後端一起部署；觸發條件見〈不在範圍內〉 |
| 請求格式 | 有 body 的請求（`POST`、`PATCH`）一律 `Content-Type: application/json`，否則回 `415`。沒有內容的 `POST`（例如登出）送 `{}`。這同時是 CSRF 的第二層防護，見 [登入與權限](auth-and-roles.md) |
| 回應格式 | 成功是 `application/json`；錯誤是 `application/problem+json`，見〈錯誤格式〉 |
| 欄位命名 | `snake_case` |
| 時間 | RFC 3339、UTC、到秒、以 `Z` 結尾：`2026-10-06T08:30:00Z`。Go 的 `time.RFC3339Nano` 會帶小數秒、Laravel 的 ISO 8601 預設輸出 `+00:00`，兩邊都要明確格式化成這一種 |
| 連結的識別 | 用短碼 `code`，不對外暴露資料表的 `id` |
| 回應 header | 每個回應帶 `X-Backend: go` 或 `X-Backend: laravel` |
| 登入 | access token 與 refresh token 都在 cookie（`HttpOnly`），前端讀不到，要知道目前使用者與角色就呼叫 `GET /api/auth/me` |

## 入口 Nginx 的路由

兩個網域各是一個 `server` 區塊。表中「交給」的後端都是同一個 upstream（Go 與 Laravel 輪流）。

### SHORT_DOMAIN

| 路徑 | 處理 | 理由 |
| --- | --- | --- |
| `= /` | Nginx 直接 `302` 到電商首頁 | 根路徑沒有短碼，不必進後端 |
| `= /robots.txt`、`= /favicon.ico` | Nginx 直接回應 | 帶 `.` 的路徑不可能是短碼，不必進後端 |
| 一段、6 到 7 碼 Base62（`^/[0-9A-Za-z]{6,7}$`） | 交給後端 | 只把格式可能正確的短碼送進後端，掃描者亂打的路徑在 Nginx 就擋掉 |
| 其他所有路徑（含 `/api/`、`/-/`） | `404` | 管理 API 與維運端點不從短網域開放 |

自訂短碼若允許 6 到 7 碼以外的長度，這條正規表示式要跟著改；目前自訂短碼也限 6 到 7 碼（見〈建立連結〉）。

### APP_DOMAIN

| 路徑 | 處理 |
| --- | --- |
| `/api/` | 交給後端 |
| `/admin/` | 交給 `web/admin`（後台） |
| `/-/` | `404`：維運端點只給 compose 網路內部使用，見 [系統設計](system-design.md)〈健康檢查〉 |
| `/` 與其他路徑 | 交給 `web/portal`（前台） |

### 後端自己的維運端點

不經過入口，只在 compose 網路內以 `go:8080`、`laravel:8080` 存取：

| Method 與路徑 | 用途 |
| --- | --- |
| `GET /-/healthz` | 健康檢查，`200` |
| `GET /-/metrics` | Prometheus 指標（階段三） |

## 端點總覽

| Method 與路徑 | 用途 | 角色 |
| --- | --- | --- |
| `GET`、`HEAD /{code}`（SHORT_DOMAIN） | 轉址 | 公開 |
| `POST /api/auth/login` | 登入 | 公開 |
| `POST /api/auth/refresh` | 換發 access token | 持有 refresh token |
| `POST /api/auth/logout` | 登出 | 已登入 |
| `GET /api/auth/me` | 目前使用者 | 已登入 |
| `POST /api/links` | 建立連結 | `marketing`、`marketing_lead`、`admin` |
| `POST /api/links/batch` | 每個收件人一條的批次建立 | `marketing_lead`、`admin` |
| `GET /api/links` | 列出連結 | 已登入（範圍依角色） |
| `GET /api/links/{code}` | 單一連結 | 已登入（範圍依角色） |
| `PATCH /api/links/{code}` | 停用、恢復、修改到期時間 | 建立者、`marketing_lead`、`admin` |
| `GET /api/stats/links/{code}` | 單一連結的點擊統計 | 範圍依角色，`engineer` 除外 |
| `GET /api/stats/campaigns` | 一個活動依管道的點擊統計 | 範圍依角色，`engineer` 除外 |
| `GET /api/stats/campaigns/export` | 匯出活動報表（CSV） | `marketing_lead`、`admin` |
| `GET /api/users` | 列出使用者 | `admin` |
| `POST /api/users` | 建立使用者 | `admin` |
| `PATCH /api/users/{id}` | 改角色、團隊、停用 | `admin` |
| `GET /api/teams` | 列出團隊 | 已登入 |
| `POST /api/teams` | 建立團隊 | `admin` |

**沒有任何 `DELETE`**：連結的短碼不重複使用、到期後仍要轉首頁（見 [系統設計](system-design.md)〈到期之後〉），所以連結只能停用不能刪除。使用者被連結與點擊紀錄引用，刪除會讓統計找不到建立者，所以只能停用（`users.disabled_at`）；團隊同樣被引用，目前沒有停用團隊的需求，所以只提供建立與列出。

### 「範圍依角色」的意思

| 角色 | 連結與統計看得到的範圍 |
| --- | --- |
| `marketing` | 自己建立的連結 |
| `marketing_lead` | 自己團隊的連結 |
| `engineer` | 全部連結的設定（唯讀）；不看統計 |
| `admin` | 全部 |

範圍外的連結一律回 `404` 而不是 `403`：回 `403` 等於告訴請求者「這個短碼存在」，可以拿來探測別的團隊的連結。`403` 只用在角色本身沒有這個操作的權限時（例如 `marketing` 呼叫 `POST /api/links/batch`）。

## 轉址

### `GET /{code}`、`HEAD /{code}`

| 情況 | 回應 |
| --- | --- |
| 短碼存在、未到期、未停用 | `302`，`Location: <原始網址>`，記錄一筆點擊 |
| 短碼存在、已到期或已停用 | `302`，`Location: <電商首頁>`，記錄一筆點擊並標記「已到期」 |
| 短碼不存在 | `404`，一頁簡單的 HTML |

- 所有轉址回應帶 `Cache-Control: no-store`：瀏覽器或中間的代理快取了轉址，之後的點擊就不會到後端，點擊數會少算。
- `HEAD` 回同樣的狀態碼與 header，但**不記錄點擊**：送 `HEAD` 的多半是連結預覽與檢查工具，不是訪客。
- 電商首頁的網址由環境變數提供（例如 `HOME_URL`），兩個後端共用。

## 登入

### `POST /api/auth/login`

```json
{ "email": "alice@example.com", "password": "..." }
```

- 成功：`204`，`Set-Cookie` 設定 access token 與 refresh token 兩個 cookie，屬性見 [登入與權限](auth-and-roles.md)〈存放位置〉。
- 帳號不存在、密碼錯誤、帳號已停用：一律 `401`、錯誤碼 `invalid_credentials`，訊息相同。分成不同訊息會讓人用來確認哪些 email 有帳號。
- 嘗試次數過多：`429`、`too_many_attempts`，由 Nginx 對這個路徑另外限流。

### `POST /api/auth/refresh`

送 `{}`。瀏覽器只在這個路徑帶上 refresh token 的 cookie。成功 `204` 並設定新的 access token；refresh token 無效或過期回 `401`、`refresh_token_invalid`，前端導回登入頁。

### `POST /api/auth/logout`

送 `{}`。刪除資料庫裡的 refresh token、清掉兩個 cookie，回 `204`。

### `GET /api/auth/me`

```json
{ "id": 12, "email": "alice@example.com", "role": "marketing", "team": { "id": 3, "name": "電商行銷" } }
```

前端在載入時呼叫它決定顯示哪些頁面；回 `401` 就導向登入頁。

## 連結

### 連結物件

```json
{
  "code": "aB3xYz",
  "short_url": "https://s.example.com/aB3xYz",
  "original_url": "https://shop.example.com/p/123?utm_source=sms&utm_medium=sms&utm_campaign=autumn",
  "channel": "sms",
  "campaign": "autumn",
  "created_by": { "id": 12, "email": "alice@example.com" },
  "team_id": 3,
  "created_at": "2026-10-06T08:30:00Z",
  "expires_at": "2027-01-04T08:30:00Z",
  "disabled": false,
  "clicks_total": 1520
}
```

- `channel` 是 `sms`、`email`、`ad` 其中之一。
- `short_url` 用 `SHORT_DOMAIN` 組出來，前端直接顯示與複製。
- `clicks_total` 來自 `click_daily` 的加總，跟點擊統計一樣是最終一致，可能落後幾秒到一分鐘。

### `POST /api/links`

```json
{
  "original_url": "https://shop.example.com/p/123?utm_source=sms&utm_medium=sms&utm_campaign=autumn",
  "channel": "sms",
  "campaign": "autumn",
  "expires_at": "2027-01-04T08:30:00Z",
  "code": "autumn1"
}
```

- `expires_at` 與 `code` 可省略：省略 `expires_at` 用預設的 90 天，省略 `code` 由後端以亂數產生 6 碼。
- **UTM 參數由前端組進 `original_url`**，後端照收照存。理由是組 UTM 的規則（參數順序、原本網址已帶參數時怎麼合併）只需要寫一次；放在後端，Go 與 Laravel 就要各寫一份並保持逐字一致。
- 成功：`201`，`Location: /api/links/{code}`，body 是連結物件。

| 錯誤情況 | 狀態碼 | 錯誤碼 |
| --- | --- | --- |
| `original_url` 不是 `http` 或 `https` 的絕對網址 | `422` | `invalid_url` |
| `code` 不是 6 到 7 碼的 Base62 | `422` | `invalid_code` |

自訂短碼不需要保留字清單：長度限制已經排除 `api`、`admin` 這類短字，而後端的固定路由都放在 `/-/` 底下，不會和任何 Base62 短碼同名（見 [系統設計](system-design.md)〈健康檢查〉）。
| `code` 已被使用 | `409` | `code_taken`（放在 `errors` 裡，頂層 `code` 是 `conflict`） |
| `expires_at` 早於現在 | `422` | `invalid_expiry` |
| `channel` 不在允許的值裡 | `422` | `invalid_channel` |

一個請求可能同時有多個欄位錯誤，`422` 的回應在 `errors` 裡逐欄列出，見〈錯誤格式〉。

### `POST /api/links/batch`

```json
{ "original_url": "https://...", "channel": "sms", "campaign": "autumn", "count": 5000, "expires_at": "2027-01-04T08:30:00Z" }
```

- 產生 `count` 條 7 碼的連結（每個收件人一條，7 碼的理由見 [系統設計](system-design.md)〈長度〉），不接受自訂短碼。
- `count` 上限 10,000（假設，依一次簡訊發送的名單大小調整）；超過回 `422`、`batch_too_large`。
- 成功：`201`，body 是 `{ "items": [<連結物件>...] }`，順序就是產生的順序，前端依序對應到收件人名單。
- 整批在同一個交易裡寫入：全部成功或全部不寫，前端不必處理「建了一半」的情況。

### `GET /api/links`

| 查詢參數 | 說明 |
| --- | --- |
| `campaign` | 只列這個活動 |
| `channel` | 只列這個管道 |
| `include_disabled` | `true` 時包含已停用的連結，預設不包含 |
| `cursor`、`limit` | 分頁，見〈分頁〉 |

依建立時間由新到舊排列，範圍依角色。

### `GET /api/links/{code}`

回連結物件；範圍外或不存在都回 `404`、`link_not_found`。

### `PATCH /api/links/{code}`

```json
{ "disabled": true }
{ "expires_at": "2027-03-01T00:00:00Z" }
```

只接受 `disabled` 與 `expires_at`，成功回 `200` 與更新後的連結物件。其他欄位出現時回 `422`、`field_not_editable`。

**`original_url` 不能修改**：短網址已經印在簡訊與廣告裡，改目的地會讓舊的收件人被導到別處，而且同一個短碼的點擊統計會混著兩個目的地。要換目的地就建立一條新連結、停用舊的。

## 點擊統計

點擊統計讀 `click_daily`，是最終一致的彙總，可能落後幾秒到一分鐘。日期以 UTC 的日為單位。

### `GET /api/stats/links/{code}`

查詢參數 `from`、`to`（日期，例如 `2026-10-01`，含頭含尾，預設最近 30 天）。

```json
{ "code": "aB3xYz", "total": 1520, "daily": [ { "day": "2026-10-01", "clicks": 230 } ] }
```

### `GET /api/stats/campaigns`

查詢參數 `name`（活動名稱，必填）、`from`、`to`。活動名稱放在查詢參數而不是路徑：名稱是行銷輸入的自由文字，可能有空白與中文，放進路徑要處理編碼，而且同一個名稱可能在不同團隊各出現一次。

```json
{ "campaign": "autumn", "total": 8200, "by_channel": [ { "channel": "sms", "clicks": 5100 }, { "channel": "email", "clicks": 3100 } ] }
```

### `GET /api/stats/campaigns/export`

參數同上，回 `text/csv`，每列是一條連結的點擊數。

## 維運與使用者管理

### 請求 log

逐筆的轉址請求（時間、短碼、狀態碼、延遲、來源 IP、User-Agent、處理的後端）不提供 API，也不寫進 PostgreSQL。資料來源是入口 Nginx 的 access log 與兩個後端的結構化 log，在[開發順序](roadmap.md)〈階段三〉送進 log 系統，工程師在 Grafana 查；後台的工程師頁面只提供連到 Grafana 的入口。

不寫進資料庫的理由：轉址是流量最大、最在意延遲的路徑，每個請求多一筆資料庫寫入會直接拖慢它，也會讓壓測量到的是這筆寫入的成本。`404` 的請求沒有對應的連結，本來就不會出現在 `click_events`。

log 的欄位現在先定下來，因為壓測與比對兩個後端都要用：

| 欄位 | 來源 |
| --- | --- |
| 時間、方法、路徑、狀態碼 | Nginx access log |
| 處理的後端 | Nginx 的 `$upstream_addr` 與回應的 `X-Backend` |
| 總延遲、後端延遲 | Nginx 的 `$request_time`、`$upstream_response_time` |
| 來源 IP、User-Agent | Nginx access log |
| 短碼、是否已到期 | 後端的結構化 log |

來源 IP 與 User-Agent 屬於個人資料，Grafana 上只開給 `engineer` 與 `admin`，與 [登入與權限](auth-and-roles.md) 的角色對照一致。

### 使用者與團隊

- `GET /api/users`：列出使用者，支援分頁與 `team_id`、`role` 篩選。
- `POST /api/users`：`{ "email", "role", "team_id", "password" }`，成功 `201`；email 已存在回 `409`，`errors` 裡是 `email_taken`。
- `PATCH /api/users/{id}`：接受 `role`、`team_id`、`disabled`。停用或改角色時，刪除該使用者的 refresh token，並讓他手上還沒過期的 access token 立刻失效，做法見 [登入與權限](auth-and-roles.md)〈效期與撤銷〉。
- `GET /api/teams`、`POST /api/teams`：`{ "name" }`。

## 狀態碼

| 狀態碼 | 用在 |
| --- | --- |
| `200` | 讀取、`PATCH` 成功 |
| `201` | 建立成功，帶 `Location` |
| `204` | 成功但沒有內容（登入、登出、換發 token） |
| `302` | 轉址 |
| `400` | body 不是合法的 JSON、查詢參數格式錯誤（例如 `cursor` 解不開） |
| `401` | 沒登入、token 過期或無效 |
| `403` | 已登入，但角色沒有這個操作的權限 |
| `404` | 資源不存在，或在使用者的範圍外 |
| `409` | 與既有資料衝突（短碼、email 已被使用） |
| `415` | 有 body 但不是 `application/json` |
| `422` | JSON 格式正確，但欄位值不合規則 |
| `429` | 超過限流 |
| `500` | 後端未預期的錯誤 |
| `503` | 依賴的服務不可用（例如資料庫連不上） |

`400` 與 `422` 的分界：解析不了請求（壞掉的 JSON、型別錯誤）是 `400`；解析得了但值不被接受是 `422`。

## 錯誤格式

錯誤回應採用 RFC 9457（Problem Details），`Content-Type: application/problem+json`：

```json
{
  "type": "urn:tinyurl:error:validation_failed",
  "title": "請求的欄位不合規則",
  "status": 422,
  "detail": "有 2 個欄位不合規則",
  "code": "validation_failed",
  "errors": [
    { "field": "original_url", "code": "invalid_url" },
    { "field": "code", "code": "code_taken" }
  ]
}
```

- **`code` 是給程式判斷的**，前端依它決定顯示什麼；`title` 與 `detail` 是給人看的說明，兩個後端的措辭可以不同，前端不拿它們做判斷。
- `type` 由 `code` 組出（`urn:tinyurl:error:<code>`）。用 URN 而不是網址，因為沒有要提供一頁說明文件，而 RFC 9457 要求 `type` 是 URI。
- `errors` 出現在跟特定欄位有關的錯誤：欄位驗證失敗（`422`）與欄位值和既有資料衝突（`409`，例如短碼或 email 已被使用），逐欄列出；`field` 是請求 JSON 裡的欄位名稱。前端只要回應裡有 `errors`，就在對應的欄位旁顯示，不必依狀態碼分兩套處理。

### 錯誤碼清單

| 錯誤碼 | 狀態碼 | 意思 |
| --- | --- | --- |
| `invalid_json` | `400` | body 不是合法的 JSON |
| `invalid_parameter` | `400` | 查詢參數格式錯誤 |
| `unauthenticated` | `401` | 沒登入或 access token 無效 |
| `invalid_credentials` | `401` | 登入失敗 |
| `refresh_token_invalid` | `401` | refresh token 無效或過期 |
| `forbidden` | `403` | 角色沒有權限 |
| `link_not_found` | `404` | 連結不存在或不在範圍內 |
| `user_not_found` | `404` | 使用者不存在 |
| `unsupported_media_type` | `415` | 不是 `application/json` |
| `validation_failed` | `422` | 欄位驗證失敗，細節在 `errors` |
| `conflict` | `409` | 欄位值和既有資料衝突，細節在 `errors` |
| `invalid_url`、`invalid_code`、`invalid_expiry`、`invalid_channel`、`field_not_editable`、`batch_too_large` | `422`（放在 `errors` 裡） | 各欄位的驗證錯誤 |
| `code_taken`、`email_taken` | `409`（放在 `errors` 裡） | 短碼、email 已被使用 |
| `too_many_attempts`、`rate_limited` | `429` | 登入嘗試過多、一般限流 |
| `internal_error` | `500` | 未預期的錯誤 |
| `dependency_unavailable` | `503` | 資料庫或快取不可用 |

新增錯誤碼時同時加進這張表與契約測試；前端遇到不認得的錯誤碼，依狀態碼顯示通用訊息。

## 分頁

列表端點用游標分頁：

```json
{ "items": [ ... ], "next_cursor": "48213" }
```

- 請求帶 `limit`（預設 50，上限 100）與上一頁回應的 `cursor`；`next_cursor` 是 `null` 代表沒有下一頁。
- **游標的內容是上一頁最後一筆的 `id`**，排序是 `id` 由大到小。`id` 由 PostgreSQL 的同一個序列產生、只增不減，所以 `id` 的順序就是建立的順序。
- 游標必須兩個後端都看得懂：第一頁可能是 Go 回的、第二頁落在 Laravel。所以游標的格式寫死在這裡，不用任何框架的預設分頁格式（Laravel 的 `cursorPaginate` 會產生自己編碼的游標，Go 那邊解不開）。前端把游標當成不透明的字串，原樣送回。
- 選游標而不是頁碼：用頁碼時，翻頁的同時有人建立新連結，下一頁會重複出現上一頁最後幾筆；游標依 `id` 接續，不受新增影響。

## 前端的頁面路由

兩個前端都用 Vue Router 的 history 模式，網址直接打開時由各自容器的 Nginx 回 `index.html`。

### 前台（web/portal，APP_DOMAIN 根路徑）

| 路徑 | 頁面 | 呼叫的 API |
| --- | --- | --- |
| `/login` | 登入 | `POST /api/auth/login` |
| `/` | 導向 `/links` | — |
| `/links` | 我的連結（範圍依角色） | `GET /api/links` |
| `/links/new` | 建立連結 | `POST /api/links`、`POST /api/links/batch` |
| `/links/:code` | 連結的設定與簡單點擊數 | `GET /api/links/{code}`、`PATCH /api/links/{code}` |

### 後台（web/admin，`/admin/` 底下）

| 路徑 | 頁面 | 呼叫的 API | 角色 |
| --- | --- | --- | --- |
| `/admin/` | 依角色導向行銷或工程師的首頁 | `GET /api/auth/me` | 已登入 |
| `/admin/campaigns` | 活動成效與管道比較，`?name=` 選活動 | `GET /api/stats/campaigns` | 行銷、`admin` |
| `/admin/links/:code` | 單一連結的點擊趨勢 | `GET /api/stats/links/{code}` | 行銷、`admin` |
| `/admin/ops` | 連到 Grafana 的入口（請求 log、錯誤率、兩個後端的對照） | — | `engineer`、`admin` |
| `/admin/users` | 使用者與角色管理 | `/api/users`、`/api/teams` | `admin` |

**登入頁只有一個**，在前台的 `/login`。後台遇到 `401` 時導向 `/login?next=/admin/...`，登入後依 `next` 回到原頁。`next` 只接受以 `/` 開頭、而且不是 `//` 開頭的路徑，否則一律回到 `/`：不檢查的話，`?next=https://evil.example` 可以讓登入頁把人導到外部網站（open redirect）。

頁面上的權限只決定顯示什麼；真正的權限檢查在後端，前端隱藏按鈕不算保護。

## 延後的功能

- **後台依裝置與地區拆分**：目前不做，觸發條件與回溯範圍見 [web/admin](../web/admin/README.md)〈延後的功能〉。

## 不在範圍內

- **對外的 API 與版本號**：消費者只有自己的兩個前端，所以網址不帶版本號。觸發條件是開放給外部整合方或發佈 SDK：那時消費者不跟著部署，要在第一次對外發布前決定版本策略，並把已發布的端點視為只能向下相容地新增。
- **對外的狀態頁**：見 [系統設計](system-design.md)〈健康檢查〉的〈對外的狀態頁〉。
