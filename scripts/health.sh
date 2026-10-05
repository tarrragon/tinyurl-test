#!/bin/sh
# 列出 compose 裡每個服務的執行與健康狀態，給 SSH 進去手動查、或給排程與監控呼叫。
#
# 用法（在 repo 根目錄，或任何位置）：
#   scripts/health.sh
#   ssh <主機> 'cd <repo 路徑> && scripts/health.sh'
#
# 結束碼：
#   0  全部服務在跑，設了 healthcheck 的都是 healthy
#   1  有服務沒在跑（沒啟動、已結束），或有服務 unhealthy
#   2  沒有 1 的情況，但有服務還在 health: starting（剛啟動或剛部署，還在 start_period 內）
# 分成 1 與 2，讓呼叫的一方自己決定「啟動中」算不算失敗：部署流程可以等它變 0，排程檢查可以把 2 當成警告。

set -u
cd "$(dirname "$0")/.." || exit 1

# compose 檔裡定義的所有服務，用來找出「定義了但沒在跑」的那些（docker compose ps 不會列出沒有容器的服務）
services=$(docker compose config --services) || exit 1

worst=0
printf '%-10s %-10s %s\n' SERVICE STATE HEALTH
for svc in $services; do
    # -a 讓已結束的容器也列出來；沒有容器時兩個欄位都是空的
    line=$(docker compose ps -a --format '{{.State}} {{.Health}}' "$svc" 2>/dev/null | head -n 1)
    state=${line%% *}
    health=${line#* }
    [ "$health" = "$line" ] && health=""
    [ -z "$state" ] && state="missing"

    if [ "$state" != "running" ] || [ "$health" = "unhealthy" ]; then
        code=1
    elif [ "$health" = "starting" ]; then
        code=2
    else
        # 沒設 healthcheck 的服務（health 欄是空的）只看它有沒有在跑
        code=0
    fi

    printf '%-10s %-10s %s\n' "$svc" "$state" "${health:--}"
    # 1 比 2 嚴重：已經是 1 就不被 2 蓋掉
    if [ "$code" -eq 1 ] || { [ "$code" -eq 2 ] && [ "$worst" -eq 0 ]; }; then
        worst=$code
    fi
done

exit "$worst"
