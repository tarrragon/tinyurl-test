#!/bin/bash
# 同一個容器裡跑 PHP-FPM 與 Nginx。任一個程序結束，就停掉另一個並讓容器結束，
# 交給 compose 的重啟策略或健康檢查處理；只剩一半在跑的容器會讓請求失敗卻看不出原因。
# 用 bash 而不是 Alpine 的 busybox sh：busybox 1.37 的 wait -n 在子程序被訊號殺掉
# （kill -9、OOM）時不會返回，正好漏掉崩潰這個最需要偵測的情況。

# docker stop 只把停機訊號送給 PID 1（這支 shell），shell 不會自己轉給子程序。
# php-fpm 官方映像檔設了 STOPSIGNAL SIGQUIT，所以 docker stop 送來的是 QUIT 而不是 TERM；
# PID 1 對沒有註冊處理器的訊號一律忽略，漏接哪一個，docker stop 就要等到逾時強制終止。
# 轉送 SIGQUIT：兩者的 graceful stop。PHP-FPM 要等進行中的請求，還得設 process_control_timeout
# （見 php-fpm-overrides.conf，預設 0 是不等）；Nginx 收到 SIGQUIT 本身就會等。
stop() {
    kill -QUIT "$fpm" "$web" 2>/dev/null
}
trap stop QUIT TERM INT

php-fpm -F &
fpm=$!
# -e stderr：Nginx 在讀設定檔之前就會開錯誤 log，預設路徑 www-data 寫不進去
nginx -e stderr -g 'daemon off;' &
web=$!

wait -n
status=$?
stop
wait
exit "$status"
