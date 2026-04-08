#!/bin/bash
# ============================================================
# 本地构建/启动管理脚本（仅用于本地开发，不用于 CI/CD）
#
# 默认（不带参数）：构建镜像并启动（原有行为）
#   bash local/docker-build.sh
#
# 开发模式（dev）：使用 local/docker-compose.dev.yml 覆盖
#   bash local/docker-build.sh dev                 # 等同 up -d --build（开发模式）
#   bash local/docker-build.sh dev start           # 等同 up -d（开发模式，不构建）
#   bash local/docker-build.sh dev restart         # restart（开发模式）
#   bash local/docker-build.sh dev <args...>       # 透传 docker compose 子命令
# ============================================================
set -e

# 脚本所在目录（local/）
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# 项目根目录（local/ 的上级）
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# 切换到项目根目录（Dockerfile 和 docker/ 都在这里）
cd "$PROJECT_DIR"

# 加载 local/.env 配置
if [ -f "$SCRIPT_DIR/.env" ]; then
  set -a
  . "$SCRIPT_DIR/.env"
  set +a
  echo "[INFO] Loaded config from local/.env"
else
  echo "[WARN] local/.env not found, using defaults"
  echo "[HINT] Run: cp local/.env.example local/.env"
fi

# 检测 docker compose 命令
COMPOSE_CMD="docker-compose"
docker compose version >/dev/null 2>&1 && COMPOSE_CMD="docker compose"

BASE_COMPOSE_ARGS=(-f "$SCRIPT_DIR/docker-compose.yml")
DEV_COMPOSE_ARGS=(-f "$SCRIPT_DIR/docker-compose.yml" -f "$SCRIPT_DIR/docker-compose.dev.yml")

has_var() {
  local name="$1"
  [[ ${!name+x} == x ]]
}

run_compose_build_env() {
  local proxy_vars=(HTTP_PROXY HTTPS_PROXY http_proxy https_proxy NO_PROXY no_proxy ALL_PROXY all_proxy)
  local any_defined=0
  local cmd=(env)
  local v

  for v in "${proxy_vars[@]}"; do
    if has_var "$v"; then
      any_defined=1
      break
    fi
  done

  if [ "$any_defined" -eq 0 ]; then
    echo "[INFO] Build proxy config: no explicit proxy vars defined, inheriting system/Docker defaults"
    "$@"
    return
  fi

  echo "[INFO] Build proxy config: using explicitly defined proxy vars"
  for v in "${proxy_vars[@]}"; do
    cmd+=("-u" "$v")
  done
  for v in "${proxy_vars[@]}"; do
    if has_var "$v"; then
      cmd+=("$v=${!v}")
      if [ -n "${!v}" ]; then
        echo "[INFO]   $v=${!v}"
      else
        echo "[INFO]   $v=<empty>"
      fi
    fi
  done

  cmd+=("$@")
  "${cmd[@]}"
}

compose_needs_build_env() {
  local arg
  for arg in "$@"; do
    case "$arg" in
      build|--build)
        return 0
        ;;
    esac
  done
  return 1
}

run_compose() {
  if compose_needs_build_env "$@"; then
    run_compose_build_env "$@"
  else
    "$@"
  fi
}

