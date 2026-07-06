<?php
// 允許所有來源 (開發練習時方便使用)
header("Access-Control-Allow-Origin: *");
// 允許前端發送的方法與標頭
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 攔截瀏覽器的 OPTIONS 預檢請求 (Preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// 設定回傳格式一律為 JSON
header('Content-Type: application/json; charset=utf-8');
?>
