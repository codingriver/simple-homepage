#!/bin/bash
# ============================================================
# 导航网站一键部署脚本（纯 Nginx / Ubuntu / Debian）
# 使用：sudo bash deploy.sh
# 功能：交互式配置端口/域名/路径，自动完成全套部署
# ============================================================
set -e

RED='\033[0;31m';GREEN='\033[0;32m';YELLOW='\033[1;33m'
BLUE='\033[0;34m';CYAN='\033[0;36m';BOLD='\033[1m';NC='\033[0m'

info()  { echo -e "${BLUE}[INFO]${NC} $1"; }
ok()    { echo -e "${GREEN}[OK]${NC}   $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERR]${NC}  $1"; exit 1; }
step()  { echo -e "\n${BOLD}${CYAN}▶ $1${NC}"; }

# ── 必须 root ──
[ "$(id -u)" -eq 0 ] || error "请以 root 运行：sudo bash deploy.sh"

show_banner() {
    echo -e "${CYAN}"
    echo "  ╔══════════════════════════════════════════╗"
    echo "  ║       导航网站一键部署脚本               ║"
    echo "  ║       Nav Portal v2.1 (Nginx + PHP)      ║"
    echo "  ╚══════════════════════════════════════════╝"
    echo -e "${NC}"
}

# ── 交互式配置 ──
collect_config() {
    echo -e "\n${BOLD}=== 部署配置 ===${NC}\n"

    # 安装路径
    read -rp "$(echo -e "${CYAN}安装目录${NC} [默认 /var/www/nav]: ")" INPUT_PATH
    INSTALL_DIR=${INPUT_PATH:-/var/www/nav}

    # PHP 版本
    echo -e "${CYAN}PHP 版本选择${NC}:"
    echo "  1) 8.2（推荐）  2) 8.1  3) 8.0  4) 7.4  5) 7.3"
    read -rp "请选择 [默认 1]: " PHP_CHOICE
    case ${PHP_CHOICE:-1} in
        2) PHP_VER=8.1 ;;
        3) PHP_VER=8.0 ;;
        4) PHP_VER=7.4 ;;
        5) PHP_VER=7.3 ;;
        *) PHP_VER=8.2 ;;
    esac

    # 端口
    read -rp "$(echo -e "${CYAN}Nginx 监听端口${NC} [默认 80]: ")" INPUT_PORT
    NAV_PORT=${INPUT_PORT:-80}
    if ! [[ "$NAV_PORT" =~ ^[0-9]+$ ]] || [ "$NAV_PORT" -lt 1 ] || [ "$NAV_PORT" -gt 65535 ]; then
        error "端口号无效"
    fi

    # 域名
    read -rp "$(echo -e "${CYAN}绑定域名${NC} [留空=IP 直接访问，填 _ 通配]: ")" INPUT_DOMAIN
    SERVER_NAME=${INPUT_DOMAIN:-_}

    # 确认
    echo ""
    echo -e "${BOLD}── 配置确认 ──${NC}"
    echo -e "  安装目录 : ${GREEN}${INSTALL_DIR}${NC}"
    echo -e "  PHP 版本 : ${GREEN}${PHP_VER}${NC}"
    echo -e "  监听端口 : ${GREEN}${NAV_PORT}${NC}"
    echo -e "  域名绑定 : ${GREEN}${SERVER_NAME}${NC}"
    echo ""
    read -rp "确认并开始部署？[Y/n] " CONFIRM
    [[ -z "$CONFIRM" || "$CONFIRM" =~ ^[Yy]$ ]] || exit 0
}

# ── 步骤1：安装系统依赖 ──
install_deps() {
    step "安装系统依赖"
    apt update -qq
    # 安装 PHP PPA（支持多版本）
    if ! dpkg -l | grep -q "php${PHP_VER}-fpm"; then
        if ! add-apt-repository -y ppa:ondrej/php 2>/dev/null; then
            apt install -y software-properties-common
            add-apt-repository -y ppa:ondrej/php
        fi
        apt update -qq
    fi
    apt install -y -qq \
        nginx \
        "php${PHP_VER}-fpm" \
        unzip curl
    # 可选：fileinfo 扩展
    apt install -y -qq "php${PHP_VER}-fileinfo" 2>/dev/null || true
    ok "依赖安装完成（PHP ${PHP_VER}）"
}

