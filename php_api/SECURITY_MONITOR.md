# HTTP 存取監控與攻擊偵測系統

## 概述

在原有會員系統上加入的一層安全監控層。所有 HTTP 請求改經由 `router.php`（前端控制器）處理，在請求前後執行白名單檢查、封鎖查核、日誌記錄及攻擊行為偵測。

## 目錄結構

```
php_api/
├── router.php                     # 前端控制器 — 所有請求的單一進入點
├── monitor.php                    # CLI 觸發器 — 全量掃描日誌，封鎖違規 IP
├── .env                           # 新增安全相關設定項
├── security/
│   ├── LogManager.php             # 存取日誌管理 (JSON Lines 寫入/查詢)
│   ├── BlocklistManager.php       # 封鎖列表管理 (含 TTL 自動過期)
│   ├── WhitelistManager.php       # 白名單管理 (靜態 .env + 動態檔案)
│   ├── DetectionEngine.php        # 偵測引擎 — 依序執行所有註冊規則
│   └── Rules/
│       ├── RuleInterface.php      # 規則介面 — 新增規則需實作此介面
│       ├── ScanDetectionRule.php  # 規則①掃描偵測 — 4xx 次數閾值
│       └── MaliciousUserAgentRule.php  # 規則②惡意 UA 偵測
└── log/
    ├── access.log                 # 存取日誌 (JSON Lines 格式)
    ├── blocklist.json             # 封鎖 IP 列表
    └── whitelist.json             # 動態白名單 (API 管理)
```

---

## 資料流程

```
客戶端請求
   │
   ▼
┌─────────────────────────────────────────────────────┐
│  router.php (前端控制器)                            │
│                                                     │
│  ① CORS 前置處理                                    │
│     - 設定跨域標頭                                  │
│     - OPTIONS 預檢請求直接回傳 200                  │
│                                                     │
│  ② 白名單檢查                                       │
│     ─ 是 → 跳過所有安全檢查，直接進入路由階段       │
│     ─ 否 → 繼續③                                    │
│                                                     │
│  ③ 封鎖檢查                                         │
│     ─ 被封鎖 → 回傳 403 Forbidden                   │
│     ─ 未封鎖 → 繼續④                                │
│                                                     │
│  ④ 管理 API 端點路由                                │
│     ─ URI 以 /admin/ 開頭 → 驗證 ADMIN_KEY          │
│       ─ 驗證失敗 → 回傳 401                         │
│       ─ 驗證成功 → 執行對應管理端點邏輯             │
│     ─ 非管理端點 → 繼續⑤                            │
│                                                     │
│  ⑤ 路由至現有 PHP 端點                              │
│     ─ .php 檔案存在 → require 執行                  │
│     ─ 不存在 → 回傳 404                             │
│                                                     │
│  ⑥ 關閉函數 (register_shutdown_function)            │
│     ─ 寫入存取日誌 (access.log)                     │
│     ─ 跳過白名單 IP                                 │
│     ─ 輕量即時偵測 (只檢查此 IP 的近期行為)         │
│     ─ 違規 → 加入封鎖列表                           │
└─────────────────────────────────────────────────────┘
   │
   ▼
回應送回客戶端
```

### 重要特性：關閉函數 (Shutdown Function)

即使現有端點程式碼內呼叫了 `exit()` / `die()`，`register_shutdown_function()` 註冊的函數仍然會被執行。PHP 會在輸出緩衝區 flush 完畢後、腳本真正結束前呼叫此函數，因此：

1. 回應已經送回客戶端（不會被阻擋或延遲）
2. 關閉函數執行日誌記錄
3. 關閉函數執行即時偵測，若發現違規則更新 blocklist
4. **違規 IP 的下一次請求**才會被 403 阻擋（非即時阻擋當次請求）

---

## 啟動方式

```bash
# 原本
php -S localhost:8000

# 改為（使用 router.php 作為前端控制器）
php -S localhost:8000 router.php
```

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

## 管理 API 端點

所有 `/admin/*` 端點需在 HTTP Header 帶入 `Authorization: Bearer {ADMIN_KEY}`，其中 `ADMIN_KEY` 在 `.env` 中設定。

| Method | Endpoint | 功能 | 請求 Body |
|---|---|---|---|
| `GET` | `/admin/whitelist` | 列出白名單（靜態 + 動態） | 無 |
| `POST` | `/admin/whitelist/add` | 新增白名單 IP | `{"ip": "x.x.x.x"}` |
| `POST` | `/admin/whitelist/remove` | 移除動態白名單 IP | `{"ip": "x.x.x.x"}` |
| `GET` | `/admin/blocklist` | 列出目前封鎖的 IP | 無 |
| `POST` | `/admin/blocklist/unblock` | 解除封鎖 IP | `{"ip": "x.x.x.x"}` |
| `POST` | `/admin/monitor/run` | 手動觸發全量掃描 | 無 |

