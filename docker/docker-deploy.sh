#!/bin/bash
# ============================================================
# 导航网站 Docker 一键部署脚本
# 支持：首次部署 / 更新 / 备份 / 卸载
# 使用：bash docker-deploy.sh
# ============================================================
set -e

# ── 颜色输出 ──
RED='\033[0;31m';GREEN='\033[0;32m';YELLOW='\033[1;33m'
BLUE='\033[0;34m';CYAN='\033[0;36m';BOLD='\033[1m';NC='\033[0m'

info()  { echo -e "${BLUE}[INFO]${NC} $1"; }
ok()    { echo -e "${GREEN}[OK]${NC}   $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERR]${NC}  $1"; exit 1; }

# ── 检查依赖 ──
check_deps() {
    command -v docker &>/dev/null  || error "未找到 docker，请先安装：https://docs.docker.com/get-docker/"
    # 实际执行测试，兼容插件式（docker compose）和独立安装（docker-compose）
    docker compose version &>/dev/null 2>&1 || \
    docker-compose version &>/dev/null 2>&1 || \
        error "未找到 docker compose，请安装：pip install docker-compose 或升级 Docker"
    local COMPOSE_CMD
    COMPOSE_CMD=$(get_compose_cmd)
    info "使用命令：${COMPOSE_CMD}"
}

# ── 确定 compose 命令 ──
get_compose_cmd() {
    # 实际执行测试（防止命令存在但不可用的情况）
    if docker compose version >/dev/null 2>&1; then
        echo "docker compose"
    elif docker-compose version >/dev/null 2>&1; then
        echo "docker-compose"
    else
        error "未找到可用的 docker compose 命令，请安装 Docker Compose"
    fi
}

# ── Banner ──
show_banner() {
    echo -e "${CYAN}"
    echo "  ╔══════════════════════════════════════════╗"
    echo "  ║       导航网站 Docker 部署工具           ║"
    echo "  ║       Nav Portal latest                  ║"
    echo "  ╚══════════════════════════════════════════╝"
    echo -e "${NC}"
}

