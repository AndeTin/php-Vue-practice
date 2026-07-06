<?php
require 'cors.php';
require 'db.php';

// 1. 嘗試從 Header 取得 Token (格式通常為: Bearer eyJhb...)
$headers = apache_request_headers(); // 若不是 Apache，可改用 $_SERVER['HTTP_AUTHORIZATION']
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    echo json_encode(["success" => false, "message" => "未授權的存取：缺少 Token"]);
    exit();
}

$token = $matches[1];
$tokenParts = explode('.', $token);

if (count($tokenParts) !== 2) {
    echo json_encode(["success" => false, "message" => "Token 格式錯誤"]);
    exit();
}

$base64Payload = $tokenParts[0];
$clientSignature = $tokenParts[1];

// 2. 驗證數位簽名 (防偽造)
$secret = $dbConfig['JWT_SECRET'] ?? 'default_secret_key';
$serverSignature = hash_hmac('sha256', $base64Payload, $secret);

if (!hash_equals($serverSignature, $clientSignature)) {
    // 如果駭客改了 Payload 裡的帳號，這裡算出來的簽名絕對不會吻合！
    echo json_encode(["success" => false, "message" => "Token 驗證失敗，請重新登入！"]);
    exit();
}

// 3. 檢查是否過期
$payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64Payload)), true);
if ($payload['exp'] < time()) {
    echo json_encode(["success" => false, "message" => "Token 已過期，請重新登入"]);
    exit();
}

// 4. 驗證全數通過，拿著解密後的正確帳號去資料庫撈資料
$account = $payload['account'];
$stmt = $pdo->prepare("SELECT account, name, email, created_at FROM users WHERE account = ?");
$stmt->execute([$account]);
$user = $stmt->fetch();

if ($user) {
    echo json_encode(["success" => true, "user" => $user]);
} else {
    echo json_encode(["success" => false, "message" => "找不到該使用者"]);
}
?>
