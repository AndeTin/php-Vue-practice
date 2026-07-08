<?php
require 'cors.php';
require 'db.php';

// 1. 驗證 Token (與 profile.php 相同的防護邏輯)
$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    echo json_encode(['success' => false, 'message' => '未授權的存取：缺少 Token']);
    exit();
}

$token = $matches[1];
$tokenParts = explode('.', $token);

if (count($tokenParts) !== 2) {
    echo json_encode(['success' => false, 'message' => 'Token 格式錯誤']);
    exit();
}

$base64Payload = $tokenParts[0];
$clientSignature = $tokenParts[1];

$secret = $dbConfig['JWT_SECRET'] ?? 'default_secret_key';
$serverSignature = hash_hmac('sha256', $base64Payload, $secret);

if (!hash_equals($serverSignature, $clientSignature)) {
    echo json_encode(['success' => false, 'message' => 'Token 驗證失敗']);
    exit();
}

$payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64Payload)), true);
if ($payload['exp'] < time()) {
    echo json_encode(['success' => false, 'message' => 'Token 已過期']);
    exit();
}

// ==========================================
// 2. 接收並處理分頁參數
// ==========================================
// 預設為第 1 頁，每頁顯示 5 筆
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;

// 防呆機制：確保頁碼與數量不為負數
if ($page < 1)
    $page = 1;
if ($limit < 1)
    $limit = 5;

// 計算資料庫查詢的偏移量 (Offset)
$offset = ($page - 1) * $limit;

try {
    // 取得資料總筆數
    $countStmt = $pdo->query('SELECT COUNT(*) FROM users');
    $totalCount = $countStmt->fetchColumn();

    // 計算總頁數 (無條件進位)
    $totalPages = ceil($totalCount / $limit);

    // 取得當頁的資料列表 (依照 id 排序)
    // ⚠️ 注意：LIMIT 和 OFFSET 在 PDO 中必須使用 bindValue 並指定為整數型態，否則會報錯
    $stmt = $pdo->prepare('SELECT id, account, name, email, created_at FROM users ORDER BY id ASC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $usersList = $stmt->fetchAll();

    // 回傳完整的列表與分頁資訊
    echo json_encode([
        'success' => true,
        'data' => $usersList,
        'pagination' => [
            'current_page' => $page,
            'items_per_page' => $limit,
            'total_count' => $totalCount,
            'total_pages' => $totalPages
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '資料取得失敗']);
}
?>
