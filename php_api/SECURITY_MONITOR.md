# HTTP 存取監控與攻擊偵測系統

## 概述

在原有會員系統上加入的一層安全監控層。所有 HTTP 請求改經由 `router.php`（前端控制器）處理，在請求前後執行白名單檢查、封鎖查核、日誌記錄及攻擊行為偵測。

封鎖分 **兩層** 防護：
- **PHP 層**：BlocklistManager 檢查資料庫，回傳 403
- **核心層**：BlocklistManager 同步寫入 ipset，iptables 直接 DROP 封鎖 IP 的連線（不經 PHP）

## 目錄結構

```
php_api/
├── router.php                     # 前端控制器 — 所有請求的單一進入點
├── monitor.php                    # CLI 觸發器 — 增量掃描日誌，封鎖違規 IP
├── .env                           # 設定檔 (含資料庫與安全設定)
├── db.php                         # PDO 連線 (router.php / monitor.php 共用)
├── cors.php                       # CORS 標頭
├── login.php / register.php / profile.php / users.php   # 原會員系統端點
├── scripts/
│   ├── block-helper.sh            # ipset 操作腳本 (PHP 透過 sudo 呼叫)
│   └── init-blocklist.sh          # 一次性初始化 ipset + iptables 規則
├── security/
│   ├── LogManager.php             # 存取日誌管理 (JSON Lines 寫入/查詢/增量讀取)
│   ├── BlocklistManager.php       # 封鎖列表管理 (PDO + ipset 雙寫)
│   ├── WhitelistManager.php       # 白名單管理 (PDO 動態 + .env 靜態)
│   ├── CursorManager.php          # 掃描游標管理 (inode + byte position)
│   ├── DetectionEngine.php        # 偵測引擎 — 依序執行所有註冊規則
│   └── Rules/
│       ├── RuleInterface.php      # 規則介面
│       ├── ScanDetectionRule.php  # 規則①掃描偵測 — 4xx 次數閾值
│       └── MaliciousUserAgentRule.php  # 規則②惡意 UA 偵測
├── log/
│   └── access.log                 # 存取日誌 (JSON Lines 格式)
└── SECURITY_MONITOR.md            # 本文件

vue/
├── vue_frontend/                  # 會員前端 (port 5173)
└── vue_admin/                     # 管理員前端 (port 5174)
    ├── index.html
    ├── package.json
    ├── vite.config.js
    └── src/
        ├── main.js
        ├── App.vue
        ├── router/index.js
        ├── api/admin.js
        ├── views/
        │   ├── AdminLogin.vue
        │   └── AdminDashboard.vue
        └── components/
            ├── WhitelistPanel.vue
            ├── BlocklistPanel.vue
            └── ScanControl.vue
```

---

## 資料流程

```
客戶端請求
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│  iptables 層 (核心層)                                   │
│  比對來源 IP 是否在 ipset http_blocklist 中             │
│  ─ 是 → 直接 DROP，不經 PHP                             │
│  ─ 否 → 放行到 PHP                                      │
└─────────────────────────────────────────────────────────┘
   │
   ▼
┌─────────────────────────────────────────────────────────┐
│  router.php (前端控制器)                                │
│                                                         │
│  ① CORS 前置處理                                        │
│     - 設定跨域標頭                                      │
│     - OPTIONS 預檢請求直接回傳 200                      │
│                                                         │
│  ② 白名單檢查                                           │
│     ─ 是 → 跳過所有安全檢查，直接進入路由階段           │
│     ─ 否 → 繼續③                                        │
│                                                         │
│  ③ 封鎖檢查 (PHP 層 403)                                │
│     ─ 被封鎖 → 回傳 403 Forbidden                       │
│     ─ 未封鎖 → 繼續④                                    │
│                                                         │
│  ④ 管理 API 端點路由                                    │
│     ─ URI 以 /admin/ 開頭 → 驗證 ADMIN_KEY              │
│       ─ 驗證失敗 → 回傳 401                             │
│       ─ 驗證成功 → 執行對應管理端點邏輯                 │
│     ─ 非管理端點 → 繼續⑤                                │
│                                                         │
│  ⑤ 路由至現有 PHP 端點                                  │
│     ─ .php 檔案存在 → require 執行                      │
│     ─ 不存在 → 回傳 404                                 │
│                                                         │
│  ⑥ 關閉函數 (register_shutdown_function)                │
│     ─ 寫入存取日誌 (access.log)                         │
│     ─ 跳過白名單 IP                                     │
│     ─ 輕量即時偵測 (只檢查此 IP 的近期行為)             │
│     ─ 違規 → 寫入 DB + 同步寫入 ipset                   │
└─────────────────────────────────────────────────────────┘
   │
   ▼
回應送回客戶端
```

