# web/portal（前台）

行銷團隊產生短網址的地方，需要登入。

## 功能

- 登入 / 登出。
- 建立短網址：輸入原始網址，選擇管道（簡訊、Email、廣告）與活動名稱，可選擇自訂短碼與到期時間。
- 自動附加 UTM 參數（`utm_source`、`utm_medium`、`utm_campaign`），讓電商站的分析工具也能辨識來源。
- 列出自己（或團隊，依角色）建立的連結，可停用、修改到期時間。
- 每條連結旁顯示簡單的點擊數，詳細數據到後台看。

## 角色

`marketing` 只看到自己建立的連結，`marketing_lead` 與 `admin` 看得到整個團隊的連結，`engineer` 唯讀。完整對照見 [docs/auth-and-roles.md](../../docs/auth-and-roles.md)。

## Docker

`Dockerfile` 是兩階段建置：Node 建置靜態檔，Nginx 提供靜態檔。框架選定之前無法建置。