### 使用範例

```bash
# 列出白名單
curl -s http://localhost:8000/admin/whitelist \
  -H 'Authorization: Bearer your_admin_key_here'

# 手動全量掃描
curl -s -X POST http://localhost:8000/admin/monitor/run \
  -H 'Authorization: Bearer your_admin_key_here'

# 手動解封 IP
curl -s -X POST http://localhost:8000/admin/blocklist/unblock \
  -H 'Authorization: Bearer your_admin_key_here' \
  -H 'Content-Type: application/json' \
  -d '{"ip":"192.168.1.100"}'

# 新增白名單 IP
curl -s -X POST http://localhost:8000/admin/whitelist/add \
  -H 'Authorization: Bearer your_admin_key_here' \
  -H 'Content-Type: application/json' \
  -d '{"ip":"10.0.0.1"}'
```

---

## CLI 掃描腳本

```bash
cd php_api
php monitor.php
```

輸出範例：

```
[2026-07-13 10:30:00] 開始安全掃描...
  掃描範圍: 最近 60 秒
  發現 1 筆違規:
    - [scan_detection] 10.0.0.5: 在 60 秒內產生 55 次 4xx 回應 (閾值: 50)
[2026-07-13 10:30:00] 掃描完成
```

適合透過 cron 定期執行（例如每分鐘）：

```cron
* * * * * /usr/bin/php /path/to/php_api/monitor.php >> /var/log/security-monitor.log 2>&1
```

---

## 權限與檢查順序

```
                        ┌──────────────┐
                        │  請求進入    │
                        └──────┬───────┘
                               │
                     ┌─────────▼─────────┐
                     │ ① 白名單檢查      │
                     │ (WhitelistManager)│
                     └─────────┬─────────┘
                               │
                   ┌───────────┴───────────┐
                   │ 是                    │ 否
                   ▼                       ▼
            ┌──────────────┐      ┌──────────────────┐
            │ 放行 (跳過   │      │ ② 封鎖檢查       │
            │ 所有安全檢查)│      │(BlocklistManager)│
            └──────────────┘      └────────┬─────────┘
                                           │
                                 ┌─────────┴─────────┐
                                 │ 是                │ 否
                                 ▼                     ▼
                          ┌──────────────┐     ┌──────────────┐
                          │ 回傳 403     │     │ 正常路由     │
                          └──────────────┘     └──────────────┘
```

**優先順序：** 白名單 > 封鎖列表（白名單 IP 即使被封鎖也放行，避免管理員誤鎖自己）

---

## 設定參考 (.env)

```env
# === 資料庫 (原系統設定) ===
DB_HOST=127.0.0.1
DB_NAME=vue_php_practice
DB_USER=root
DB_PASS=your_password
JWT_SECRET=your_jwt_secret

# === Security Monitor (新增設定) ===
# 靜態白名單 IP（半形逗號分隔，不可透過 API 刪除）
WHITELIST_IPS=127.0.0.1,::1

# 預設封鎖持續時間（秒），預設 3600 = 1 小時
BLOCK_DURATION=3600

# 掃描偵測閾值：SCAN_WINDOW 秒內超過 SCAN_THRESHOLD 次 4xx 即違規
SCAN_THRESHOLD=50
SCAN_WINDOW=60

# 管理 API 的 Bearer Token 密鑰（務必修改為隨機字串）
ADMIN_KEY=change_this_to_a_random_secret_key
```

---

## 資料檔案格式

### access.log（JSON Lines，一行一筆）

```json
{"time":1783910795,"ip":"192.168.1.5","method":"GET","uri":"/login.php","status":200,"ua":"Mozilla/5.0"}
{"time":1783910796,"ip":"10.0.0.3","method":"POST","uri":"/register.php","status":201,"ua":"curl/8.0"}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `time` | int | Unix 時間戳 |
| `ip` | string | 請求來源 IP |
| `method` | string | HTTP 方法 |
| `uri` | string | 請求路徑 |
| `status` | int | HTTP 狀態碼 |
| `ua` | string | User-Agent 字串 |

### blocklist.json

```json
[
    {
        "ip": "10.0.0.5",
        "reason": "在 60 秒內產生 55 次 4xx 回應 (閾值: 50)",
        "rule": "scan_detection",
        "blocked_at": 1783910830,
        "expires_at": 1783914430
    }
]
```

### whitelist.json（動態白名單）

```json
["192.168.1.100", "10.0.0.50"]
```

---

## 除錯指南

### 1. 管理 API 回傳 404

確認 URI 路徑是否完全匹配（含結尾斜線）。目前僅支援精確匹配。

```
/admin/whitelist      ✓
/admin/whitelist/     ✗（多結尾斜線）
/Admin/whitelist      ✗（大小寫敏感）
```

### 2. IP 被封鎖但請求仍通過

檢查該 IP 是否在白名單中。白名單優先順序高於封鎖列表：

```bash
# 檢查 whitelist
curl -s http://localhost:8000/admin/whitelist \
  -H 'Authorization: Bearer {ADMIN_KEY}' | python3 -m json.tool