### 重要特性：關閉函數 (Shutdown Function)

即使現有端點程式碼內呼叫了 `exit()` / `die()`，`register_shutdown_function()` 註冊的函數仍然會被執行。PHP 會在輸出緩衝區 flush 完畢後、腳本真正結束前呼叫此函數，因此：

1. 回應已經送回客戶端（不會被阻擋或延遲）
2. 關閉函數執行日誌記錄
3. 關閉函數執行即時偵測，若發現違規則更新 DB + ipset
4. **違規 IP 的下一次請求**才會被阻擋（非即時阻擋當次請求）

---

## 啟動方式

```bash
# 後端
cd php_api && php -S localhost:8000 router.php

# 管理員前端 (選用)
cd vue/vue_admin && npm install && npm run dev
# → 開啟 http://localhost:5174

# 會員前端
cd vue/vue_frontend && npm install && npm run dev
# → 開啟 http://localhost:5173
```

---

## ipset 封鎖機制

### 架構

```
BlocklistManager::block()
  │
  ├─ INSERT INTO blocklist (DB)
  │
  └─ sudo block-helper.sh add {ip} {timeout}
       └─ ipset add http_blocklist {ip} timeout {seconds}
            └─ iptables INPUT -p tcp --dport 8000 -m set --match-set http_blocklist src -j DROP
```

### 安裝 (僅需執行一次)

```bash
# 1. 安裝 ipset
sudo pacman -S ipset

# 2. symlink helper + sudoers
sudo ln -sf ~/practice/php/php-Vue-practice/php_api/scripts/block-helper.sh /usr/local/bin/
echo "$USER ALL=(ALL) NOPASSWD: /usr/local/bin/block-helper.sh" | sudo tee /etc/sudoers.d/http-block-helper

# 3. 初始化 ipset + iptables
sudo bash ~/practice/php/php-Vue-practice/php_api/scripts/init-blocklist.sh
```

### 自動到期

ipset 建立時指定 `timeout 3600`，核心會自動在 timeout 秒後移除該 IP，無需 PHP 介入清理。

### 重啟復原

`router.php` 初始化時自動呼叫 `BlocklistManager::restoreIpset()`，從資料庫讀取所有未過期的封鎖 IP 並重新加入 ipset。

```php
// 僅在 IPSET_ENABLED=true 時執行，靜態旗標確保每 request 只跑一次
$blocklistManager->restoreIpset();
```

### 停用 ipset

`.env` 設定 `IPSET_ENABLED=false` 即回到純資料庫模式，不影響 PHP 層的 403 檢查邏輯。

---

## 偵測規則

### 規則① ScanDetectionRule (掃描偵測)

| 項目 | 說明 |
|---|---|
| 規則名稱 | `scan_detection` |
| 偵測標的 | 短時間內大量 4xx 回應（尤其 404） |
| 預設閾值 | 60 秒內 ≥ 50 次 4xx |
| 參數 | `SCAN_THRESHOLD`（次數）、`SCAN_WINDOW`（秒） |
| 觸發理由範例 | `在 60 秒內產生 55 次 4xx 回應 (閾值: 50)` |

**判斷邏輯：**

```
讀取 access.log 中最近 SCAN_WINDOW 秒內的所有條目
對每個條目檢查 status 是否在 400~499 之間
  是 → 該 IP 計數 +1
所有 IP 計數完成後，對每個 IP：
  計數 ≥ SCAN_THRESHOLD → 標記為違規
```

**全量掃描（monitor.php / admin/monitor/run）：** 掃描所有 IP 的近期行為。

**即時檢查（router.php shutdown）：** 只掃描當前請求的 IP 的近期行為，用 `getRecentEntriesByIp()` 進行，而非讀取全部日誌。

### 規則② MaliciousUserAgentRule (惡意 UA 偵測)

| 項目 | 說明 |
|---|---|
| 規則名稱 | `malicious_ua` |
| 偵測標的 | 使用已知掃描/攻擊工具的 User-Agent |
| 掃描範圍 | 最近 3600 秒（1 小時）的日誌 |
| UA 黑名單 | sqlmap, nikto, nmap, gobuster, dirbuster, wpscan, ffuf, hydra, metasploit, openvas, nessus, acunetix, burpsuite, zap, netsparker, w3af, arachni, masscan 等 30+ 個 |

