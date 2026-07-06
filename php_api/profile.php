<?php
require 'cors.php';
require 'db.php';

// 假設前端透過 GET 請求傳遞 account 參數 (例如: profile.php?account=testuser)
$account = $_GET['account'] ?? '';

if (empty($account)) {
    echo json_encode(["success" => false, "message" => "未提供帳號資訊"]);
    exit();
}

$stmt = $pdo->prepare("SELECT account, name, email, created_at FROM users WHERE account = ?");
$stmt->execute([$account]);
$user = $stmt->fetch();

if ($user) {
    echo json_encode(["success" => true, "user" => $user]);
} else {
    echo json_encode(["success" => false, "message" => "找不到該使用者"]);
}
?>
