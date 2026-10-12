#!/bin/sh
# 對 compose 的 PostgreSQL 執行 infra/postgres/seed/seed.sql：清空業務表、重建種子資料。
# 兩個後端共用這一份；Laravel 的 php artisan db:seed 也是執行同一個檔案。
#
# 用法（在 repo 根目錄，或任何位置）：
#   scripts/seed.sh                                        # 預設量：300 條一般連結、6 次發送 × 2,000 位收件人
#   SEED_SENDS=100 SEED_RECIPIENTS=10000 scripts/seed.sh   # 壓測或成長實驗用的量
#   SEED_DB=tinyurl_test scripts/seed.sh                   # 換資料庫（預設是 .env 的 POSTGRES_DB）

set -eu
cd "$(dirname "$0")/.." || exit 1
. ./.env

{
  echo "SET seed.links = '${SEED_LINKS:-}';"
  echo "SET seed.sends = '${SEED_SENDS:-}';"
  echo "SET seed.recipients = '${SEED_RECIPIENTS:-}';"
  cat infra/postgres/seed/seed.sql
} | docker compose exec -T postgres psql -q -o /dev/null -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "${SEED_DB:-$POSTGRES_DB}"
