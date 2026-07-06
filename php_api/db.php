<?php
// 1. 安全讀取 .env，不依賴不可控的 $_ENV 全域變數
$envPath = __DIR__ . '/.env';
$dbConfig = [];

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            // trim() 會過濾掉空白、\n、\r 等所有隱形控制字元
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $dbConfig[$key] = $value; 
        }
    }
}

// 2. 直接從我們的區域陣列取值，預設退回 localhost
$host     = $dbConfig['DB_HOST'] ?? 'localhost';
$dbname   = $dbConfig['DB_NAME'] ?? 'vue_php_practice';
$username = $dbConfig['DB_USER'] ?? 'root';
$password = $dbConfig['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // 3. 安全升級：真實錯誤寫入系統 Log，前端只回傳模糊訊息
    error_log("資料庫連線錯誤: " . $e->getMessage());
    die(json_encode(["success" => false, "message" => "系統發生異常，資料庫連線失敗"]));
}
?>
