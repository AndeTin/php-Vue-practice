# PHP + Vue.js 前後端分離會員系統練習專案

本專案是一個用於學習與練習的 **前後端分離 (SPA)** 會員註冊與登入系統。
前端使用 **Vue 3 (Vite + Composition API)** 搭配 **Axios** 進行 API 請求；後端則使用 **原生 PHP** 連接 **MySQL 資料庫**，並自研實現了一套輕量級的 **JWT (JSON Web Token) 驗證機制**。

---

## 🚀 技術棧與架構

### 前端 (Frontend)
- **框架：** Vue 3 (使用 `<script setup>` 語法糖)
- **建置工具：** Vite
- **路由管理：** Vue Router (實現前端路由守衛與頁面權限攔截)
- **API 請求：** Axios
- **狀態管理：** 利用 `localStorage` 儲存並維護登入 Token

### 後端 (Backend API)
- **語言：** 原生 PHP
- **資料庫：** MySQL / MariaDB (使用 PDO 防止 SQL 注入)
- **安全防護：** 
  - 自製 `.env` 檔案解析器（安全讀取配置，不依賴不安全的全域變數）
  - 使用 `password_hash()` 進行 Bcrypt 密碼加密
  - 輕量級 JWT 機制：包含 Base64 內容、過期時間限制（1小時）以及數位簽名防偽（HMAC SHA-256）
  - 跨來源資源共享 (CORS) 安全配置與 OPTIONS 預檢請求處理

---

## 📂 專案目錄結構

```text
php-Vue-practice/
├── php_api/                      # PHP 後端 API 專案
│   ├── .env                      # 資料庫與安全密鑰設定檔
│   ├── cors.php                  # CORS Header 配置
│   ├── db.php                    # 安全讀取 .env 與建立 PDO 連線
│   ├── router.php                # 前端控制器 (所有請求入口)
│   ├── monitor.php               # CLI 增量掃描腳本
│   ├── register.php              # 會員註冊端點
│   ├── login.php                 # 會員登入端點
│   ├── profile.php               # 會員資料查詢端點
│   ├── users.php                 # 會員列表端點
│   ├── log/                      # 存取日誌
│   │   └── access.log            # JSON Lines 格式日誌
│   ├── scripts/
│   │   ├── block-helper.sh       # ipset 操作腳本
│   │   └── init-blocklist.sh     # ipset + iptables 初始化
│   ├── security/
│   │   ├── LogManager.php        # 日誌管理
│   │   ├── BlocklistManager.php  # 封鎖管理 (DB + ipset)
│   │   ├── WhitelistManager.php  # 白名單管理 (DB + .env 靜態)
│   │   ├── CursorManager.php     # 掃描游標管理
│   │   ├── DetectionEngine.php   # 偵測引擎
│   │   └── Rules/
│   │       ├── RuleInterface.php
│   │       ├── ScanDetectionRule.php
│   │       └── MaliciousUserAgentRule.php
│   ├── SECURITY_MONITOR.md       # 安全監控文件
│   └── HttpdLearning.md          # 學習路徑文件
│
├── vue/
│   ├── vue_frontend/             # 會員前端 (Vue 3 + Vite)
│   └── vue_admin/                # 管理員前端 (Vue 3 + Vite，選用)
│       ├── src/
│       │   ├── main.js
│       │   ├── App.vue
│       │   ├── router/index.js
│       │   ├── api/admin.js
│       │   ├── views/ (AdminLogin, AdminDashboard)
│       │   └── components/ (WhitelistPanel, BlocklistPanel, ScanControl)
│       ├── package.json
│       └── vite.config.js
│
└── database.sql                  # 資料庫建表腳本 (含安全相關表格)
```

---

## 🗄️ 資料庫初始化建置

請在您的 MySQL 資料庫中執行以下 SQL 指令來建立資料庫與使用者資料表：

完整的 SQL 腳本請見 `database.sql`，包含會員、封鎖列表、白名單、掃描游標等表格：

