#!/bin/bash
# block-helper.sh — 透過 ipset 管理 HTTP 封鎖列表
#
# 用法：
#   block-helper.sh add <IP> <timeout_seconds>
#   block-helper.sh del <IP>
#   block-helper.sh list
#
# 安裝 sudoers (以 jc 使用者為例)：
#   echo "jc ALL=(ALL) NOPASSWD: /usr/local/bin/block-helper.sh" \
#     | sudo tee /etc/sudoers.d/http-block-helper

IPSET_NAME="http_blocklist"

case "${1:-}" in
    add)
        if [ -z "$2" ] || [ -z "$3" ]; then
            echo "Usage: $0 add <IP> <timeout>" >&2
            exit 1
        fi
        ipset add "$IPSET_NAME" "$2" timeout "$3" 2>/dev/null || {
            # set 不存在時自動建立（通常不該發生，但容錯）
            ipset create "$IPSET_NAME" hash:ip timeout 3600 2>/dev/null
            ipset add "$IPSET_NAME" "$2" timeout "$3"
        }
        ;;
    del)
        if [ -z "$2" ]; then
            echo "Usage: $0 del <IP>" >&2
            exit 1
        fi
        ipset del "$IPSET_NAME" "$2" 2>/dev/null || true
        ;;
    list)
        ipset list "$IPSET_NAME" 2>/dev/null || echo "The set with the name: $IPSET_NAME does not exist."
        ;;
    *)
        echo "Usage: $0 {add|del|list} [args...]" >&2
        exit 1
        ;;
esac