**判斷邏輯：**

```
讀取 access.log 中最近 3600 秒內的所有條目
對每個條目（同一 IP 只記錄一次）：
  將 UA 轉為小寫
  逐一比對黑名單 pattern
    命中任一 pattern → 標記為違規
```

> 比對使用 `str_contains()`（PHP 8.0+），子字串即命中。例如 UA 為 `sqlmap/1.4.7` 會命中 `sqlmap`。

---

## 增量掃描 (monitor.php)

### cursor-driven

每次執行 `monitor.php` 時，`CursorManager` 記錄上次讀取到的 byte position + 檔案 inode，之後只處理新增的日誌條目：

```
第一次執行：從頭讀 → 處理 → 記錄 position + inode
第二次執行：比對 inode
  ├── 相同 → 從 position 繼續讀新行
  └── 不同 (log rotation) ─→ ① 找出舊檔 (比對 inode)
                                │  ② 從舊 position 讀取殘留條目
                                │  ③ preload 進 LogManager
                                └─→ ④ 從頭讀新檔
```

### log rotation 防漏

rotation 發生時，舊檔 (`access.log.1`) 中可能還有未處理的條目（上次掃描後到 rotation 之間寫入的資料）。

處理流程：
1. `CursorManager::checkRotation()` 回傳舊 cursor 的 inode 與 position
2. `findRotatedFile()` 掃描 `access.log.*` 和 `access.log-*` 比對 inode 找出舊檔
3. `LogManager::getNewEntriesFrom()` 從舊 position 讀取舊檔剩餘條目
4. 透過 `LogManager::setPreloadedEntries()` 預載，讓後續規則的 `getRecentEntries()` 也能看見這些條目
5. 規則分析完成後 `clearPreloadedEntries()` 清除，不影響下次掃描

### 規則仍使用時間窗

增量讀取只減少 I/O，規則內部仍用 `getRecentEntries()` 做 60 秒 / 3600 秒時間窗過濾。因此即使某次掃描只新增了 5 條日誌，規則也能正確檢查過去 60 秒內的所有記錄（含 preload 的舊檔條目）。

### cron 設定

```cron
* * * * * /usr/bin/php /path/to/php_api/monitor.php >> /var/log/security-monitor.log 2>&1
```

---

## 管理 API 端點 + 管理員前端

### API 端點

所有 `/admin/*` 端點需在 HTTP Header 帶入 `Authorization: Bearer {ADMIN_KEY}`，其中 `ADMIN_KEY` 在 `.env` 中設定。

| Method | Endpoint | 功能 | 請求 Body |
|---|---|---|---|
| `GET` | `/admin/whitelist` | 列出白名單（靜態 + 動態） | 無 |
| `POST` | `/admin/whitelist/add` | 新增白名單 IP | `{"ip": "x.x.x.x"}` |
| `POST` | `/admin/whitelist/remove` | 移除動態白名單 IP | `{"ip": "x.x.x.x"}` |
| `GET` | `/admin/blocklist` | 列出目前封鎖的 IP | 無 |
| `POST` | `/admin/blocklist/unblock` | 解除封鎖 IP + ipset | `{"ip": "x.x.x.x"}` |
| `POST` | `/admin/monitor/run` | 手動觸發增量掃描 | 無 |

### 管理員前端

一個獨立的 Vue 3 SPA，位於 `vue/vue_admin/`。

```bash
cd vue/vue_admin && npm run dev
# → 開啟 http://localhost:5174
```

功能：
- **登入頁**：輸入 ADMIN_KEY，通過驗證後存入 sessionStorage
- **儀表板**：
  - 白名單管理表格（新增/移除 IP）
  - 封鎖列表表格（解封 IP）
  - 執行掃描按鈕 + 即時結果
  - 封鎖中 IP 數量統計

---

## 權限與檢查順序

