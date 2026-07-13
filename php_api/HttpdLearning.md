# HTTP 監控系統 — 學習路徑

本文檔將新增的安全監控功能拆解成 **5 個階段**，每個階段有明確的學習目標、建議閱讀檔案與驗收標準。照這個順序走完，就能完整掌握整個系統。

---

## 階段一：了解問題與架構定位

**目標：** 先知道「為什麼要這樣做」和「整體長什麼樣」

### 背景

原本的 `php -S localhost:8000` 直接存取模式：

```
請求 → PHP 內建伺服器 → 直接執行對應的 .php 檔案 → 回應
```

沒有任何日誌記錄、沒有請求攔截、沒有攻擊偵測。

### 解決方案：前端控制器模式

```
請求 → router.php（前端控制器）→ 檢查 → 分發 → 日誌 → 偵測 → 回應
```

所有請求強制經過 `router.php`，由它決定放行、封鎖或分發。

### 閱讀順序

| 步驟 | 檔案 | 看什麼 |
|---|---|---|
| 1 | `SECURITY_MONITOR.md` | **概述** 與 **資料流程圖** 章節，建立整體認知 |
| 2 | `php_api/`（目錄） | 對照檔案結構，確認每個檔案的位置 |
| 3 | 原本的啟動指令 | `php -S localhost:8000` → 改為 `php -S localhost:8000 router.php` |

### 驗收標準

- 能畫出請求從進來到回傳的流程圖（6 個步驟，框線層級即可）
- 知道前端控制器模式與直接存取的差異
- 知道啟動方式已經改變

---

## 階段二：日誌系統

**目標：** 理解資料從哪裡來、存成什麼格式

### 核心概念

每筆請求都會被記錄成一行 JSON 字串，追加寫入 `log/access.log`。這種格式稱為 **JSON Lines**：

```
{"time":1783910795,"ip":"::1","method":"GET","uri":"/login.php","status":200,"ua":"curl/8.0"}
```

優點：單純追加寫入、逐行讀取、不需完整解析就能過濾。

### 閱讀順序

| 步驟 | 檔案 | 看什麼 |
|---|---|---|
| 1 | `security/LogManager.php` | 三個公開方法：寫入、查詢全部（時間範圍）、查詢單一 IP（時間範圍） |
| 2 | `log/access.log` | 實際打開檔案看內容，對照每個欄位 |
| 3 | `router.php` 第 5 段 | shutdown function 中如何呼叫 `logRequest()` |

### LogManager 方法速查

```php
// 寫入一筆請求記錄
$logManager->logRequest($ip, $method, $uri, $statusCode, $ua);

// 取得最近 60 秒的所有記錄
$entries = $logManager->getRecentEntries(60);

// 取得某個 IP 最近 60 秒的記錄
$entries = $logManager->getRecentEntriesByIp('10.0.0.5', 60);
```

### 欄位對照

| 欄位 | 類型 | 範例 | 說明 |
|---|---|---|---|
| `time` | int | 1783910795 | Unix 時間戳 |
| `ip` | string | `::1` | 請求來源 IP |
| `method` | string | `GET` | HTTP 方法 |
| `uri` | string | `/login.php` | 請求路徑 |
| `status` | int | 200 | HTTP 狀態碼 |
| `ua` | string | `curl/8.0` | User-Agent 字串 |

### 驗收標準

- 知道 `access.log` 的六個欄位名稱與意義
- 能說出 JSON Lines 格式的兩個優點
- 知道 `LogManager` 的三個公開方法各在什麼情境被呼叫

---

## 階段三：白名單與封鎖列表

**目標：** 理解「誰可以過」和「誰被擋」的決策機制

### 核心概念：兩種名單、兩個來源

```
白名單（WhitelistManager）
  ├── 靜態來源：.env 的 WHITELIST_IPS（API 不可刪除）
  └── 動態來源：log/whitelist.json（API 可增刪）

封鎖列表（BlocklistManager）
  └── 來源：log/blocklist.json
        每筆含到期時間（expires_at），逾時自動清除
```

### 決策優先順序

```
請求進入
  │
  ├── 是白名單 IP？──→ 放行，跳過所有安全檢查
  │
  └── 不是白名單 IP？
        ├── 被封鎖且未過期？──→ 403 Forbidden
        └── 未封鎖？──→ 正常路由
```

**重點：** 白名單的優先權高於封鎖列表。即使 IP 同時存在於兩者，白名單勝出。這是為了避免管理者誤鎖自己。

### 閱讀順序

