# web/admin（後台）

看點擊數據與系統 log 的地方，需要登入，行銷與工程師看到的頁面不同。

## 功能

行銷（`marketing`、`marketing_lead`）：

- 活動成效：每條連結、每個活動的點擊數，依時間、管道（簡訊 / Email / 廣告）、裝置與地區拆分。
- 管道比較：同一個活動在不同管道的點擊數。
- 匯出報表（`marketing_lead` 以上）。

工程師（`engineer`）：

- 請求 log：逐筆的轉址請求（時間、短碼、回應狀態、延遲、來源 IP、User-Agent）。
- 錯誤與異常：找不到短碼的請求、被限流的請求、後端錯誤率與延遲。
- 比對 Go 與 Laravel 兩個後端的表現。

管理者（`admin`）：

- 使用者與角色管理。

完整對照見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)。

## Docker

與前台相同，Vue 3 + Vite + TypeScript 的 SPA，兩階段建置，見 [web/README.md](../README.md)。後台掛在 `/admin/` 下，Vite 的 `base` 與 Vue Router 都要設這個前綴。