```
                         ┌──────────────┐
                         │  iptables    │
                         │  (核心層)    │
                         └──────┬───────┘
                                │
                    ┌───────────┴───────────┐
                    │ IP in ipset?          │
                    └───────────┬───────────┘
                                │
                   ┌────────────┴────────────┐
                   │ 是                      │ 否
                   ▼                         ▼
            ┌──────────────┐       ┌──────────────────┐
            │ DROP (不經   │       │ 進入 PHP router  │
            │ PHP)         │       └────────┬─────────┘
            └──────────────┘                │
                                  ┌─────────▼─────────┐
                                  │ ① 白名單檢查      │
                                  └─────────┬─────────┘
                                            │
                                ┌───────────┴───────────┐
                                │ 是                    │ 否
                                ▼                       ▼
                         ┌──────────────┐      ┌──────────────────┐
                         │ 放行 (跳過   │      │ ② 封鎖檢查 DB    │
                         │ 所有安全檢查)│      └────────┬─────────┘
                         └──────────────┘               │
                                               ┌────────┴────────┐
                                               │ 是              │ 否
                                               ▼                 ▼
                                        ┌──────────────┐  ┌──────────────┐
                                        │ 回傳 403     │  │ 正常路由     │
                                        └──────────────┘  └──────────────┘
```

**優先順序：** ipset > 白名單 > 封鎖列表（白名單 IP 即使被封鎖也放行，避免管理員誤鎖自己）

---

## 設定參考 (.env)

```env
# === 資料庫 ===
DB_HOST=127.0.0.1
DB_NAME=vue_php_practice
DB_USER=root
DB_PASS=your_password
JWT_SECRET=your_jwt_secret

# === Security Monitor ===
# 靜態白名單 IP（半形逗號分隔，不可透過 API 刪除）
WHITELIST_IPS=127.0.0.1,::1

# 預設封鎖持續時間（秒），預設 3600 = 1 小時
BLOCK_DURATION=3600

# 掃描偵測閾值
SCAN_THRESHOLD=50
SCAN_WINDOW=60

# 管理 API 的 Bearer Token 密鑰
ADMIN_KEY=change_this_to_a_random_secret_key

# ipset 核心層封鎖 (true/false)
IPSET_ENABLED=true
```

---

## 資料庫表格

### blocklist

```sql
CREATE TABLE IF NOT EXISTS blocklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    rule VARCHAR(100) NOT NULL,
    blocked_at INT NOT NULL COMMENT 'Unix 時間戳',
    expires_at INT NOT NULL COMMENT 'Unix 時間戳，逾期自動解除',
    INDEX idx_ip (ip),
    INDEX idx_expires (expires_at)
);
```

### whitelist_dynamic

