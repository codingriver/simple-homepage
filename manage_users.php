<?php
/**
 * 用户管理命令行工具（仓库根目录：manage_users.php）
 * Docker 内路径：/var/www/nav/manage_users.php（与 COPY 位置一致，勿写成 data/ 下）
 * 只能通过 CLI 运行，Web 访问返回 403
 *
 * 用法：
 *   php manage_users.php list                    列出所有用户
 *   php manage_users.php info <user>             查看用户详情
 *   php manage_users.php add <user> <pwd>        添加管理员账户
 *   php manage_users.php passwd <user> <pwd>    修改密码
 *   php manage_users.php del <user>             删除用户
 *   php manage_users.php reset                   完整重置并重新激活Web安装向导（保留备份）
 */

// 禁止 Web 访问
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('403 Forbidden: 此脚本只允许命令行运行。');
}

if (is_file(__DIR__ . '/shared/auth.php')) {
    require_once __DIR__ . '/shared/auth.php';
} elseif (is_file(__DIR__ . '/../shared/auth.php')) {
    require_once __DIR__ . '/../shared/auth.php';
} else {
    fwrite(STDERR, "找不到 shared/auth.php（请将本脚本放在项目根目录或 data/ 下运行）。\n");
    exit(1);
}

$args = array_slice($argv, 1);
$cmd  = $args[0] ?? 'help';