# ── 交互式配置 ──
collect_config() {
    echo -e "\n${BOLD}=== 部署配置 ===${NC}\n"

    # 端口
    read -rp "$(echo -e "${CYAN}访问端口${NC} [默认 8080]: ")" INPUT_PORT
    NAV_PORT=${INPUT_PORT:-8080}

    # 验证端口
    if ! [[ "$NAV_PORT" =~ ^[0-9]+$ ]] || [ "$NAV_PORT" -lt 1 ] || [ "$NAV_PORT" -gt 65535 ]; then
        error "端口号无效：$NAV_PORT"
    fi
    if ss -tlnp 2>/dev/null | grep -q ":${NAV_PORT} " ; then
        warn "端口 ${NAV_PORT} 已被占用，请确认或更换端口"
        read -rp "继续？[y/N] " CONFIRM
        [[ "$CONFIRM" =~ ^[Yy]$ ]] || exit 0
    fi

    # 数据目录
    read -rp "$(echo -e "${CYAN}数据持久化目录${NC} [默认 $(pwd)/nav-data]: ")" INPUT_DATA
    DATA_DIR=${INPUT_DATA:-$(pwd)/nav-data}

    # 容器名
    read -rp "$(echo -e "${CYAN}容器名称${NC} [默认 nav-portal]: ")" INPUT_NAME
    CONTAINER_NAME=${INPUT_NAME:-nav-portal}

    # 时区
    read -rp "$(echo -e "${CYAN}时区${NC} [默认 Asia/Shanghai]: ")" INPUT_TZ
    TZ=${INPUT_TZ:-Asia/Shanghai}

    # 构建代理
    read -rp "$(echo -e "${CYAN}构建时启用代理？${NC} [y/N]: ")" INPUT_BUILD_PROXY
    if [[ "$INPUT_BUILD_PROXY" =~ ^[Yy]$ ]]; then
        BUILD_USE_PROXY=1
        read -rp "$(echo -e "${CYAN}构建代理地址${NC} [默认 http://192.168.2.2:7890]: ")" INPUT_PROXY_URL
        BUILD_PROXY_URL=${INPUT_PROXY_URL:-http://192.168.2.2:7890}
    else
        BUILD_USE_PROXY=0
        BUILD_PROXY_URL=http://192.168.2.2:7890
    fi

    echo ""
    echo -e "${BOLD}── 配置确认 ──${NC}"
    echo -e "  访问端口   : ${GREEN}${NAV_PORT}${NC}"
    echo -e "  数据目录   : ${GREEN}${DATA_DIR}${NC}"
    echo -e "  容器名称   : ${GREEN}${CONTAINER_NAME}${NC}"
    echo -e "  时区       : ${GREEN}${TZ}${NC}"
    echo -e "  构建代理   : ${GREEN}$( [ "${BUILD_USE_PROXY}" = "1" ] && echo "启用 ${BUILD_PROXY_URL}" || echo "禁用" )${NC}"
    echo -e "  访问地址   : ${GREEN}http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo '服务器IP'):${NAV_PORT}${NC}"
    echo ""
    read -rp "确认以上配置并开始部署？[Y/n] " CONFIRM
    [[ -z "$CONFIRM" || "$CONFIRM" =~ ^[Yy]$ ]] || exit 0
}

# ── 写入 .env ──
write_env() {
    cat > .env <<EOF
# 由 docker-deploy.sh 自动生成于 $(date '+%Y-%m-%d %H:%M:%S')
CONTAINER_NAME=${CONTAINER_NAME}
NAV_PORT=${NAV_PORT}
TZ=${TZ}
DATA_DIR=${DATA_DIR}
BUILD_USE_PROXY=${BUILD_USE_PROXY:-0}
BUILD_PROXY_URL=${BUILD_PROXY_URL:-http://192.168.2.2:7890}
EOF
    ok ".env 已生成"
}

# ── 构建并启动 ──
deploy() {
    local COMPOSE=$(get_compose_cmd)

    info "创建数据目录：${DATA_DIR}"
    mkdir -p "${DATA_DIR}"

    info "构建 Docker 镜像..."
    if [ "${BUILD_USE_PROXY:-0}" = "1" ]; then
        info "构建代理：启用 ${BUILD_PROXY_URL}"
        HTTP_PROXY="${BUILD_PROXY_URL}" \
        HTTPS_PROXY="${BUILD_PROXY_URL}" \
        http_proxy="${BUILD_PROXY_URL}" \
        https_proxy="${BUILD_PROXY_URL}" \
        NO_PROXY="localhost,127.0.0.1,::1" \
        no_proxy="localhost,127.0.0.1,::1" \
        DOCKER_BUILDKIT=0 \
        $COMPOSE build --no-cache
    else
        info "构建代理：禁用"
        env -u HTTP_PROXY -u HTTPS_PROXY -u http_proxy -u https_proxy \
            -u ALL_PROXY -u all_proxy -u NO_PROXY -u no_proxy \
            DOCKER_BUILDKIT=0 \
            $COMPOSE build --no-cache
    fi

    info "启动容器..."
    $COMPOSE up -d

    # 等待健康检查
    info "等待服务就绪..."
    local RETRY=0
    until docker inspect --format='{{.State.Health.Status}}' "${CONTAINER_NAME}" 2>/dev/null | grep -q "healthy" || [ $RETRY -ge 20 ]; do
        sleep 3
        RETRY=$((RETRY+1))
        echo -n "."
    done
    echo ""

    if docker ps --filter "name=${CONTAINER_NAME}" --filter "status=running" | grep -q "${CONTAINER_NAME}"; then
        ok "容器启动成功！"
    else
        error "容器启动失败，请执行：docker logs ${CONTAINER_NAME}"
    fi
}

# ── 显示完成信息 ──
show_result() {
    local IP
    IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo '服务器IP')
    echo -e "\n${GREEN}${BOLD}✅ 部署完成！${NC}\n"
    echo -e "  🌐 访问地址  : ${CYAN}http://${IP}:${NAV_PORT}${NC}"
    echo -e "  📁 数据目录  : ${DATA_DIR}"
    echo -e "  📋 查看日志  : docker logs -f ${CONTAINER_NAME}"
    echo -e "  ⏹  停止服务  : docker compose down"
    echo -e "  🔄 重启服务  : docker compose restart"
    echo -e "  🗑  彻底删除  : docker compose down -v\n"
    echo -e "  首次访问将自动跳转到安装向导，完成初始化设置。"
    echo ""
}

# ── 主流程 ──
main() {
    show_banner
    check_deps

    # 已有 .env 则询问是否重新配置
    if [ -f .env ]; then
        warn "检测到已有 .env 配置文件"
        read -rp "重新配置？[y/N] " RECONF
        if [[ "$RECONF" =~ ^[Yy]$ ]]; then
            collect_config
            write_env
        else
            source .env
            info "使用现有配置（端口: ${NAV_PORT}，数据目录: ${DATA_DIR}）"
        fi
    else
        collect_config
        write_env
    fi

    deploy
    show_result
}

main
