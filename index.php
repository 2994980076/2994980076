<?php
require_once 'config.php';

// 跨域+响应头配置
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

$AES_KEY_B64 = "7bQx3Uehe7mQTAcrkJXR8A==";
$key = base64_decode($AES_KEY_B64);

// 仅支持POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["code" => 405, "msg" => "仅支持POST请求，拒绝访问"]);
    exit;
}

// 解析请求参数
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['kouling']) || !isset($input['timestamp']) || empty($input['kouling']) || empty($input['timestamp'])) {
    echo json_encode(["code" => 400, "msg" => "参数错误：缺少kouling或timestamp（不能为空）"]);
    exit;
}

$kouling = trim($input['kouling']);
$timestamp = trim($input['timestamp']);

// 验证口令有效性（含自定义时效）
if (!is_kouling_valid($kouling)) {
    echo json_encode(["code" => 403, "msg" => "服务器崩溃了请稍后重试"]);
    exit;
}

// 更新请求记录（IP、次数、时间）
$log_data = update_kouling_request($kouling);
$expire_seconds = get_kouling_expire_seconds($kouling);
$remaining_seconds = $expire_seconds - (time() - strtotime($log_data['first_request_time']));

// AES-CBC加密timestamp（标准PKCS7填充）
$iv = openssl_random_pseudo_bytes(16);
$block_size = openssl_cipher_iv_length('AES-128-CBC');
$pad_len = $block_size - (strlen($timestamp) % $block_size);
$timestamp_padded = $timestamp . str_repeat(chr($pad_len), $pad_len);

$encrypted = openssl_encrypt(
    $timestamp_padded,
    'AES-128-CBC',
    $key,
    OPENSSL_RAW_DATA,
    $iv
);

// 返回加密结果+口令状态信息
echo json_encode([
    "code" => 200,
    "msg" => "加密成功",
    "data" => [
        "s" => base64_encode($encrypted),       // 加密后的timestamp
        "iv" => base64_encode($iv),             // 加密向量
        "request_count" => $log_data["request_count"], // 累计请求次数
        "first_request_time" => $log_data["first_request_time"], // 首次请求时间
        "last_request_time" => $log_data["last_request_time"],   // 最后请求时间
        "request_ip" => get_client_ip(),        // 当前请求IP
        "config_expire" => format_expire_time($expire_seconds),  // 配置的时效
        "remaining_expire" => $remaining_seconds > 0 ? format_expire_time($remaining_seconds) : "已过期" // 剩余时效
    ]
]);
?>
