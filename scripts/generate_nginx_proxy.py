#!/usr/bin/env python3
"""
预生成 Nginx 反代配置文件（供宿主机调试/验证用）
实际生效的配置由容器内 PHP 生成并写入 /etc/nginx/...
"""
import json
import re
from pathlib import Path

PROJECT_ROOT = Path(__file__).parent.parent
DATA_DIR = PROJECT_ROOT / "data"

def load_json(path):
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)

def sanitize_config_literal(s):
    return re.sub(r'[^a-zA-Z0-9._\-]', '', s)

def main():
    cfg = load_json(DATA_DIR / "config.json")
    sites = load_json(DATA_DIR / "sites.json")

    nav_domain = cfg.get("nav_domain", "nav.yourdomain.com")
    port = 58080
    params_mode = cfg.get("proxy_params_mode", "simple")
    params_file = (
        "/var/www/nav/data/nginx/proxy-params-full.conf"
        if params_mode == "full"
        else "/var/www/nav/data/nginx/proxy-params-simple.conf"
    )

    path_blocks = []
    domain_blocks = []

    # 读取模板
    tpl_path_file = DATA_DIR / "nginx" / "proxy-template-path.conf"
    tpl_domain_file = DATA_DIR / "nginx" / "proxy-template-domain.conf"
    tpl_path = tpl_path_file.read_text(encoding="utf-8") if tpl_path_file.exists() else ""
    tpl_domain = tpl_domain_file.read_text(encoding="utf-8") if tpl_domain_file.exists() else ""
    use_tpl_path = bool(tpl_path.strip())
    use_tpl_domain = bool(tpl_domain.strip())

    # 校验模板占位符
    if use_tpl_path and not all(p in tpl_path for p in ["{{slug}}", "{{target}}", "{{params_file}}"]):
        use_tpl_path = False
    if use_tpl_domain and not all(p in tpl_domain for p in ["{{domain}}", "{{target}}", "{{params_file}}", "{{nav_domain}}", "{{port}}"]):
        use_tpl_domain = False

    for grp in sites.get("groups", []):
        for s in grp.get("sites", []):
            site_type = s.get("type", "")
            if site_type not in ("proxy", "proxy_domain", "proxy_path"):
                continue

            target = (s.get("proxy_target") or "").rstrip("/")
            if not re.match(r"^https?://[a-zA-Z0-9._\-]+(:\d+)?(/[a-zA-Z0-9._~!$&'()*+,;=:@/-]*)?$", target) or ".." in target:
                continue

            name = sanitize_config_literal(s.get("name") or s.get("id", ""))

            if site_type == "proxy_path" or (site_type == "proxy" and s.get("proxy_mode", "path") == "path"):
                slug = re.sub(r"[^a-z0-9_-]", "-", (s.get("slug") or s.get("id", "")).lower())
                if use_tpl_path:
                    block = tpl_path.replace("{{name}}", name).replace("{{slug}}", slug).replace("{{target}}", target).replace("{{params_file}}", params_file)
                    path_blocks.append(block)
                else:
                    block = f"""    # {name}
    location /p/{slug}/ {{
        auth_request      /auth/verify.php;
        error_page 401  = @login_redirect;
        proxy_pass        {target}/;
        include           {params_file};
        proxy_set_header Host $host;
    }}"""
                    path_blocks.append(block)
            else:
                pd = sanitize_config_literal(s.get("proxy_domain", ""))
                if not pd or not re.match(r"^[a-zA-Z0-9._\-]+$", pd):
                    continue
                target_host = ""
                target_port = 80
                m = re.match(r"^https?://([^/:]+)(?::(\d+))?", target)
                if m:
                    target_host = m.group(1)
                    target_port = int(m.group(2) or 80)
                is_qbittorrent = target_host == "192.168.2.2" and target_port == 9097
                if use_tpl_domain and not is_qbittorrent:
                    block = (tpl_domain
                        .replace("{{name}}", name)
                        .replace("{{domain}}", pd)
                        .replace("{{target}}", target)
                        .replace("{{params_file}}", params_file)
                        .replace("{{nav_domain}}", nav_domain)
                        .replace("{{port}}", str(port)))
                    domain_blocks.append(block)
                else:
                    qb_params = """        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $proxy_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $nav_forwarded_proto;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie $http_cookie;
        proxy_set_header Range $http_range;
        proxy_set_header If-Range $http_if_range;
        proxy_set_header Origin "";
        proxy_set_header Referer "";
        proxy_pass_header Set-Cookie;
        proxy_pass_header Location;
        proxy_connect_timeout 30s;
        proxy_send_timeout 1800s;
        proxy_read_timeout 1800s;
        send_timeout 1800s;
        client_max_body_size 2g;
        proxy_redirect off;"""
                    default_params = f"""        include {params_file};
        proxy_set_header Host $proxy_host;
        proxy_set_header X-Forwarded-Host $host;"""
                    proxy_params = qb_params if is_qbittorrent else default_params
                    block = f"""server {{
    listen {port};
    listen [::]:{port};
    server_name {pd};

    location = /auth/verify {{
        internal;
        fastcgi_pass unix:/run/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/nav/public/auth/verify.php;
        fastcgi_pass_request_body off;
        fastcgi_param CONTENT_LENGTH "";
        include fastcgi_params;
        fastcgi_param HTTP_X_REAL_IP $remote_addr;
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
        fastcgi_connect_timeout 10s;
        fastcgi_send_timeout 30s;
        fastcgi_read_timeout 30s;
    }}

    location = /login.php {{
        auth_request off;
        fastcgi_pass unix:/run/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/nav/public/login.php;
        include fastcgi_params;
        fastcgi_param HTTP_X_REAL_IP $remote_addr;
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
        fastcgi_connect_timeout 10s;
        fastcgi_send_timeout 60s;
        fastcgi_read_timeout 60s;
    }}

    location = /login.css {{
        auth_request off;
        root /var/www/nav/public;
        expires 7d;
        add_header Cache-Control "public, immutable";
    }}

    location = /gesture-guard.js {{
        auth_request off;
        root /var/www/nav/public;
        expires 7d;
        add_header Cache-Control "public, immutable";
    }}

    location / {{
        auth_request /auth/verify;
        error_page 401 = @nav_login;
        proxy_pass {target};
{proxy_params}
    }}

    location @nav_login {{
        return 302 /login.php?redirect=$scheme://$http_host$request_uri;
    }}
}}"""
                    domain_blocks.append(block)

    # 路径模式配置
    path_conf_lines = [
        "# 导航站自动生成的 Nginx 反代配置",
        "# 生成时间：由脚本预生成",
        "# 此文件由后台自动管理，请勿手动编辑",
        "# 路径前缀模式：此文件被 include 到 server {} 块内，只能包含 location 块",
        "",
    ]
    if path_blocks:
        path_conf_lines.append("# ── 路径前缀模式 ──")
        for b in path_blocks:
            path_conf_lines.append(b)
            path_conf_lines.append("")
    else:
        path_conf_lines.append("# 暂无路径前缀代理站点配置")

    # 子域名模式配置
    domain_conf_lines = [
        "# 导航站自动生成的 Nginx 子域名代理配置",
        "# 生成时间：由脚本预生成",
        "# 此文件由后台自动管理，请勿手动编辑",
        "",
    ]
    if domain_blocks:
        domain_conf_lines.append("# ── 子域名模式 ──")
        for b in domain_blocks:
            domain_conf_lines.append(b)
            domain_conf_lines.append("")
    else:
        domain_conf_lines.append("# 暂无子域名代理站点配置")

    path_conf = "\n".join(path_conf_lines)
    domain_conf = "\n".join(domain_conf_lines)

    # 写入文件（供参考）
    (DATA_DIR / "nginx" / "nav-proxy.conf").write_text(path_conf, encoding="utf-8")
    (DATA_DIR / "nginx" / "http.d" / "nav-proxy-domains.conf").write_text(domain_conf, encoding="utf-8")

    print("=== 路径模式配置已写入 data/nginx/nav-proxy.conf ===")
    print(path_conf)
    print("\n=== 子域名模式配置已写入 data/nginx/http.d/nav-proxy-domains.conf ===")
    print(domain_conf)

if __name__ == "__main__":
    main()