| 步驟 | 檔案 | 看什麼 |
|---|---|---|
| 1 | `security/WhitelistManager.php` | 建構子（兩種來源）、`isWhitelisted()`、`add()`、`remove()`、`list()` |
| 2 | `security/BlocklistManager.php` | `block()`（寫入含 TTL）、`isBlocked()`、`unblock()`、`cleanExpired()` |
| 3 | `router.php` 第 6 段 | 白名單→封鎖的判斷順序 |
| 4 | `.env` | `WHITELIST_IPS`、`BLOCK_DURATION` 的對應位置 |

### 封鎖條目結構

```json
{
    "ip": "10.0.0.5",
    "reason": "在 60 秒內產生 55 次 4xx 回應 (閾值: 50)",
    "rule": "scan_detection",
    "blocked_at": 1783910830,
    "expires_at": 1783914430
}
```

### 驗收標準

- 能說出 whiteList vs blackList 的優先順序，並解釋原因
- 知道封鎖是有時效的（TTL），不是永久
- 知道靜態與動態白名單的差異
- 能說出 `block()` 需要哪些參數（ip, reason, rule, duration）

---

## 階段四：偵測引擎與規則

**目標：** 理解「怎麼發現攻擊行為」

### 核心概念：介面 + 實作 + 引擎

```
RuleInterface（規則該長什麼樣）
  ├── getName(): string         → 規則名稱
  └── analyze(LogManager, ?string $targetIp): array
       → 回傳違規列表 [{ip, reason, rule}, ...]

ScanDetectionRule（實作①）
  └── 4xx 次數閾值檢查

MaliciousUserAgentRule（實作②）
  └── UA 黑名單比對

DetectionEngine（引擎）
  ├── registerRule(RuleInterface)  → 註冊規則
  └── runAll(LogManager, ?targetIp) → 依序執行所有規則，彙整結果
```

### 兩種執行模式

| 模式 | 誰呼叫 | `$targetIp` | 行為 |
|---|---|---|---|
| **即時檢查（inline）** | `router.php` shutdown function | 目前請求的 IP | 只查此 IP 的近期行為，輕量快速 |
| **全量掃描（full scan）** | `monitor.php` / 管理 API | `null` | 掃描所有 IP 的近期行為，完整但較重 |

### 規則①：ScanDetectionRule

```
收到 analyze($logManager, $ip) 呼叫
  │
  ├── $ip 不為 null（即時檢查）：
  │     LogManager->getRecentEntriesByIp($ip, SCAN_WINDOW)
  │     計算其中 status 在 400~499 的次數
  │     次數 ≥ SCAN_THRESHOLD → 違規
  │
  └── $ip 為 null（全量掃描）：
        LogManager->getRecentEntries(SCAN_WINDOW)
        對所有條目依 IP 分組計算 4xx 次數
        各 IP 次數 ≥ SCAN_THRESHOLD → 違規
```

可調參數（來自 `.env`）：
- `SCAN_THRESHOLD`：預設 50 次
- `SCAN_WINDOW`：預設 60 秒

### 規則②：MaliciousUserAgentRule

```
收到 analyze($logManager, $ip) 呼叫
  │
  LogManager->getRecentEntries(3600)  # 掃描最近 1 小時
  遍歷每筆記錄：
    若指定 $ip，跳過不相關的 IP
    同一 IP 只記錄一次
    UA 轉小寫後逐一比對黑名單 pattern（str_contains）
      命中任一 → 違規
```

內建 30+ pattern（`sqlmap`、`nikto`、`nmap`、`gobuster`、`dirbuster`、`wpscan`、`ffuf`、`hydra`、`metasploit`、`openvas`、`nessus`、`acunetix`、`burpsuite` 等）。比對方式是子字串比對，`sqlmap/1.4.7` 會命中 `sqlmap`。

### 閱讀順序

| 步驟 | 檔案 | 看什麼 |
|---|---|---|
| 1 | `security/Rules/RuleInterface.php` | 兩個方法的簽名 |
| 2 | `security/DetectionEngine.php` | `registerRule()` + `runAll()` 的流程 |
| 3 | `security/Rules/ScanDetectionRule.php` | `$targetIp` 的兩種分支邏輯 |
| 4 | `security/Rules/MaliciousUserAgentRule.php` | pattern 比對邏輯與 `$seenIps` 去重 |
| 5 | `router.php` shutdown function | 即時檢查如何呼叫 `runAll()` |
| 6 | `monitor.php` | 全量掃描如何呼叫 `runAll()` |

### 驗收標準

- 能說出 `RuleInterface` 的兩個方法
- 能解釋 inline 檢查與 full scan 的行為差異
- 能說出 `ScanDetectionRule` 的判斷邏輯
- 能說出 `MaliciousUserAgentRule` 如何比對 UA

