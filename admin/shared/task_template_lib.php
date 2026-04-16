<?php
declare(strict_types=1);

require_once __DIR__ . '/cron_lib.php';

const TASK_TEMPLATES_FILE = DATA_DIR . '/task_templates.json';

function task_template_default_data(): array {
    return [
        'version' => 1,
        'templates' => [
            [
                'id' => 'tpl_ddns_check',
                'name' => 'DDNS 检查模板',
                'icon' => '📡',
                'category' => '网络',
                'description' => '执行一次 DDNS 同步任务，适合巡检或手动补跑。',
                'command_template' => "php /var/www/nav/cli/ddns_sync.php {{ddns_task_id}}\n",
                'default_schedule' => '*/30 * * * *',
                'variables' => [
                    ['key' => 'ddns_task_id', 'label' => 'DDNS 任务 ID', 'type' => 'text', 'required' => true, 'placeholder' => 'ddns_xxxxxx'],
                ],
                'tags' => ['ddns', 'network'],
            ],
            [
                'id' => 'tpl_docker_restart',
                'name' => 'Docker 重启模板',
                'icon' => '🐳',
                'category' => '运维',
                'description' => '重启指定容器并输出结果。',
                'command_template' => "docker restart {{container_name}}\ndocker ps --filter name={{container_name}}\n",
                'default_schedule' => '0 4 * * *',
                'variables' => [
                    ['key' => 'container_name', 'label' => '容器名', 'type' => 'text', 'required' => true, 'placeholder' => 'simple-homepage'],
                ],
                'tags' => ['docker', 'restart'],
            ],
            [
                'id' => 'tpl_backup_data',
                'name' => '备份模板',
                'icon' => '💾',
                'category' => '备份',
                'description' => '把 data 目录打包到备份目录。',
                'command_template' => "STAMP=\$(date '+%Y%m%d_%H%M%S')\nARCHIVE=\"/var/www/nav/data/backups/{{archive_prefix}}_\${STAMP}.tar.gz\"\ntar -czf \"\$ARCHIVE\" /var/www/nav/data\necho \"backup saved: \$ARCHIVE\"\n",
                'default_schedule' => '30 3 * * *',
                'variables' => [
                    ['key' => 'archive_prefix', 'label' => '备份前缀', 'type' => 'text', 'required' => true, 'placeholder' => 'nav_data'],
                ],
                'tags' => ['backup'],
            ],
            [
                'id' => 'tpl_health_check',
                'name' => '健康检查模板',
                'icon' => '🩺',
                'category' => '运维',
                'description' => '访问站点并输出 HTTP 状态，可用于后续接通知。',
                'command_template' => "curl -fsS -o /dev/null -w 'HTTP %{http_code}\\n' '{{target_url}}'\n",
                'default_schedule' => '*/10 * * * *',
                'variables' => [
                    ['key' => 'target_url', 'label' => '检测地址', 'type' => 'text', 'required' => true, 'placeholder' => 'http://127.0.0.1:58080/'],
                ],
                'tags' => ['health'],
            ],
            [
                'id' => 'tpl_nginx_reload',
                'name' => 'Nginx Reload 模板',
                'icon' => '🧩',
                'category' => '系统',
                'description' => '预检 Nginx 配置后执行 reload。',
                'command_template' => "/usr/local/bin/nginx-test\n/usr/local/bin/nginx-reload\necho 'nginx reload triggered'\n",
                'default_schedule' => '15 4 * * *',
                'variables' => [],
                'tags' => ['nginx', 'reload'],
            ],
        ],
    ];
}

function task_template_load_all(): array {
    if (!file_exists(TASK_TEMPLATES_FILE)) {
        return task_template_default_data();
    }
    $raw = json_decode((string)@file_get_contents(TASK_TEMPLATES_FILE), true);
    if (!is_array($raw) || !is_array($raw['templates'] ?? null)) {
        return task_template_default_data();
    }
    return $raw + ['version' => 1];
}

function task_template_find(string $id): ?array {
    $data = task_template_load_all();
    foreach ($data['templates'] ?? [] as $template) {
        if (($template['id'] ?? '') === $id && is_array($template)) {
            return $template;
        }
    }
    return null;
}

function task_template_render_command(array $template, array $vars): string {
    $command = (string)($template['command_template'] ?? '');
    foreach ($template['variables'] ?? [] as $var) {
        if (!is_array($var)) {
            continue;
        }
        $key = (string)($var['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $command = str_replace('{{' . $key . '}}', trim((string)($vars[$key] ?? '')), $command);
    }
    return task_normalize_script_contents($command);
}

function task_template_validate_vars(array $template, array $vars): ?string {
    foreach ($template['variables'] ?? [] as $var) {
        if (!is_array($var)) {
            continue;
        }
        $key = (string)($var['key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!empty($var['required']) && trim((string)($vars[$key] ?? '')) === '') {
            return '请填写模板变量：' . (string)($var['label'] ?? $key);
        }
    }
    return null;
}

function task_template_grouped(): array {
    $grouped = [];
    foreach (task_template_load_all()['templates'] ?? [] as $template) {
        if (!is_array($template)) {
            continue;
        }
        $category = (string)($template['category'] ?? '未分类');
        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }
        $grouped[$category][] = $template;
    }
    return $grouped;
}
