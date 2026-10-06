# 登入與權限

前台與後台都要登入。權限以角色區分，兩個後端（Go 與 Laravel）必須用同一套角色定義與同一種登入憑證，Nginx 才能把請求交給任一個後端而結果一致。

## 角色

| 角色        | 對象             | 前台（web/portal）               | 後台（web/admin）                                   |
| ----------- | ---------------- | -------------------------------- | --------------------------------------------------- |
| `marketing` | 行銷團隊成員     | 建立連結、管理自己建立的連結     | 看自己團隊連結的點擊數據（依管道、活動、時間）      |
| `marketing_lead` | 行銷主管    | 管理團隊所有連結                 | 看全部活動數據、匯出報表                            |
| `engineer`  | 工程師           | 唯讀（排查問題時查連結設定）     | 看系統 log、錯誤率、延遲、轉址失敗紀錄；不看行銷報表的匯出 |
| `admin`     | 系統管理者       | 全部                             | 全部，加上使用者與角色管理                          |

行銷與工程師在後台看到的東西不同：行銷看的是「活動成效」（點擊數、管道比較、轉換），工程師看的是「系統健康」（請求 log、錯誤、延遲、被限流的請求）。行銷報表只看點擊的彙總；工程師在 Grafana 看逐筆請求的 log，裡面有 IP 與 User-Agent，這部分屬於個人資料，所以只開給工程師與管理者（log 的來源與欄位見 [API 與路由規格](api.md)〈請求 log〉）。

## 登入憑證：JWT

Go 與 Laravel 在同一個 upstream 輪流接請求，同一個使用者的連續請求可能先後落在兩邊，所以兩個後端都要能簽發、也都要能驗證同一種 token。選 JWT 的理由是驗證不必查資料庫或快取，兩個後端各自用同一把金鑰驗簽即可。

### 簽章

- **演算法固定為 HS256**，金鑰由環境變數 `JWT_SECRET` 提供，兩個後端共用。登入請求可能落在任一個後端，兩邊都是簽發者，都得持有簽章金鑰，所以非對稱演算法（RS256、EdDSA）在這裡沒有多帶來好處；之後若有第三個只負責驗證的服務，再換成非對稱。
- **驗證時寫死允許的演算法**，不讀 token header 裡的 `alg` 決定怎麼驗，避免 `alg: none` 或演算法替換的攻擊。
- 函式庫：Go 用 `golang-jwt/jwt/v5`；Laravel 用 `firebase/php-jwt` 自己寫一個 guard，不用 Laravel 預設的 session guard，兩邊的行為才容易對齊。

### Token 內容（claims）

| claim     | 內容                                                   |
| --------- | ------------------------------------------------------ |
| `iss`     | 固定字串，例如 `tinyurl-test`，兩邊驗證時都檢查        |
| `sub`     | 使用者 ID                                              |
| `role`    | 角色：`marketing`、`marketing_lead`、`engineer`、`admin` |
| `team_id` | 所屬團隊，`marketing` 依它限制看得到的連結             |
| `iat`、`exp` | 簽發與到期時間                                      |

兩個後端的時間判斷要允許相同的小誤差（例如 30 秒），否則一邊接受、一邊拒絕同一個剛到期的 token。

### 效期與撤銷

JWT 簽出去之後，在到期前驗證都會通過，所以撤銷靠以下三件事：

- **access token 短效期**：15 分鐘。
- **refresh token 存在 PostgreSQL**：存雜湊值，效期較長（例如 7 天），用來換新的 access token。登出或停用帳號時刪除它，最晚在 access token 到期後失去存取權。token 本身是 32 bytes 的密碼學安全亂數，以不補 `=` 的 base64url 編碼放進 cookie；資料庫存的是 cookie 字串的 SHA-256（32 bytes 原始值，欄位見 [資料表與程式介面](data-model.md)〈refresh_tokens〉）。用 SHA-256 而不是密碼用的 bcrypt：token 有 256 bits 的亂數，無法暴力猜出，而換發時要依雜湊值查詢，加鹽的 bcrypt 每次結果不同、查不到。
- **立即撤銷（停用帳號、改角色）**：以使用者為單位。在 Valkey 寫入 `revoked_user:{使用者 ID}`，值是撤銷當下的 Unix 時間（秒），TTL 15 分鐘；兩個後端驗簽之後讀這個 key，token 的 `iat` 小於或等於這個值就拒絕。同時刪除這個使用者的 refresh token。

以使用者為單位而不是以 token 為單位，是因為停用帳號時系統不知道這個人手上有哪些 access token 還沒過期，拿不到它們各自的識別碼。TTL 只需要 15 分鐘：15 分鐘後舊的 access token 都已過期，而 refresh token 已經刪除，換發不了新的。

`iat` 與撤銷時間都以秒為單位，同一秒內簽發的 token 也會被拒絕（使用者重新登入一次即可），這是為了不讓同一秒內「先簽發、後撤銷」的 token 漏過。key 的名稱、值的格式與比對方式是兩個後端共用的契約，兩邊要逐字一致。

角色變更也走同一個機制，所以立即生效；使用者重新登入或換發 token 時，拿到的是新角色。

### 密碼

- 兩個後端都用 bcrypt、cost 12。PHP 的 `password_hash` 產生 `$2y$` 開頭的雜湊值，Go 的 `golang.org/x/crypto/bcrypt` 產生 `$2a$` 開頭的；兩種前綴在兩個函式庫都驗得過，所以同一個帳號由哪個後端建立，都能在另一個後端登入。契約測試要涵蓋這一點：一邊建立使用者、另一邊登入。
- 密碼長度 8 到 72 bytes（UTF-8 編碼後計算），不合規則回 `422`、`invalid_password`。上限來自 bcrypt：它只用前 72 bytes，Go 的函式庫對更長的密碼回錯誤，PHP 則直接截斷。在兩邊都先擋下，兩個後端對同一個密碼的結果才一致。

### 存放位置

- access token 與 refresh token 都放在 `HttpOnly`、`Secure`、`SameSite=Strict` 的 cookie，前端的 JavaScript 讀不到，XSS 偷不走。
- `Secure` 要求 HTTPS。瀏覽器把 `http://localhost` 視為安全來源而放行，但用虛擬機的 IP 以 HTTP 連線時 cookie 不會被設定，那時要讓 Nginx 提供自簽憑證的 HTTPS。
- 前台、後台與 API 都在 `APP_DOMAIN`，cookie 會自動帶上。
- cookie **不設 `Domain` 屬性**，讓它只屬於設定它的那個主機名稱（host-only），短網域收不到。這一點不能只靠 `SameSite`：短網域與 `APP_DOMAIN` 若是同一個主網域下的子網域（例如 `s.shop.tw` 與 `app.shop.tw`），瀏覽器把兩者視為同站（same-site），`SameSite` 不會擋。
- refresh token 的 cookie 把 `Path` 限制在換發 token 的端點（例如 `/api/auth/refresh`），其他請求不會帶上它。
- `SameSite=Strict` 擋掉跨站請求帶 cookie；API 另外只接受 `Content-Type: application/json`，作為 CSRF 的第二層防護。

公開的轉址路徑 `GET /{code}` 不需要登入。