---

## 階段五：實際操作與管理 API

**目標：** 動手操作驗證理解

### 前置準備

```bash
cd php_api
php -S localhost:8000 router.php
```

### 操作 1：確認基本路由正常

```bash
# 註冊
curl -s -X POST http://localhost:8000/register.php \
  -H 'Content-Type: application/json' \
  -d '{"account":"studyuser","password":"123456","name":"學習者","email":"study@test.com"}'

# 登入取得 token
curl -s -X POST http://localhost:8000/login.php \
  -H 'Content-Type: application/json' \
  -d '{"account":"studyuser","password":"123456"}'

# 取個人資料（替換成上面回傳的 token）
curl -s http://localhost:8000/profile.php \
  -H 'Authorization: Bearer {你的token}'
```

### 操作 2：熟悉管理 API

```bash
ADMIN_KEY="982jfdinc2iqxm89o1i9nxcm3jn828uas8"

# 查看白名單
curl -s http://localhost:8000/admin/whitelist \
  -H "Authorization: Bearer $ADMIN_KEY"

# 新增動態白名單
curl -s -X POST http://localhost:8000/admin/whitelist/add \
  -H "Authorization: Bearer $ADMIN_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"ip":"10.0.0.55"}'

# 查看封鎖列表
curl -s http://localhost:8000/admin/blocklist \
  -H "Authorization: Bearer $ADMIN_KEY"

# 手動觸發掃描
curl -s -X POST http://localhost:8000/admin/monitor/run \
  -H "Authorization: Bearer $ADMIN_KEY"
```

### 操作 3：模擬掃描攻擊

先確認自己的 IP 不在白名單，或改用非本機 IP 測試。這裡用 monitor.php CLI 觀察：

```bash
# 步驟 1：發出大量 404 請求
for i in $(seq 1 55); do
  curl -s -o /dev/null http://localhost:8000/attack_test_$i.php
done

# 步驟 2：執行全量掃描（CLI）
php monitor.php

# 步驟 3：查看封鎖列表
curl -s http://localhost:8000/admin/blocklist \
  -H "Authorization: Bearer $ADMIN_KEY"

# 步驟 4：查閱日誌確認 4xx 記錄
grep '"status":4' log/access.log | wc -l
```

### 操作 4：理解白名單優先權

```bash
# 步驟 1：確認你在白名單中
# 如果 ::1 或 127.0.0.1 在白名單，即使被封鎖也不會被擋
# 原因是 router.php 第 6 段的判斷順序：
#   whitelist 檢查 → blocklist 檢查

# 步驟 2：解封測試 IP
curl -s -X POST http://localhost:8000/admin/blocklist/unblock \
  -H "Authorization: Bearer $ADMIN_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"ip":"::1"}'
```

### 操作 5：模擬惡意 UA

```bash
# 用 sqlmap 的 UA 發送請求
curl -s -A "sqlmap/1.4.7" http://localhost:8000/login.php

# 執行掃描觀察結果
php monitor.php
```

### 操作 6：觀察 shutdown function 行為

```bash
# 看看請求日誌與回應時間的關係
# shutdown function 在回應送出後才執行
# 所以不會延遲客戶端的回應

# 查看日誌確認每筆請求都有記錄
tail -f log/access.log
# 開另一個 terminal 發請求，觀察日誌即時寫入
```

### 驗收標準

- 能獨立完成註冊→登入→取資料的流程
- 能使用管理 API 查閱白名單與封鎖列表
- 能觸發掃描偵測並觀察封鎖結果
- 能解封被封鎖的 IP
- 理解白名單優先於封鎖列表的行為

---

## 建議閱讀捷徑

時間有限時，從最重要的三個檔案開始：

```
router.php              # 核心流程，所有環節的黏合點
security/Rules/ScanDetectionRule.php  # 第一條規則的實作樣板
SECURITY_MONITOR.md     # 除錯指南 + 設定參考
```

這三個看完就能掌握 80% 的內容，其餘檔案是支撐這三個的底層元件。

---

## 各階段所需時間估計

| 階段 | 內容 | 估計時間 |
|---|---|---|
| 一 | 了解問題與架構定位 | 10 分鐘 |
| 二 | 日誌系統 | 15 分鐘 |
| 三 | 白名單與封鎖列表 | 20 分鐘 |
| 四 | 偵測引擎與規則 | 30 分鐘 |
| 五 | 實際操作 | 30 分鐘 |
| **合計** | | **約 1.5 ~ 2 小時** |