switch ($cmd) {

    // ── 列出所有用户 ──
    case 'list':
        $users = auth_load_users();
        if (empty($users)) { echo "暂无用户。\n"; break; }
        echo str_pad('用户名', 18) . str_pad('角色', 8) . str_pad('创建时间', 22) . "\n";
        echo str_repeat('-', 48) . "\n";
        foreach ($users as $name => $info) {
            echo str_pad($name, 18)
               . str_pad($info['role'] ?? 'user', 8)
               . str_pad($info['created_at'] ?? '-', 22) . "\n";
        }
        break;

    // ── 查看用户详情 ──
    case 'info':
        $username = $args[1] ?? '';
        if (!$username) { echo "用法：php manage_users.php info <用户名>\n"; exit(1); }
        $users = auth_load_users();
        if (!isset($users[$username])) { echo "错误：用户 '{$username}' 不存在。\n"; exit(1); }
        $u = $users[$username];
        echo "用户名   : {$username}\n";
        echo "角色     : " . ($u['role'] ?? 'user') . "\n";
        echo "创建时间 : " . ($u['created_at'] ?? '-') . "\n";
        echo "更新时间 : " . ($u['updated_at'] ?? '-') . "\n";
        echo "密码哈希 : " . substr($u['password_hash'], 0, 20) . "...\n";
        break;

    // ── 添加管理员账户（固定 admin 角色）──
    case 'add':
        $username = $args[1] ?? '';
        $password = $args[2] ?? '';
        if (!$username || !$password) {
            echo "用法：php manage_users.php add <用户名> <密码>\n"; exit(1);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $username)) {
            echo "错误：用户名只允许字母、数字、下划线、横杠，2-32 位。\n"; exit(1);
        }
        if (strlen($password) < 8) {
            echo "错误：密码至少 8 位。\n"; exit(1);
        }
        auth_save_user($username, $password, 'admin');
        echo "OK: 管理员账户 '{$username}' 已创建/更新。\n";
        break;

    // ── 修改密码 ──
    case 'passwd':
        $username = $args[1] ?? '';
        $password = $args[2] ?? '';
        if (!$username || !$password) {
            echo "用法：php manage_users.php passwd <用户名> <新密码>\n"; exit(1);
        }
        $users = auth_load_users();
        if (!isset($users[$username])) { echo "错误：用户 '{$username}' 不存在。\n"; exit(1); }
        if (strlen($password) < 8) { echo "错误：密码至少 8 位。\n"; exit(1); }
        $role = $users[$username]['role'] ?? 'admin';
        auth_save_user($username, $password, $role);
        echo "OK: '{$username}' 的密码已修改。\n";
        break;

    // ── 完整重置（等同 setup，保留备份）──
    case 'reset':
        echo "\n╔══════════════════════════════════════════════════════════╗\n";
        echo "║  🔄  开始完整重置...                                     ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n\n";

        // [1] 清空用户数据
        file_put_contents(USERS_FILE, '{}', LOCK_EX);
        echo "[1/7] ✅ 用户数据已清空\n";

        // [2] 删除安装锁
        if (file_exists(INSTALLED_FLAG)) {
            unlink(INSTALLED_FLAG);
            echo "[2/7] ✅ 安装锁 .installed 已删除\n";
        } else {
            echo "[2/7] ⏭  安装锁不存在，跳过\n";
        }

        // [3] 重置 config.json（保留文件但重置敏感项）
        if (file_exists(CONFIG_FILE)) {
            $cfg = json_decode(file_get_contents(CONFIG_FILE), true) ?? [];
            $cfg = auth_remove_retired_config($cfg);
            $cfg['cookie_secure'] = 'off';
            $cfg['cookie_domain'] = '';
            file_put_contents(CONFIG_FILE,
                json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo "[3/7] ✅ config.json: cookie_secure=off, cookie_domain='' 已重置\n";
        } else {
            echo "[3/7] ⏭  config.json 不存在，跳过\n";
        }

        // [4] 清空 IP 锁定记录
        if (file_exists(IP_LOCKS_FILE)) {
            file_put_contents(IP_LOCKS_FILE, '{}', LOCK_EX);
            echo "[4/7] ✅ IP 锁定记录已清空\n";
        } else {
            echo "[4/7] ⏭  IP 锁定记录不存在，跳过\n";
        }

        // [5] 生成新认证密钥文件
        auth_rotate_secret_key();
        echo "[5/7] ✅ 认证密钥已更换为新随机值（data/auth_secret.key）\n";

        // [6] 清空登录日志
        if (file_exists(AUTH_LOG_FILE)) {
            file_put_contents(AUTH_LOG_FILE, '', LOCK_EX);
            echo "[6/7] ✅ 登录日志已清空（auth.log）\n";
        } else {
            echo "[6/7] ⏭  登录日志不存在，跳过\n";
        }

        // [7] 保留备份目录
        $backup_dir = DATA_DIR . '/backups';
        if (is_dir($backup_dir)) {
            $bak_files = glob($backup_dir . '/backup_*.json');
            $bak_count = is_array($bak_files) ? count($bak_files) : 0;
            echo "[7/7] ✅ 备份文件已保留（当前 {$bak_count} 个，未清理）\n";
        } else {
            echo "[7/7] ⏭  备份目录不存在，跳过\n";
        }

        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║  ✅  完整重置完成！                                      ║\n";
        echo "║                                                          ║\n";
        echo "║  已重置内容：                                            ║\n";
        echo "║    • 用户数据已清空                                      ║\n";
        echo "║    • 安装锁已删除                                        ║\n";
        echo "║    • cookie_secure=off，cookie_domain 已清空             ║\n";
        echo "║    • IP 锁定记录已清空                                   ║\n";
        echo "║    • AUTH_SECRET_KEY 已更换为新随机密钥                  ║\n";
        echo "║    • 登录日志 auth.log 已清空                            ║\n";
        echo "║    • 备份文件已保留（未清空）                            ║\n";
        echo "║                                                          ║\n";
        echo "║  ⚠️  请立即打开浏览器访问后台入口完成安装向导！          ║\n";
        echo "║  完成前后台处于未保护状态，请尽快操作。                  ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n\n";
        break;
    case 'del':
        $username = $args[1] ?? '';
        if (!$username) { echo "用法：php manage_users.php del <用户名>\n"; exit(1); }
        $users = auth_load_users();
        if (!isset($users[$username])) { echo "错误：用户 '{$username}' 不存在。\n"; exit(1); }
        unset($users[$username]);
        file_put_contents(USERS_FILE,
            json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo "OK: 用户 '{$username}' 已删除。\n";
        break;

    // ── 帮助 ──
    default:
        echo "\n=== 后台管理面板用户管理工具 ===\n\n";
        echo "用法：\n";
        echo "  php manage_users.php list                    列出所有用户\n";
        echo "  php manage_users.php info <用户名>           查看用户详情\n";
        echo "  php manage_users.php add <用户名> <密码>     添加管理员账户\n";
        echo "  php manage_users.php passwd <用户名> <新密码> 修改密码\n";
        echo "  php manage_users.php del <用户名>            删除用户\n";
        echo "  php manage_users.php reset                   完整重置：清空用户/安装锁/登录日志/IP锁定，保留备份\n";
        echo "\n注意：此脚本只能命令行运行，Web 访问返回 403。\n\n";
        break;
}