```sql
-- 建立資料庫 (請根據您 .env 內設定的名稱調整)
CREATE DATABASE IF NOT EXISTS vue_php_practice
DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vue_php_practice;

-- 會員資料表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 封鎖列表 (取代 blocklist.json)
CREATE TABLE IF NOT EXISTS blocklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    rule VARCHAR(100) NOT NULL,
    blocked_at INT NOT NULL COMMENT 'Unix 時間戳',
    expires_at INT NOT NULL COMMENT 'Unix 時間戳，逾期自動解除',
    INDEX idx_ip (ip),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 動態白名單 (取代 whitelist.json)
CREATE TABLE IF NOT EXISTS whitelist_dynamic (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 掃描游標 (紀錄 monitor.php 讀取進度)
CREATE TABLE IF NOT EXISTS scan_cursors (
    id VARCHAR(50) PRIMARY KEY,
    filename VARCHAR(500) NOT NULL,
    inode BIGINT NOT NULL DEFAULT 0,
    position BIGINT NOT NULL DEFAULT 0,
    file_size BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## ⚙️ 環境變數設定

在 `php_api/` 目錄下建立 `.env` 檔案，內容如下（請替換為您的實際資料庫配置）：

```env
# === 資料庫 ===
DB_HOST=127.0.0.1
DB_NAME=vue_php_practice
DB_USER=root
DB_PASS=您的資料庫密碼
JWT_SECRET=您的自訂隨機JWT密鑰

# === 安全監控 (選用) ===
WHITELIST_IPS=127.0.0.1,::1
BLOCK_DURATION=3600
SCAN_THRESHOLD=50
SCAN_WINDOW=60
ADMIN_KEY=change_this_to_a_random_secret_key
IPSET_ENABLED=true
```

> **安全提示：** 後端 `db.php` 內建有安全性解析，如果沒找到 `.env` 檔案，預設將會退回使用 `localhost` 且資料庫名稱為 `vue_php_practice`、帳號為 `root`、密碼為空。

---

## 🏃 啟動與安裝指南

### 第一步：運行後端 PHP API

您可以使用 PHP 內建的 Web 伺服器快速啟動後端：

```bash
# 1. 進入後端目錄
cd php_api

# 2. 啟動 PHP 內建伺服器（監聽 8000 埠，透過 router.php 前控制器）
php -S localhost:8000 router.php
```
> 後端 API 網址將會是：`http://localhost:8000`

---

### 第二步：啟動前端 Vue.js SPA

```bash
# 1. 進入前端目錄
cd vue/vue_frontend

# 2. 安裝前端相依套件 (Axios, Vue-Router 等)
npm install

# 3. 啟動開發伺服器
npm run dev
```
> 前端應用程式預設將在瀏覽器 `http://localhost:5173` (或 Vite 提示的其他埠號) 開啟。

---

## 🛡️ 安全機制詳解 (以自研輕量級 Token 驗證為例)

本專案最大特色在於不依賴第三方 JWT 套件，而是自行利用 PHP 核心函式庫實作了 Token 簽發與防偽校驗：

1. **Token 簽發 (`login.php`)：**
   - 準備包含帳號資訊與過期時間戳記（目前設為 1 小時後）的 JSON 內容，轉為 Base64。
   - 使用系統配置的 `JWT_SECRET`，透過 `hash_hmac('sha256', ...)` 對 Base64 內容進行雜湊，產生唯一的**數位簽章 (Signature)**。
   - 將兩者透過 `.` 連接：`{Base64Payload}.{Signature}`，這即是回傳給前端的登入憑證。
2. **Token 攜帶 (前端)：**
   - 前端成功登入後，將此 Token 儲存於 `localStorage.setItem('userToken', token)`。
   - 後續跳轉至需要授權的 `/dashboard` 頁面時，前端會從 LocalStorage 讀取 Token，並在 Axios 請求中以 `Authorization: Bearer <Token>` 的 HTTP Header 傳遞。
3. **Token 驗證 (`profile.php`)：**
   - 後端接收到 Header 後，取出 Token 並用 `.` 分割。
   - 使用相同的 `JWT_SECRET` 重算該 Base64Payload 的簽章，並使用安全性高的 `hash_equals()` 與客戶端傳遞的簽章比對，藉此**防範 Payload 被篡改**（例如駭客竄改帳號名稱）。
   - 比對成功後，解析並檢查 `exp` 是否已過期。
   - 完全驗證通過，才允許自資料庫撈取並回傳使用者資料。
