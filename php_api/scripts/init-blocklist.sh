#!/bin/bash
# init-blocklist.sh — 一次性初始化 ipset + iptables
#
# 執行方式：sudo bash init-blocklist.sh
#
# 會建立：
#   1. ipset set: http_blocklist (hash:ip, 預設 timeout 3600)
#   2. iptables 規則: INPUT -p tcp --dport 8000 -m set --match-set http_blocklist src -j DROP

IPSET_NAME="http_blocklist"
PORT="8000"

set -e

echo "=== 初始化封鎖列表 ==="

# 建立 ipset set (若已存在則先刪除重建)
if ipset list "$IPSET_NAME" >/dev/null 2>&1; then
    echo "  ipset set [$IPSET_NAME] 已存在，跳過建立"
else
    echo "  建立 ipset set: $IPSET_NAME"
    ipset create "$IPSET_NAME" hash:ip timeout 3600
fi

# 檢查 iptables 規則是否已存在
if iptables -C INPUT -p tcp --dport "$PORT" -m set --match-set "$IPSET_NAME" src -j DROP 2>/dev/null; then
    echo "  iptables 規則已存在，跳過建立"
else
    echo "  新增 iptables 規則 (port $PORT)"
    iptables -A INPUT -p tcp --dport "$PORT" -m set --match-set "$IPSET_NAME" src -j DROP
fi

echo "=== 完成 ==="
echo ""
echo "目前 set 內容："
ipset list "$IPSET_NAME"
