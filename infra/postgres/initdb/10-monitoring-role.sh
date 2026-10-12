#!/bin/sh
# postgres_exporter 用的唯讀帳號：只給 PostgreSQL 內建的 pg_monitor 角色（讀統計視圖與設定），
# 不沿用應用程式的帳號。initdb/ 只在資料目錄是空的時候執行；既有的資料庫用 infra/observability/README.md 的指令補建。
set -eu
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  -v pw="$POSTGRES_EXPORTER_PASSWORD" <<'SQL'
CREATE ROLE postgres_exporter LOGIN PASSWORD :'pw';
GRANT pg_monitor TO postgres_exporter;
SQL
