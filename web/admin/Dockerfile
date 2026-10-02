# syntax=docker/dockerfile:1
# Vue 3 + Vite：`npm run build` 把靜態檔輸出到 dist/

# ---- 建置階段 ----
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- 執行階段：Nginx 提供靜態檔 ----
FROM nginx:1.27-alpine
# TODO：加入 SPA 路由回退的設定檔（try_files ... /index.html），見 web/README.md
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
