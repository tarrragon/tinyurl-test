#!/bin/sh
# 從 docs/data-model.md 依序取出 SQL 區塊，印出規格的 schema（建表順序照文件，文件依外鍵參照順序排列）。
# 規格是兩套 migration 共同的比對基準，也是 Atlas 產生 Go migration 時的目標狀態。
#
# 用法（在 repo 根目錄，或任何位置）：
#   scripts/spec-schema.sh > /tmp/spec.sql
#   scripts/spec-schema.sh | docker compose exec -T postgres psql -U tinyurl -d <空資料庫> -v ON_ERROR_STOP=1

set -eu
cd "$(dirname "$0")/.." || exit 1
awk '/^```sql/{f=1;next}/^```/{f=0}f' docs/data-model.md
