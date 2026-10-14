#!/usr/bin/env bash
# 起 healthchecks，建立登入帳號、ntfy 通知管道與兩個檢查項目，並把兩個 ping 網址寫進
# Alertmanager 讀的私密檔。重複執行只會補上缺的、更新 .env 改過的值，不會重建已有的檢查項目
# （檢查項目的 UUID 就是 ping 網址，重建會讓 Alertmanager 手上的網址失效）。
#   heartbeat    週期 5 分鐘、寬限 5 分鐘，接 Watchdog 的心跳
#   notify-fail  只接 /fail，Alertmanager 送通知失敗時由它推播；平時不收 ping
set -euo pipefail
cd "$(dirname "$0")"

[ -f .env ] || { echo "缺 infra/heartbeat/.env：cp .env.example .env 之後填值" >&2; exit 1; }
set -a; . ./.env; set +a
for v in HC_SECRET_KEY HC_ADMIN_PASSWORD HC_NTFY_TOPIC; do
  [ "${!v}" != change-me ] || { echo ".env 的 $v 還是 change-me" >&2; exit 1; }
done

docker compose up -d
# 第一次啟動要先跑完資料庫遷移，查得到資料表才往下
for _ in $(seq 1 30); do
  docker compose exec -T healthchecks ./manage.py shell -c 'from hc.api.models import Check; Check.objects.count()' >/dev/null 2>&1 && break
  sleep 2
done

codes=$(docker compose exec -T \
  -e HC_ADMIN_EMAIL -e HC_ADMIN_PASSWORD -e HC_NTFY_TOPIC \
  healthchecks ./manage.py shell -c '
import json, os
from datetime import timedelta
from django.contrib.auth.models import User
from hc.accounts.models import Profile, Project
from hc.api.models import Channel, Check

email = os.environ["HC_ADMIN_EMAIL"]
u = User.objects.filter(email=email).first() or User(username="admin", email=email)
u.set_password(os.environ["HC_ADMIN_PASSWORD"])
u.save()
Profile.objects.for_user(u)
p, _ = Project.objects.get_or_create(owner=u, name="tinyurl")

# 心跳中斷（down）以最高優先度推播，恢復（up）以一般優先度
conf = {"topic": os.environ["HC_NTFY_TOPIC"], "url": "https://ntfy.sh", "priority": 5, "priority_up": 3, "token": ""}
ch = Channel.objects.filter(project=p, kind="ntfy").first() or Channel(project=p, kind="ntfy", name="ntfy")
ch.value = json.dumps(conf)
ch.save()

for name, timeout, grace in (("heartbeat", timedelta(minutes=5), timedelta(minutes=5)),
                             ("notify-fail", timedelta(days=1), timedelta(hours=1))):
    c = Check.objects.filter(project=p, name=name).first() or Check(project=p, name=name)
    c.timeout, c.grace = timeout, grace
    c.save()
    c.channel_set.add(ch)
    print("CODE", name, c.code)
')

code() { awk -v n="$1" '$1 == "CODE" && $2 == n { print $3 }' <<<"$codes"; }
hb=$(code heartbeat); nf=$(code notify-fail)
[ -n "$hb" ] && [ -n "$nf" ] || { echo "沒有取得檢查項目的 UUID：" >&2; echo "$codes" >&2; exit 1; }

# Alertmanager 在容器裡，以 host.docker.internal 連到這台機器上發佈的連接埠
secrets=../observability/alertmanager/secrets
mkdir -p "$secrets"
printf 'http://host.docker.internal:%s/ping/%s' "${HC_PORT:-8000}" "$hb" > "$secrets/heartbeat.url"
printf 'http://host.docker.internal:%s/ping/%s/fail' "${HC_PORT:-8000}" "$nf" > "$secrets/notify-fail.url"
echo "已寫入 $secrets/heartbeat.url 與 notify-fail.url；網頁 http://localhost:${HC_PORT:-8000}（帳號 ${HC_ADMIN_EMAIL}）"
