#!/bin/sh
# 壓測、大量資料的實驗、效能的前後比較開始之前，在主機上確認它是安靜的：
# 每顆核心的 load 與 Docker 資料所在磁碟的剩餘空間。超過門檻時結束碼 1，量到的數字不能和其他時間比較。
#
# 一定要在主機上跑，不在容器裡跑：macOS 上的 Docker 跑在虛擬機裡，虛擬機裡的 load 看不到 Mac 上
# 其他程式的負載（見 infra/observability/README.md〈在 macOS 上跑時看不到 Mac 本身〉）。
#
# 用法：
#   scripts/host-preflight.sh && k6 run ...
#   PREFLIGHT_MAX_LOAD_PER_CORE=0.5 PREFLIGHT_MIN_FREE_GB=50 scripts/host-preflight.sh
#   DOCKER_DATA_PATH=/srv/docker scripts/host-preflight.sh   # Docker 資料不在預設位置時

set -eu
max_load_per_core=${PREFLIGHT_MAX_LOAD_PER_CORE:-1.0}
min_free_gb=${PREFLIGHT_MIN_FREE_GB:-20}

case "$(uname -s)" in
  Darwin)
    load1=$(sysctl -n vm.loadavg | awk '{print $2}')
    cores=$(sysctl -n hw.ncpu)
    # OrbStack 與 Docker Desktop 的虛擬機磁碟映像檔放在使用者的家目錄底下
    data_path=${DOCKER_DATA_PATH:-$HOME}
    ;;
  Linux)
    load1=$(cut -d' ' -f1 /proc/loadavg)
    cores=$(nproc)
    data_path=${DOCKER_DATA_PATH:-/var/lib/docker}
    ;;
  *)
    echo "不支援的系統：$(uname -s)" >&2
    exit 2
    ;;
esac

free_gb=$(df -Pk "$data_path" | awk 'NR==2 {printf "%d", $4 / 1024 / 1024}')
load_per_core=$(awk -v l="$load1" -v c="$cores" 'BEGIN {printf "%.2f", l / c}')

status=0
echo "load1 ${load1}（${cores} 顆核心，每顆 ${load_per_core}，上限 ${max_load_per_core}）"
if awk -v x="$load_per_core" -v m="$max_load_per_core" 'BEGIN {exit !(x > m)}'; then
  echo "  主機忙碌：每顆核心的 load 超過上限" >&2
  status=1
fi
echo "${data_path} 所在磁碟剩 ${free_gb} GB（下限 ${min_free_gb} GB）"
if [ "$free_gb" -lt "$min_free_gb" ]; then
  echo "  磁碟空間不足：大量寫入可能讓 Docker 的虛擬機被停掉" >&2
  status=1
fi
exit "$status"
