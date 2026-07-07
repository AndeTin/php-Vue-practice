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
├── php_api/                  # PHP 後端 API 專案
│   ├── .env                  # 資料庫與安全密鑰設定檔 (需自行建立)
│   ├── cors.php              # 跨來源資源共享 (CORS) Header 配置
│   ├── db.php                # 安全讀取 .env 與建立 PDO 連線
│   ├── register.php          # 會員註冊端點 (Bcrypt 加密)
│   ├── login.php             # 會員登入端點 (數位簽章 Token 簽發)
│   └── profile.php           # 會員資料查詢端點 (Token 驗證)
│
└── vue/
    └── vue_frontend/         # Vue.js 前端專案
        ├── src/
        │   ├── main.js       # 入口點
        │   ├── App.vue       # 主元件
        │   ├── router/       # Vue Router 路由守衛配置
        │   └── views/        # 頁面元件 (Login.vue, Dashboard.vue 等)
        ├── package.json      # 前端相依套件與指令檔
        └── vite.config.js    # Vite 設定檔
```

---

## 🗄️ 資料庫初始化建置

請在您的 MySQL 資料庫中執行以下 SQL 指令來建立資料庫與使用者資料表：

```sql
-- 建立資料庫 (請根據您 .env 內設定的名稱調整，例如 vue_practice 或 vue_php_practice)
CREATE DATABASE IF NOT EXISTS `vue_practice` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `vue_practice`;

-- 建立使用者資料表
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `account` VARCHAR(50) NOT NULL UNIQUE COMMENT '使用者帳號',
    `password` VARCHAR(255) NOT NULL COMMENT 'Bcrypt 加密密碼',
    `name` VARCHAR(100) DEFAULT '無名氏' COMMENT '姓名',
    `email` VARCHAR(150) DEFAULT NULL COMMENT '電子信箱',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '註冊時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## ⚙️ 環境變數設定

在 `php_api/` 目錄下建立 `.env` 檔案，內容如下（請替換為您的實際資料庫配置）：

```env
DB_HOST=127.0.0.1
DB_NAME=vue_practice
DB_USER=root
DB_PASS=您的資料庫密碼
JWT_SECRET=您的自訂隨機JWT密鑰(例如: j_d9uj4c9v2mrhsg98unbp6bnskgjnnmcx)
```

> **安全提示：** 後端 `db.php` 內建有安全性解析，如果沒找到 `.env` 檔案，預設將會退回使用 `localhost` 且資料庫名稱為 `vue_php_practice`、帳號為 `root`、密碼為空。

---

## 🏃 啟動與安裝指南

### 第一步：運行後端 PHP API

您可以使用 PHP 內建的 Web 伺服器快速啟動後端：

```bash
# 1. 進入後端目錄
cd php_api

# 2. 啟動 PHP 內建伺服器（監聽 8000 埠）
php -S localhost:8000
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
