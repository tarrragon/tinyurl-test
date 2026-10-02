# 登入與權限

前台與後台都要登入。權限以角色區分，兩個後端（Go 與 Laravel）必須用同一套角色定義與同一種登入憑證，Nginx 才能把請求交給任一個後端而結果一致。

## 角色

| 角色        | 對象             | 前台（web/portal）               | 後台（web/admin）                                   |
| ----------- | ---------------- | -------------------------------- | --------------------------------------------------- |
| `marketing` | 行銷團隊成員     | 建立連結、管理自己建立的連結     | 看自己團隊連結的點擊數據（依管道、活動、時間）      |
| `marketing_lead` | 行銷主管    | 管理團隊所有連結                 | 看全部活動數據、匯出報表                            |
| `engineer`  | 工程師           | 唯讀（排查問題時查連結設定）     | 看系統 log、錯誤率、延遲、轉址失敗紀錄；不看行銷報表的匯出 |
| `admin`     | 系統管理者       | 全部                             | 全部，加上使用者與角色管理                          |

行銷與工程師在後台看到的東西不同：行銷看的是「活動成效」（點擊數、管道比較、轉換），工程師看的是「系統健康」（請求 log、錯誤、延遲、被限流的請求）。兩者讀同一批點擊事件，但行銷報表只看彙總，工程師看得到逐筆請求的 IP 與 User-Agent，這部分屬於個人資料，所以只開給工程師與管理者。

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
| `jti`     | token 的唯一 ID，撤銷時用                              |

兩個後端的時間判斷要允許相同的小誤差（例如 30 秒），否則一邊接受、一邊拒絕同一個剛到期的 token。

### 效期與撤銷

JWT 簽出去之後，在到期前驗證都會通過，所以撤銷靠以下三件事：

- **access token 短效期**：15 分鐘。
- **refresh token 存在 PostgreSQL**：存雜湊值，效期較長（例如 7 天），用來換新的 access token。登出或停用帳號時刪除它，最晚在 access token 到期後失去存取權。
- **立即撤銷（例如員工離職）**：把 `jti` 寫進 Valkey 的撤銷清單，TTL 設成該 token 剩下的效期，兩個後端驗簽後再查一次清單。

角色變更在下一次換發 access token 時生效，也就是最多延遲 15 分鐘。

### 存放位置

- access token 與 refresh token 都放在 `HttpOnly`、`Secure`、`SameSite=Strict` 的 cookie，前端的 JavaScript 讀不到，XSS 偷不走。
- `Secure` 要求 HTTPS。瀏覽器把 `http://localhost` 視為安全來源而放行，但用虛擬機的 IP 以 HTTP 連線時 cookie 不會被設定，那時要讓 Nginx 提供自簽憑證的 HTTPS。
- 前台、後台與 API 都在 `APP_DOMAIN`，cookie 會自動帶上。
- cookie **不設 `Domain` 屬性**，讓它只屬於設定它的那個主機名稱（host-only），短網域收不到。這一點不能只靠 `SameSite`：短網域與 `APP_DOMAIN` 若是同一個主網域下的子網域（例如 `s.shop.tw` 與 `app.shop.tw`），瀏覽器把兩者視為同站（same-site），`SameSite` 不會擋。
- refresh token 的 cookie 把 `Path` 限制在換發 token 的端點（例如 `/api/auth/refresh`），其他請求不會帶上它。
- `SameSite=Strict` 擋掉跨站請求帶 cookie；API 另外只接受 `Content-Type: application/json`，作為 CSRF 的第二層防護。

公開的轉址路徑 `GET /{code}` 不需要登入。
