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
