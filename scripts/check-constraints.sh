#!/bin/sh
# 對 compose 的 PostgreSQL 執行 infra/postgres/verify/constraints.sql：逐條確認約束擋下違規、放行合規的寫入，結束時回滾。
# 兩套 migration 建出的資料庫都跑同一份；有任何一條不符時結束碼是 3（psql 的 ON_ERROR_STOP）。
#
# 用法（在 repo 根目錄，或任何位置）：
#   scripts/check-constraints.sh
#   CHECK_DB=tinyurl_go scripts/check-constraints.sh   # 換資料庫（預設是 .env 的 POSTGRES_DB）

set -eu
cd "$(dirname "$0")/.." || exit 1
. ./.env
docker compose exec -T postgres psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "${CHECK_DB:-$POSTGRES_DB}" \
  < infra/postgres/verify/constraints.sql
