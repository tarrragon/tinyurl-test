# web

前端網頁，分成兩個獨立的應用：

- [portal/](portal/README.md)：前台，行銷團隊登入後建立與管理短網址。
- [admin/](admin/README.md)：後台，看點擊數據與 log、管理使用者與角色。

兩者分開建置、分開部署：前台是每天操作的工具，後台的報表頁面較重，分開之後後台的改版不會影響前台。兩者都呼叫同一組後端 API（`/api/...`），由 Nginx 轉給 Go 或 Laravel。

## 建置方式（暫定）

框架還沒選定。兩個 `Dockerfile` 先假設：

1. 用 Node 執行 `npm ci` 與 `npm run build`，產出靜態檔到 `dist/`。
2. 把 `dist/` 複製進 Nginx 映像檔提供靜態檔。

選了需要伺服器端執行的框架（例如 SSR）時，第二階段要改成 Node 執行環境。
