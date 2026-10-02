# syntax=docker/dockerfile:1
FROM nginx:1.27-alpine
# 負載平衡、限流與路由設定放在 conf.d/，尚未撰寫，目前使用 Nginx 預設設定
COPY conf.d/ /etc/nginx/conf.d/
EXPOSE 80 443