```

### 3. 管理 API 回傳 401

確認 HTTP Header 格式為 `Authorization: Bearer {ADMIN_KEY}`，且 `ADMIN_KEY` 與 `.env` 設定完全一致（含前後空白）。

### 4. 如何確認封鎖機制正常

```bash
# 步驟 1：手動封鎖一個測試 IP（用 monitor.php 或直接寫入 blocklist.json）
# 步驟 2：驗證封鎖已寫入
curl -s http://localhost:8000/admin/blocklist \
  -H 'Authorization: Bearer {ADMIN_KEY}'

# 步驟 3：用 curl 模擬該 IP 的請求（無法實際偽造來源 IP，可在 .env 暫移出自己的 IP）
# 或直接檢查 blocklist.json 確認資料格式正確

# 步驟 4：解封測試 IP
curl -s -X POST http://localhost:8000/admin/blocklist/unblock \
  -H 'Authorization: Bearer {ADMIN_KEY}' \
  -H 'Content-Type: application/json' \
  -d '{"ip":"x.x.x.x"}'
```

### 5. 如何查看即時日誌

```bash
# 即時 tail 存取日誌
tail -f php_api/log/access.log | python3 -m json.tool --no-ensure-ascii 2>/dev/null || tail -f php_api/log/access.log
```

### 6. 管理者誤鎖自己

若使用 `--interface` 等參數模擬其他 IP 時誤鎖自己：

1. 直接編輯 `log/blocklist.json`，刪除自己的 IP 條目
2. 或請另一位管理員透過管理 API 解封
3. 或重啟伺服器前先刪除 blocklist.json

> **安全機制：** 只要自己的 IP 在 `.env` 的 `WHITELIST_IPS` 中，即使 blocklist.json 有該 IP 條目也不會被封鎖。開發階段務必保持 `127.0.0.1,::1` 在白名單中。

### 7. 如何測試掃描偵測（不影響正式環境）

```bash
# 方法一：用 monitor.php 掃描現有日誌（不會發出實際請求）
php php_api/monitor.php

# 方法二：在測試環境發送大量 404 請求
for i in $(seq 1 60); do
  curl -s -o /dev/null http://localhost:8000/nonexistent_$i.php
done
php php_api/monitor.php
```

### 8. 日誌未寫入

確認 `php_api/log/` 目錄存在且對 PHP 行程可寫入：

```bash
ls -la php_api/log/
# 若不存在，手動建立
mkdir -p php_api/log && chmod 755 php_api/log
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
    public function getName(): string
    {
        return 'brute_force';
    }

    public function analyze(LogManager $logManager, ?string $targetIp = null): array
    {
        // 實作邏輯：偵測短時間內大量 POST 登入失敗
        // ...
        return [
            // [
            //     'ip'     => 'x.x.x.x',
            //     'reason' => '短時間內 10 次登入失敗',
            //     'rule'   => $this->getName(),
            // ],
        ];
    }
}
```

### 步驟 2：在 router.php 註冊

```php
// 在 router.php 初始化區塊加入
require_once __DIR__ . '/security/Rules/BruteForceRule.php';
$detectionEngine->registerRule(new BruteForceRule($config));
```

### 步驟 3：在 monitor.php 註冊（同步更新）

```php
// 在 monitor.php 相同位置加入
require_once __DIR__ . '/security/Rules/BruteForceRule.php';
$detectionEngine->registerRule(new BruteForceRule($config));
```

新規則會自動：
- 在每次請求的即時檢查中執行（若指定 `$targetIp`）
- 在全量掃描（CLI / API）中執行
- 違規 IP 會被 `BlocklistManager.block()` 記錄

---

## 相關檔案對照

| 職責 | 檔案 |
|---|---|
| 請求入口 | `router.php` |
| 日誌寫入/查詢 | `security/LogManager.php` |
| 封鎖管理 | `security/BlocklistManager.php` |
| 白名單管理 | `security/WhitelistManager.php` |
| 規則引擎 | `security/DetectionEngine.php` |
| 4xx 掃描偵測 | `security/Rules/ScanDetectionRule.php` |
| 惡意 UA 偵測 | `security/Rules/MaliciousUserAgentRule.php` |
| 規則介面 | `security/Rules/RuleInterface.php` |
| CLI 掃描 | `monitor.php` |
| 設定檔 | `.env` |
| 日誌儲存 | `log/access.log` |
| 封鎖列表 | `log/blocklist.json` |
| 動態白名單 | `log/whitelist.json` |
