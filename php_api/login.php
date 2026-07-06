<?php
require 'cors.php';
require 'db.php';

$data = json_decode(file_get_contents("php://input"), true);
$account = $data['account'] ?? '';
$password = $data['password'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE account = ?");
$stmt->execute([$account]);
$user = $stmt->fetch();

// 驗證帳號是否存在，且密碼是否吻合 (使用 password_verify 比對雜湊值)
if ($user && password_verify($password, $user['password'])) {
    // 將密碼從陣列中移除，避免傳回前端
    unset($user['password']);
    
    echo json_encode([
        "success" => true, 
        "message" => "登入成功", 
        "user" => $user
    ]);
} else {
    echo json_encode(["success" => false, "message" => "帳號或密碼錯誤"]);
}
?>
