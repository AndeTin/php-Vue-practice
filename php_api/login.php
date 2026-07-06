<?php
require 'cors.php';
require 'db.php'; // 裡面已經有我們寫好的 $dbConfig 解析邏輯了

$data = json_decode(file_get_contents("php://input"), true);
$account = $data['account'] ?? '';
$password = $data['password'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE account = ?");
$stmt->execute([$account]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    
    // ======== [新增] 製作輕量版 JWT Token ========
    // 1. 準備內容 (Payload)：包含帳號，以及 1 小時後過期的時間戳記
    $payload = json_encode([
        'account' => $user['account'],
        'exp' => time() + 3600 
    ]);
    
    // 2. 將內容轉成 Base64 字串 (方便在網址或 Header 中傳輸)
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    // 3. 製作數位簽名：利用我們的 JWT_SECRET 將內容進行 SHA256 雜湊加密
    $secret = $dbConfig['JWT_SECRET'] ?? 'default_secret_key';
    $signature = hash_hmac('sha256', $base64Payload, $secret);
    
    // 4. 將內容與簽名用「.」組合起來，這就是我們的 Token！
    $token = $base64Payload . '.' . $signature;
    // ===========================================

    echo json_encode([
        "success" => true, 
        "message" => "登入成功", 
        "token" => $token  // 改為回傳 Token，不再回傳明文資料
    ]);
} else {
    echo json_encode(["success" => false, "message" => "帳號或密碼錯誤"]);
}
?>
