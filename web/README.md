# web

前端網頁，分成兩個獨立的應用：

- [portal/](portal/README.md)：前台，行銷團隊登入後建立與管理短網址。
- [admin/](admin/README.md)：後台，看點擊數據與 log、管理使用者與角色。

兩者分開建置、分開部署：前台是每天操作的工具，後台的報表頁面較重，分開之後後台的改版不會影響前台。兩者都呼叫同一組後端 API（`/api/...`），由 Nginx 轉給 Go 或 Laravel。

## 技術選擇：Vue 3 + Vite + TypeScript（SPA）

前台與後台都是登入後才能用的內部工具，不需要 SEO，所以打包成靜態檔、交給 Nginx 提供，不需要伺服器端渲染。前端只透過 JSON API（`/api/...`）跟後端溝通：Go 與 Laravel 在同一個 upstream 輪流接請求，前端不能依賴任何一個後端的頁面渲染機制（例如 Inertia 或 Blade）。

- 路由：Vue Router，history 模式。
- 狀態管理：Pinia（登入使用者、角色）。
- 圖表（後台）：ECharts。
- UI 元件庫：後台的表格與表單用現成元件庫（Element Plus 或 Naive UI，開工時選定）。

## 建置與部署

1. Node 執行 `npm ci` 與 `npm run build`，Vite 把靜態檔輸出到 `dist/`。
2. 把 `dist/` 複製進 Nginx 映像檔提供靜態檔。

兩個要在建專案時設定的地方：

- **後台的路徑前綴**：後台由外層 Nginx 掛在 `/admin/` 下，`web/admin` 的 `vite.config.ts` 要設 `base: '/admin/'`，Vue Router 也用同一個前綴，否則打包出來的 JS 與 CSS 會從根路徑載入而找不到。
- **SPA 的路由回退**：history 模式下，使用者直接開啟 `/links/123` 這類網址時，Nginx 找不到對應的檔案，要回傳 `index.html` 交給 Vue Router 處理（`try_files $uri $uri/ /index.html;`）。這段設定放在各自容器內的 Nginx 設定檔。

## 共用程式（之後決定）

前台與後台都需要登入流程與呼叫 API 的程式。兩邊各寫一份可以先開工；重複變多時，改成 npm workspaces，在 `web/` 底下加一個 `packages/shared` 讓兩邊共用。