# ── 步骤2：部署文件 ──
deploy_files() {
    step "部署项目文件"
    # 找到脚本同级目录的 nav-portal/
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    SRC_DIR="${SCRIPT_DIR}/nav-portal"
    [ -d "$SRC_DIR" ] || error "未找到项目目录：${SRC_DIR}"

    mkdir -p "${INSTALL_DIR}"
    rsync -a --exclude='data/' --exclude='docker/' \
        --exclude='.env*' --exclude='*.sh' \
        "${SRC_DIR}/" "${INSTALL_DIR}/"

    # 设置权限
    chown -R www-data:www-data "${INSTALL_DIR}"
    chmod -R 755 "${INSTALL_DIR}"

    # 创建反代配置占位文件
    touch /etc/nginx/conf.d/nav-proxy.conf

    ok "文件部署完成：${INSTALL_DIR}"
}

# ── 步骤3：配置 Nginx ──
config_nginx() {
    step "配置 Nginx"
    PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
    CONF_FILE="/etc/nginx/sites-available/nav.conf"

    cat > "${CONF_FILE}" <<NGINX
server {
    listen ${NAV_PORT};
    listen [::]:${NAV_PORT};
    server_name ${SERVER_NAME};

    root  ${INSTALL_DIR}/public;
    index index.php login.php;

    add_header X-Frame-Options        SAMEORIGIN;
    add_header X-Content-Type-Options  nosniff;

    location ~* \.(json|sh|md|log)\$  { deny all; }
    location ~ /\.                    { deny all; }
    location ~ ^/data/                { deny all; }
    location ~ ^/shared/              { deny all; }

    location = /auth/verify.php {
        internal;
        fastcgi_pass            unix:${PHP_SOCK};
        fastcgi_param           SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass_request_body off;
        fastcgi_param           CONTENT_LENGTH "";
        include                 fastcgi_params;
    }

    location ^~ /admin/ {
        alias ${INSTALL_DIR}/admin/;
        auth_request /auth/verify.php;
        error_page 401 = @login_redirect;
        location ~ \.php\$ {
            fastcgi_pass  unix:${PHP_SOCK};
            fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/admin\$fastcgi_script_name;
            include       fastcgi_params;
        }
    }

    location @login_redirect {
        return 302 /login.php?redirect=\$request_uri;
    }

    location ~ \.php\$ {
        fastcgi_pass  unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include       fastcgi_params;
    }

    location ~* \.(css|js|ico|png|jpg|jpeg|gif|webp|woff2?)\$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    include /etc/nginx/conf.d/nav-proxy.conf;

    access_log /var/log/nginx/nav.access.log;
    error_log  /var/log/nginx/nav.error.log;
}
NGINX

    ln -sf "${CONF_FILE}" /etc/nginx/sites-enabled/nav.conf
    # 删除默认站点（避免端口冲突）
    rm -f /etc/nginx/sites-enabled/default

    nginx -t || error "Nginx 配置有误"
    systemctl enable nginx php${PHP_VER}-fpm
    systemctl restart nginx php${PHP_VER}-fpm
    ok "Nginx 配置完成"
}

# ── 步骤4：sudo 白名单 ──
config_sudo() {
    step "配置 Nginx reload sudo 白名单"
    NGINX_BIN=$(which nginx)
    {
        echo "www-data ALL=(ALL) NOPASSWD: ${NGINX_BIN} -t"
        echo "www-data ALL=(ALL) NOPASSWD: ${NGINX_BIN} -s reload"
    } > /etc/sudoers.d/nav-nginx
    chmod 440 /etc/sudoers.d/nav-nginx
    ok "sudo 白名单已配置"
}

# ── 完成提示 ──
show_result() {
    IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo '服务器IP')
    PORT_STR=""
    [ "$NAV_PORT" != "80" ] && PORT_STR=":${NAV_PORT}"
    echo -e "\n${GREEN}${BOLD}✅ 部署完成！${NC}\n"
    echo -e "  🌐 访问地址  : ${CYAN}http://${IP}${PORT_STR}${NC}"
    echo -e "  📁 安装目录  : ${INSTALL_DIR}"
    echo -e "  🐘 PHP 版本  : ${PHP_VER}"
    echo -e "  首次访问将自动跳转安装向导，完成初始化。\n"
}

main() {
    show_banner
    collect_config
    install_deps
    deploy_files
    config_nginx
    config_sudo
    show_result
}

main
