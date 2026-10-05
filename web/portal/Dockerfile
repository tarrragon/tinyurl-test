# syntax=docker/dockerfile:1
# Vue 3 + Vite：`npm run build` 把靜態檔輸出到 dist/

# ---- 建置階段 ----
FROM node:24-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- 執行階段：Nginx 提供靜態檔 ----
FROM nginx:1.30-alpine
# SPA 路由回退：找不到檔案的路徑一律回 index.html，交給 Vue Router，見 web/README.md
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
