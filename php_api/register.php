<?php
require 'cors.php';
require 'db.php';

// 接收 Vue 透過 Axios 傳來的 JSON Payload
$data = json_decode(file_get_contents("php://input"), true);

if (!$data || empty($data['account']) || empty($data['password'])) {
    echo json_encode(["success" => false, "message" => "資料不完整"]);
    exit();
}

$account = $data['account'];
// 使用 password_hash 進行 bcrypt 加密，絕對不能存明碼
$password = password_hash($data['password'], PASSWORD_DEFAULT); 
$name = $data['name'] ?? '無名氏';
$email = $data['email'] ?? '';

try {
    $stmt = $pdo->prepare("INSERT INTO users (account, password, name, email) VALUES (?, ?, ?, ?)");
    $stmt->execute([$account, $password, $name, $email]);
    echo json_encode(["success" => true, "message" => "註冊成功"]);
} catch (PDOException $e) {
    // 錯誤碼 23000 代表違反 UNIQUE 限制 (帳號重複)
    if ($e->getCode() == 23000) {
        echo json_encode(["success" => false, "message" => "該帳號已被使用"]);
    } else {
        echo json_encode(["success" => false, "message" => "註冊失敗: " . $e->getMessage()]);
    }
}
?>