print_help() {
  cat <<'EOF'
用法总览：
  bash local/docker-build.sh
    - 正式模式：先 build --no-cache，再 up -d
    - 缓存策略：不使用缓存（每层强制重建）
    - 覆盖行为：会更新同名镜像标签，并按新配置重建/替换同名容器（不是并行新建第二个）

  bash local/docker-build.sh start
    - 正式模式快速启动：仅 up -d（不执行 build）
    - 缓存策略：不涉及构建缓存（因为不构建）
    - 覆盖行为：若配置未变通常直接复用现有容器；配置变化时会重建容器

  bash local/docker-build.sh <args...>
    - 正式模式透传：将参数原样传给 docker compose（带 -f local/docker-compose.yml）

  bash local/docker-build.sh dev
    - 开发模式：up -d --build（带 dev 覆盖 compose）
    - 缓存策略：使用 Docker 默认构建缓存（未加 --no-cache）
    - 覆盖行为：会更新同名镜像标签，并按 dev 配置重建/替换同名容器

  bash local/docker-build.sh dev start
    - 开发模式快速启动：仅 up -d（不执行 build）
    - 缓存策略：不涉及构建缓存（因为不构建）
    - 覆盖行为：若配置未变通常直接复用现有容器；配置变化时会重建容器

  bash local/docker-build.sh dev restart
    - 开发模式重启：restart（不构建、不改镜像）
    - 缓存策略：不涉及构建缓存
    - 覆盖行为：仅重启当前容器，不会替换镜像

  bash local/docker-build.sh dev <args...>
    - 开发模式透传：将参数原样传给 docker compose（带 -f local/docker-compose.yml -f local/docker-compose.dev.yml）

  bash local/docker-build.sh help
  bash local/docker-build.sh -h
  bash local/docker-build.sh --help
    - 显示本帮助

常用示例：
  bash local/docker-build.sh
  bash local/docker-build.sh start
  bash local/docker-build.sh restart
  bash local/docker-build.sh ps
  bash local/docker-build.sh logs -f
  bash local/docker-build.sh dev
  bash local/docker-build.sh dev start
  bash local/docker-build.sh dev restart
  bash local/docker-build.sh dev down
  bash local/docker-build.sh dev logs -f
  bash local/docker-build.sh dev ps
EOF
}

if [ "${1:-}" = "help" ] || [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
  print_help
  exit 0
fi

# -----------------------------
# 开发模式：
#   - dev           => up -d --build
#   - dev start     => up -d
#   - dev restart   => restart
#   - 其他参数       => 透传
# -----------------------------
if [ "${1:-}" = "dev" ]; then
  shift

  # 无子命令：默认构建并启动
  if [ $# -eq 0 ]; then
    echo "[INFO] Dev mode compose: up -d --build"
    run_compose_build_env $COMPOSE_CMD "${DEV_COMPOSE_ARGS[@]}" up -d --build
    exit 0
  fi

  DEV_SUBCMD="${1:-}"

  case "$DEV_SUBCMD" in
    start)
      shift || true
      echo "[INFO] Dev mode compose: up -d $*"
      run_compose $COMPOSE_CMD "${DEV_COMPOSE_ARGS[@]}" up -d "$@"
      ;;
    restart)
      shift || true
      echo "[INFO] Dev mode compose: restart $*"
      run_compose $COMPOSE_CMD "${DEV_COMPOSE_ARGS[@]}" restart "$@"
      ;;
    *)
      echo "[INFO] Dev mode compose: $*"
      run_compose $COMPOSE_CMD "${DEV_COMPOSE_ARGS[@]}" "$@"
      ;;
  esac
  exit 0
fi

# -----------------------------
# 非 dev 模式：
#   - 无参数        => build --no-cache + up -d
#   - start         => up -d（不构建）
#   - 其他参数       => 透传 docker compose
# -----------------------------
if [ $# -eq 0 ]; then
  run_compose_build_env $COMPOSE_CMD "${BASE_COMPOSE_ARGS[@]}" build --no-cache

  run_compose $COMPOSE_CMD "${BASE_COMPOSE_ARGS[@]}" up -d
  exit 0
fi

if [ "${1:-}" = "start" ]; then
  shift || true
  echo "[INFO] Base mode compose: up -d $*"
  run_compose $COMPOSE_CMD "${BASE_COMPOSE_ARGS[@]}" up -d "$@"
  exit 0
fi

echo "[INFO] Base mode compose: $*"
run_compose $COMPOSE_CMD "${BASE_COMPOSE_ARGS[@]}" "$@"
