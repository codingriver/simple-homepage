<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/host_agent_lib.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '需要 AJAX 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_require_permission('ssh.view');
$action = trim((string)($_REQUEST['action'] ?? ''));

function docker_api_send(array $result): void {
    $payload = is_array($result['data'] ?? null) ? $result['data'] : $result;
    if (!array_key_exists('ok', $payload)) {
        $payload['ok'] = (bool)($result['ok'] ?? false);
    }
    if (!empty($result['msg']) && empty($payload['msg'])) {
        $payload['msg'] = (string)$result['msg'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function docker_api_require_manage(array $user): void {
    if (auth_user_has_permission('ssh.manage', $user) || auth_user_has_permission('ssh.service.manage', $user)) {
        return;
    }
    auth_require_permission('ssh.manage');
}

if ($action === 'summary') {
    docker_api_send(host_agent_docker_summary());
}

if ($action === 'containers') {
    docker_api_send(host_agent_docker_containers_list(($_REQUEST['all'] ?? '1') !== '0'));
}

if ($action === 'container_logs') {
    $id = trim((string)($_REQUEST['id'] ?? ''));
    $tail = (int)($_REQUEST['tail'] ?? 200);
    docker_api_send(host_agent_docker_container_logs($id, $tail));
}

if ($action === 'container_inspect') {
    $id = trim((string)($_REQUEST['id'] ?? ''));
    docker_api_send(host_agent_docker_container_inspect($id));
}

if ($action === 'container_stats') {
    $id = trim((string)($_REQUEST['id'] ?? ''));
    docker_api_send(host_agent_docker_container_stats($id));
}

if ($action === 'images') {
    docker_api_send(host_agent_docker_images_list());
}

if ($action === 'volumes') {
    docker_api_send(host_agent_docker_volumes_list());
}

if ($action === 'networks') {
    docker_api_send(host_agent_docker_networks_list());
}

if ($action === 'container_action') {
    docker_api_require_manage($user);
    csrf_check();
    $id = trim((string)($_POST['id'] ?? ''));
    $containerAction = trim((string)($_POST['container_action'] ?? ''));
    $result = host_agent_docker_container_action($id, $containerAction);
    ssh_manager_audit('docker_container_action', ['id' => $id, 'container_action' => $containerAction, 'ok' => (bool)($result['ok'] ?? false)]);
    docker_api_send($result);
}

if ($action === 'container_delete') {
    docker_api_require_manage($user);
    csrf_check();
    $id = trim((string)($_POST['id'] ?? ''));
    $force = ($_POST['force'] ?? '0') === '1';
    $result = host_agent_docker_container_delete($id, $force);
    ssh_manager_audit('docker_container_delete', ['id' => $id, 'force' => $force, 'ok' => (bool)($result['ok'] ?? false)]);
    docker_api_send($result);
}

http_response_code(404);
echo json_encode(['ok' => false, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