```sql
CREATE TABLE IF NOT EXISTS whitelist_dynamic (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### scan_cursors

```sql
CREATE TABLE IF NOT EXISTS scan_cursors (
    id VARCHAR(50) PRIMARY KEY COMMENT '游標名稱，例如 monitor_full',
    filename VARCHAR(500) NOT NULL,
    inode BIGINT NOT NULL DEFAULT 0 COMMENT '檔案 inode，用於偵測 rotation',
    position BIGINT NOT NULL DEFAULT 0 COMMENT '已讀取的 byte 位置',
    file_size BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## 資料檔案格式

### access.log（JSON Lines，一行一筆）

```json
{"time":1783910795,"ip":"192.168.1.5","method":"GET","uri":"/login.php","status":200,"ua":"Mozilla/5.0"}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `time` | int | Unix 時間戳 |
| `ip` | string | 請求來源 IP |
| `method` | string | HTTP 方法 |
| `uri` | string | 請求路徑 |
| `status` | int | HTTP 狀態碼 |
| `ua` | string | User-Agent 字串 |

---

## CLI 掃描腳本

```bash
cd php_api && php monitor.php
```

輸出範例：

```
[2026-07-16 10:30:00] 開始安全掃描...
  新增日誌: 12 筆
  發現 1 筆違規:
    - [scan_detection] 10.0.0.5: 在 60 秒內產生 55 次 4xx 回應 (閾值: 50)
[2026-07-16 10:30:00] 掃描完成
```

---

## 除錯指南

### 1. 管理 API 回傳 404

確認 URI 路徑是否完全匹配（含結尾斜線）。目前僅支援精確匹配。

```
/admin/whitelist      ✓
/admin/whitelist/     ✗
/Admin/whitelist      ✗
```

### 2. IP 被封鎖但請求仍通過

檢查該 IP 是否在白名單中：

```bash
curl -s http://localhost:8000/admin/whitelist -H 'Authorization: Bearer {ADMIN_KEY}'
```

白名單 IP 即使存在於 DB blocklist 也會放行。

### 3. 管理 API 回傳 401

確認 HTTP Header 格式為 `Authorization: Bearer {ADMIN_KEY}`，且與 `.env` 一致。

### 4. 確認 ipset 狀態

```bash
sudo /usr/local/bin/block-helper.sh list
# 或直接
sudo ipset list http_blocklist
```

### 5. 管理者誤鎖自己

白名單優先於所有檢查。只要 `.env` 的 `WHITELIST_IPS` 包含自己的 IP，即使 ipset 中有也不會被封鎖。若真的誤鎖：

```bash
# 從 ipset 手動移除
sudo ipset del http_blocklist 自己的IP

# 從 DB 移除
curl -s -X POST http://localhost:8000/admin/blocklist/unblock \
  -H 'Authorization: Bearer {ADMIN_KEY}' \
  -H 'Content-Type: application/json' \
  -d '{"ip":"自己的IP"}'
```

### 6. 如何測試掃描偵測

```bash
# 發送大量 404
for i in $(seq 1 60); do
  curl -s -o /dev/null http://localhost:8000/nonexistent_$i.php
done

# 執行掃描
php monitor.php

# 查看結果
curl -s http://localhost:8000/admin/blocklist -H 'Authorization: Bearer {ADMIN_KEY}'
```

### 7. 日誌未寫入

```bash
ls -la php_api/log/
# 若不存在
mkdir -p php_api/log && chmod 755 php_api/log
```

### 8. 查看 ipset iptables 規則

```bash
sudo iptables -L INPUT -n --line-numbers | grep http_blocklist
```

---

## 如何擴充偵測規則

### 步驟 1：建立規則類別

```php
<?php
// php_api/security/Rules/BruteForceRule.php

require_once __DIR__ . '/RuleInterface.php';
require_once __DIR__ . '/../LogManager.php';

class BruteForceRule implements RuleInterface
{
    public function getName(): string { return 'brute_force'; }

    public function analyze(LogManager $logManager, ?string $targetIp = null): array
    {
        // 實作邏輯
        return [];
    }
}
```

### 步驟 2：在 router.php + monitor.php 註冊

```php
require_once __DIR__ . '/security/Rules/BruteForceRule.php';
$detectionEngine->registerRule(new BruteForceRule($config));
```

---

## 完整重置 ipset + iptables 與封鎖資料

若需完全清除所有核心層封鎖設定與 DB 封鎖記錄：

```bash
# 1. 找出並刪除 iptables 規則
RULE_NUM=$(sudo iptables -L INPUT -n --line-numbers 2>/dev/null \
  | grep 'http_blocklist' \
  | awk '{print $1}')
if [ -n "$RULE_NUM" ]; then
  sudo iptables -D INPUT "$RULE_NUM"
  echo "已刪除 iptables 規則 (編號 $RULE_NUM)"
else
  echo "iptables 規則不存在，跳過"
fi

# 2. 刪除 ipset set
if sudo ipset list http_blocklist >/dev/null 2>&1; then
  sudo ipset destroy http_blocklist
  echo "已刪除 ipset set http_blocklist"
else
  echo "ipset set 不存在，跳過"
fi

# 3. 清空 DB blocklist 表格 (選擇性，不清也沒關係)
mysql -u root -p vue_php_practice -e "DELETE FROM blocklist;"
echo "已清空 blocklist 資料表"
```

執行後：
- iptables 規則完全移除，不再有核心層封鎖
- `init-blocklist.sh` 可重新初始化（重建 set + 規則）
- 但此時 set 是空的，須等 `router.php` 重啟時自動回存 DB 中的封鎖 IP，或手動加入
- 建議清空 DB 後將 `IPSET_ENABLED=false` 設為暫時停用，避免回存時重建

---

## 相關檔案對照

| 職責 | 檔案 |
|---|---|
| 請求入口 | `router.php` |
| 日誌寫入/查詢 | `security/LogManager.php` |
| 封鎖管理 (DB + ipset) | `security/BlocklistManager.php` |
| 白名單管理 | `security/WhitelistManager.php` |
| 掃描游標 | `security/CursorManager.php` |
| 規則引擎 | `security/DetectionEngine.php` |
| ipset 操作腳本 | `scripts/block-helper.sh` |
| ipset 初始化 | `scripts/init-blocklist.sh` |
| CLI 增量掃描 | `monitor.php` |
| 設定檔 | `.env` |
| 存取日誌 | `log/access.log` |
| 管理員前端 | `vue/vue_admin/` |
| 會員前端 | `vue/vue_frontend/` |
