<?php
require_once 'config.php';

// 登录状态处理（单端登录）
session_start();
$show_login = true;
$login_error = "";

// 验证现有会话
if (isset($_SESSION['admin_logged_in']) && check_admin_session(session_id())) {
    $show_login = false;
}

// 处理登录请求
if ($_POST['action'] === 'login') {
    $password = $_POST['password'];
    if ($password === $ADMIN_PASSWORD) {
        destroy_admin_session(); // 踢掉其他登录端
        save_admin_session(session_id());
        $_SESSION['admin_logged_in'] = true;
        $show_login = false;
    } else {
        $login_error = "密码错误，请重新输入";
    }
}

// 核心功能操作（登录后生效）
$add_result = $delete_result = $update_single_expire_result = $batch_expire_result = [];
if (!$show_login) {
    $valid_koulings = get_valid_koulings();
    $kouling_log = read_kouling_log();
    $expire_config = json_decode(file_get_contents($KOULING_EXPIRE_CONFIG_FILE), true) ?: [];

    // 1. 添加口令
    if ($_POST['action'] === 'add_kouling') {
        $new_kouling = trim($_POST['new_kouling']);
        $add_result = add_kouling($new_kouling);
    }

    // 2. 删除口令
    if ($_POST['action'] === 'delete_kouling') {
        $del_kouling = trim($_POST['del_kouling']);
        $delete_result = delete_kouling($del_kouling);
    }

    // 3. 单口令修改时效
    if ($_POST['action'] === 'update_single_expire') {
        $target_kouling = trim($_POST['target_kouling']);
        $expire_days = trim($_POST['expire_days']);
        if (!in_array($target_kouling, $valid_koulings)) {
            $update_single_expire_result = ["code" => 400, "msg" => "口令不存在，修改失败"];
        } elseif (!is_numeric($expire_days) || $expire_days < 0.1) {
            $update_single_expire_result = ["code" => 400, "msg" => "请输入有效时效（≥0.1天）"];
        } else {
            $expire_seconds = (int)($expire_days * 86400);
            $expire_config[$target_kouling] = $expire_seconds;
            save_kouling_expire_config($expire_config);
            $update_single_expire_result = ["code" => 200, "msg" => "口令【{$target_kouling}】时效修改为：{$expire_days}天"];
        }
    }

    // 4. 批量修改口令时效
    if ($_POST['action'] === 'batch_update_expire') {
        $selected_koulings = $_POST['selected_koulings'] ?? [];
        $batch_expire_days = trim($_POST['batch_expire_days']);
        if (empty($selected_koulings)) {
            $batch_expire_result = ["code" => 400, "msg" => "请至少选择1个口令"];
        } elseif (!is_numeric($batch_expire_days) || $batch_expire_days < 0.1) {
            $batch_expire_result = ["code" => 400, "msg" => "请输入有效时效（≥0.1天）"];
        } else {
            $expire_seconds = (int)($batch_expire_days * 86400);
            $batch_expire_result = batch_update_kouling_expire($selected_koulings, $expire_seconds);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>口令管理与统计后台（带时效控制）</title>
    <style>
        * {box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif;}
        body {margin: 30px; background: #f5f5f5;}
        .container {max-width: 1200px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 15px rgba(0,0,0,0.05);}
        .login-box {max-width: 350px; margin: 80px auto; padding: 30px; border: 1px solid #eee; border-radius: 8px; background: #fff;}
        h2 {margin-bottom: 25px; color: #333; font-size: 22px; border-left: 4px solid #2563eb; padding-left: 12px;}
        h3 {margin: 20px 0 15px; color: #444; font-size: 18px;}
        input, select {padding: 9px 12px; margin: 8px 0; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;}
        button {padding: 9px 18px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; transition: all 0.2s;}
        .btn-primary {background: #2563eb; color: #fff;}
        .btn-primary:hover {background: #1d4ed8;}
        .btn-danger {background: #dc2626; color: #fff;}
        .btn-danger:hover {background: #b91c1c;}
        .btn-warning {background: #f59e0b; color: #fff;}
        .btn-warning:hover {background: #d97706;}
        .section {margin: 30px 0; padding: 20px; border: 1px solid #eee; border-radius: 6px; background: #fafafa;}
        table {width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 14px;}
        th, td {padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee;}
        th {background: #f8fafc; color: #333; font-weight: 600;}
        tr:hover {background: #f9fafb;}
        .msg {margin: 12px 0; padding: 10px 15px; border-radius: 4px; font-size: 14px;}
        .success-msg {background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;}
        .error-msg {background: #fee2e2; color: #991b1b; border: 1px solid #fecdd3;}
        .valid {color: #16a34a; font-weight: 500;}
        .invalid {color: #dc2626; font-weight: 500;}
        .expired {color: #f59e0b; font-weight: 500;}
        .ip-list {display: flex; flex-wrap: wrap; gap: 5px;}
        .ip-tag {padding: 3px 8px; background: #e2e8f0; border-radius: 3px; font-size: 12px;}
        .checkbox-item {margin: 0 10px;}
        .form-group {margin: 15px 0;}
        label {display: inline-block; margin-right: 10px; font-weight: 500;}
        .batch-control {display: flex; align-items: center; gap: 15px; margin-bottom: 15px; flex-wrap: wrap;}
    </style>
</head>
<body>
    <div class="container">
        <?php if ($show_login): ?>
            <!-- 登录页面 -->
            <div class="login-box">
                <h2 style="text-align: center; border: none; margin-bottom: 20px;">后台管理登录</h2>
                <?php if ($login_error): ?>
                    <div class="msg error-msg"><?php echo $login_error; ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="password" name="password" placeholder="请输入后台密码" required style="width: 100%;">
                    <input type="hidden" name="action" value="login">
                    <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px;">登录</button>
                </form>
            </div>
        <?php else: ?>
            <!-- 管理首页 -->
            <h2>口令管理与请求统计系统</h2>

            <!-- 1. 口令基础管理（添加/删除） -->
            <div class="section">
                <h3>一、口令基础管理</h3>
                <?php if ($add_result): ?>
                    <div class="msg <?php echo $add_result['code'] == 200 ? 'success-msg' : 'error-msg'; ?>">
                        <?php echo $add_result['msg']; ?>
                    </div>
                <?php endif; ?>
                <?php if ($delete_result): ?>
                    <div class="msg <?php echo $delete_result['code'] == 200 ? 'success-msg' : 'error-msg'; ?>">
                        <?php echo $delete_result['msg']; ?>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <form method="post" style="display: inline-block; margin-right: 20px;">
                        <label>添加口令：</label>
                        <input type="text" name="new_kouling" placeholder="输入新口令" required>
                        <input type="hidden" name="action" value="add_kouling">
                        <button type="submit" class="btn-primary">添加</button>
                    </form>

                    <form method="post" style="display: inline-block;">
                        <label>删除口令：</label>
                        <input type="text" name="del_kouling" placeholder="输入要删除的口令" required>
                        <input type="hidden" name="action" value="delete_kouling">
                        <button type="submit" class="btn-danger">删除</button>
                    </form>
                </div>
            </div>

            <!-- 2. 单口令时效修改 -->
            <div class="section">
                <h3>二、单口令时效修改</h3>
                <?php if ($update_single_expire_result): ?>
                    <div class="msg <?php echo $update_single_expire_result['code'] == 200 ? 'success-msg' : 'error-msg'; ?>">
                        <?php echo $update_single_expire_result['msg']; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <div class="form-group">
                        <label>选择口令：</label>
                        <select name="target_kouling" required>
                            <option value="">-- 请选择 --</option>
                            <?php foreach ($valid_koulings as $k): ?>
                                <option value="<?php echo $k; ?>"><?php echo $k; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>设置时效：</label>
                        <input type="number" name="expire_days" step="0.1" min="0.1" placeholder="输入天数（如1/2/0.5）" required>
                        <span style="margin: 0 10px;">天</span>
                    </div>
                    <input type="hidden" name="action" value="update_single_expire">
                    <button type="submit" class="btn-warning">修改时效</button>
                </form>
            </div>

            <!-- 3. 批量口令时效修改 -->
            <div class="section">
                <h3>三、批量口令时效修改</h3>
                <?php if ($batch_expire_result): ?>
                    <div class="msg <?php echo $batch_expire_result['code'] == 200 ? 'success-msg' : 'error-msg'; ?>">
                        <?php echo $batch_expire_result['msg']; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <div class="batch-control">
                        <div>
                            <label>选择口令：</label>
                            <?php foreach ($valid_koulings as $k): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="selected_koulings[]" value="<?php echo $k; ?>"> <?php echo $k; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <label>批量设置时效：</label>
                            <input type="number" name="batch_expire_days" step="0.1" min="0.1" placeholder="输入天数（如1/3/7）" required>
                            <span style="margin: 0 10px;">天</span>
                            <button type="submit" class="btn-primary">批量修改</button>
                        </div>
                    </div>
                    <input type="hidden" name="action" value="batch_update_expire">
                </form>
            </div>

            <!-- 4. 口令详情统计表格 -->
            <div class="section">
                <h3>四、口令请求详情统计</h3>
                <table>
                    <thead>
                        <tr>
                            <th>选择</th>
                            <th>口令</th>
                            <th>状态</th>
                            <th>配置时效</th>
                            <th>首次请求时间</th>
                            <th>剩余时效</th>
                            <th>请求次数</th>
                            <th>最后请求时间</th>
                            <th>请求IP地址</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($valid_koulings)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 20px; color: #666;">暂无有效口令，请先添加</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($valid_koulings as $kouling): ?>
                                <?php
                                $log_data = $kouling_log[$kouling] ?? [];
                                $expire_seconds = get_kouling_expire_seconds($kouling);
                                $config_expire = format_expire_time($expire_seconds);
                                $first_request = $log_data['first_request_time'] ?? '未请求';
                                $remaining_time = '未开始';
                                $status = '<span class="valid">有效（未请求）</span>';

                                if ($first_request !== '未请求') {
                                    $first_time = strtotime($first_request);
                                    $used_seconds = time() - $first_time;
                                    $remaining_seconds = $expire_seconds - $used_seconds;

                                    if ($remaining_seconds <= 0) {
                                        $remaining_time = '<span class="expired">已过期</span>';
                                        $status = '<span class="expired">时效已过</span>';
                                    } else {
                                        $remaining_time = format_expire_time($remaining_seconds);
                                        $status = '<span class="valid">有效</span>';
                                    }
                                }

                                $request_count = $log_data['request_count'] ?? 0;
                                $last_request = $log_data['last_request_time'] ?? '无';
                                $request_ips = $log_data['request_ips'] ?? [];
                                $ip_html = empty($request_ips) ? '无' : '<div class="ip-list">'.implode('', array_map(function($ip){return "<span class='ip-tag'>{$ip}</span>";}, $request_ips)).'</div>';
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="table_kouling" value="<?php echo $kouling; ?>"></td>
                                    <td><?php echo htmlspecialchars($kouling); ?></td>
                                    <td><?php echo $status; ?></td>
                                    <td><?php echo $config_expire; ?></td>
                                    <td><?php echo $first_request; ?></td>
                                    <td><?php echo $remaining_time; ?></td>
                                    <td><?php echo $request_count; ?></td>
                                    <td><?php echo $last_request; ?></td>
                                    <td><?php echo $ip_html; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
