# syntax=docker/dockerfile:1
# 暫定：前端框架尚未選定，假設 `npm run build` 會把靜態檔輸出到 dist/

# ---- 建置階段 ----
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- 執行階段：Nginx 提供靜態檔 ----
FROM nginx:1.27-alpine
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
