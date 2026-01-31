<?php
// 后台管理密码（可自行修改）
$ADMIN_PASSWORD = "admin123";

// 配置文件路径
$VALID_KOULING_LIST_FILE = "valid_koulings.json";
$LOG_FILE = "kouling_log.json";
$SESSION_ID_FILE = "admin_session.json";
$KOULING_EXPIRE_CONFIG_FILE = "kouling_expire_config.json"; // 单口令时效配置文件

// 默认口令有效期（秒）：1天=86400 | 2天=172800 | 自定义修改此处
define('DEFAULT_KOULING_EXPIRE_SECONDS', 86400);

// 初始化默认文件
if (!file_exists($VALID_KOULING_LIST_FILE)) {
    file_put_contents($VALID_KOULING_LIST_FILE, json_encode(["1667718743", "1234567890", "9876543210"], JSON_PRETTY_PRINT));
}
if (!file_exists($KOULING_EXPIRE_CONFIG_FILE)) {
    file_put_contents($KOULING_EXPIRE_CONFIG_FILE, json_encode([], JSON_PRETTY_PRINT));
}

// 读取有效口令列表
function get_valid_koulings() {
    global $VALID_KOULING_LIST_FILE;
    $content = file_get_contents($VALID_KOULING_LIST_FILE);
    return json_decode($content, true) ?: [];
}

// 读取口令时效配置（优先单口令配置，无则用默认）
function get_kouling_expire_seconds($kouling) {
    global $KOULING_EXPIRE_CONFIG_FILE;
    $expire_config = json_decode(file_get_contents($KOULING_EXPIRE_CONFIG_FILE), true) ?: [];
    return isset($expire_config[$kouling]) ? (int)$expire_config[$kouling] : DEFAULT_KOULING_EXPIRE_SECONDS;
}

// 保存口令时效配置（单口令/批量）
function save_kouling_expire_config($config_data) {
    global $KOULING_EXPIRE_CONFIG_FILE;
    file_put_contents($KOULING_EXPIRE_CONFIG_FILE, json_encode($config_data, JSON_PRETTY_PRINT));
}

// 验证口令是否有效（含时效验证）
function is_kouling_valid($kouling) {
    $valid_list = get_valid_koulings();
    if (!in_array($kouling, $valid_list)) return false;

    $log = read_kouling_log();
    if (isset($log[$kouling]['first_request_time'])) {
        $first_time = strtotime($log[$kouling]['first_request_time']);
        $expire_seconds = get_kouling_expire_seconds($kouling);
        if (time() - $first_time > $expire_seconds) {
            return false;
        }
    }
    return true;
}

// 添加新口令（自动继承默认时效）
function add_kouling($new_kouling) {
    global $VALID_KOULING_LIST_FILE;
    $valid_list = get_valid_koulings();
    if (in_array($new_kouling, $valid_list)) {
        return ["code" => 400, "msg" => "口令已存在"];
    }
    $valid_list[] = $new_kouling;
    file_put_contents($VALID_KOULING_LIST_FILE, json_encode($valid_list, JSON_PRETTY_PRINT));
    return ["code" => 200, "msg" => "口令添加成功（默认时效：".format_expire_time(DEFAULT_KOULING_EXPIRE_SECONDS).")"];
}

// 删除口令（同步删除时效配置+日志）
function delete_kouling($del_kouling) {
    global $VALID_KOULING_LIST_FILE, $KOULING_EXPIRE_CONFIG_FILE, $LOG_FILE;
    $valid_list = get_valid_koulings();
    $key = array_search($del_kouling, $valid_list);
    if ($key === false) {
        return ["code" => 400, "msg" => "口令不存在"];
    }

    // 删除口令白名单
    array_splice($valid_list, $key, 1);
    file_put_contents($VALID_KOULING_LIST_FILE, json_encode($valid_list, JSON_PRETTY_PRINT));

    // 删除时效配置
    $expire_config = json_decode(file_get_contents($KOULING_EXPIRE_CONFIG_FILE), true) ?: [];
    if (isset($expire_config[$del_kouling])) {
        unset($expire_config[$del_kouling]);
        save_kouling_expire_config($expire_config);
    }

    // 删除日志
    $log = read_kouling_log();
    if (isset($log[$del_kouling])) {
        unset($log[$del_kouling]);
        file_put_contents($LOG_FILE, json_encode($log, JSON_PRETTY_PRINT));
    }

    return ["code" => 200, "msg" => "口令及关联配置删除成功"];
}

// 读取口令日志
function read_kouling_log() {
    global $LOG_FILE;
    if (!file_exists($LOG_FILE)) {
        return [];
    }
    $content = file_get_contents($LOG_FILE);
    return json_decode($content, true) ?: [];
}

// 更新口令请求记录（含IP、首次请求时间）
function update_kouling_request($kouling) {
    global $LOG_FILE;
    $log = read_kouling_log();
    $current_time = date("Y-m-d H:i:s");
    $client_ip = get_client_ip();

    if (!isset($log[$kouling])) {
        $log[$kouling] = [
            "request_count" => 0,
            "first_request_time" => $current_time,
            "last_request_time" => "",
            "request_ips" => []
        ];
    }

    $log[$kouling]["request_count"] += 1;
    $log[$kouling]["last_request_time"] = $current_time;
    if (!in_array($client_ip, $log[$kouling]["request_ips"])) {
        $log[$kouling]["request_ips"][] = $client_ip;
    }

    file_put_contents($LOG_FILE, json_encode($log, JSON_PRETTY_PRINT));
    return $log[$kouling];
}

// 获取客户端真实IP
function get_client_ip() {
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return trim($ip);
}

// 验证管理员会话（单端登录）
function check_admin_session($session_id) {
    global $SESSION_ID_FILE;
    if (!file_exists($SESSION_ID_FILE)) return false;
    $data = json_decode(file_get_contents($SESSION_ID_FILE), true);
    return $data['session_id'] === $session_id && $data['expire_time'] > time();
}

// 保存管理员会话（单端登录）
function save_admin_session($session_id) {
    global $SESSION_ID_FILE;
    $data = [
        "session_id" => $session_id,
        "expire_time" => time() + 3600 // 会话1小时过期，无操作自动登出
    ];
    file_put_contents($SESSION_ID_FILE, json_encode($data));
}

// 销毁管理员会话
function destroy_admin_session() {
    global $SESSION_ID_FILE;
    if (file_exists($SESSION_ID_FILE)) unlink($SESSION_ID_FILE);
}

// 格式化时效显示（秒转天/小时/分钟）
function format_expire_time($seconds) {
    if ($seconds >= 86400) {
        return floor($seconds / 86400) . "天";
    } elseif ($seconds >= 3600) {
        return floor($seconds / 3600) . "小时";
    } elseif ($seconds >= 60) {
        return floor($seconds / 60) . "分钟";
    } else {
        return $seconds . "秒";
    }
}

// 批量修改口令时效
function batch_update_kouling_expire($kouling_list, $expire_seconds) {
    $expire_config = json_decode(file_get_contents($KOULING_EXPIRE_CONFIG_FILE), true) ?: [];
    foreach ($kouling_list as $kouling) {
        $expire_config[$kouling] = $expire_seconds;
    }
    save_kouling_expire_config($expire_config);
    return ["code" => 200, "msg" => "批量时效修改成功（共".count($kouling_list)."个口令）"];
}
?>
