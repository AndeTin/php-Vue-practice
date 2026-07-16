-- 建立資料庫 (設定為 utf8mb4 以支援多國語言與 Emoji)
CREATE DATABASE IF NOT EXISTS vue_php_practice 
DEFAULT CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- 切換到該資料庫
USE vue_php_practice;

-- 建立會員資料表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account VARCHAR(50) NOT NULL UNIQUE COMMENT '登入帳號，設定為唯一值',
    password VARCHAR(255) NOT NULL COMMENT '密碼 (長度設為255以容納雜湊值)',
    name VARCHAR(50) NOT NULL COMMENT '使用者姓名',
    email VARCHAR(100) NOT NULL COMMENT '電子信箱',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '帳號建立時間'
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

-- 掃描游標 (紀錄 monitor.php 本次讀取到 access.log 的進度)
CREATE TABLE IF NOT EXISTS scan_cursors (
    id VARCHAR(50) PRIMARY KEY COMMENT '游標名稱，例如 monitor_full',
    filename VARCHAR(500) NOT NULL COMMENT '監控的檔案路徑',
    inode BIGINT NOT NULL DEFAULT 0 COMMENT '檔案 inode，用於偵測 rotation',
    position BIGINT NOT NULL DEFAULT 0 COMMENT '已讀取的 byte 位置',
    file_size BIGINT NOT NULL DEFAULT 0 COMMENT '當時的檔案大小',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
