#!/usr/bin/env python3
"""
qBittorrent 反代诊断脚本
用法:
    python3 scripts/diagnose_qb.py
    然后按提示粘贴浏览器中的 nav_session cookie 值
"""

import json
import sys
import urllib.error
import urllib.parse
import urllib.request
import ssl

# 忽略 SSL 证书验证（如有自签名证书）
ssl_context = ssl.create_default_context()
ssl_context.check_hostname = False
ssl_context.verify_mode = ssl.CERT_NONE

QB_PROXY_URL = "https://qb1.303066.xyz"
QB_DIRECT_URL = "http://192.168.2.2:9097"
NAV_URL = "https://nav.303066.xyz"


def http_request(url, method="GET", headers=None, data=None, timeout=15):
    """发送 HTTP 请求并返回 (status, headers, body)"""
    if headers is None:
        headers = {}
    req = urllib.request.Request(url, method=method, headers=headers, data=data)
    try:
        response = urllib.request.urlopen(req, timeout=timeout, context=ssl_context)
        body = response.read()
        return response.status, dict(response.headers), body
    except urllib.error.HTTPError as e:
        body = e.read()
        return e.code, dict(e.headers), body
    except Exception as e:
        return -1, {}, str(e).encode()


def print_request_detail(label, url, status, resp_headers, body):
    print(f"\n{'='*60}")
    print(f"【{label}】")
    print(f"URL: {url}")
    print(f"Status: {status}")
    print(f"Response Headers:")
    for k, v in resp_headers.items():
        print(f"  {k}: {v}")
    text = body.decode("utf-8", errors="ignore")[:800]
    print(f"Response Body (前800字符):\n{text}")
    print("="*60)


def diagnose_direct_backend():
    """直接访问 qBittorrent 后端（绕过反代）"""
    print("\n" + "="*60)
    print("第一阶段：直接访问 qBittorrent 后端")
    print("="*60)

    # 1. 获取根页面（登录页）
    url = f"{QB_DIRECT_URL}/"
    status, headers, body = http_request(url)
    print_request_detail("直接 GET /", url, status, headers, body)

    # 2. 直接访问 API（应返回 403，因为未登录）
    url = f"{QB_DIRECT_URL}/api/v2/app/version"
    status, headers, body = http_request(url)
    print_request_detail("直接 GET /api/v2/app/version", url, status, headers, body)


def diagnose_proxy_without_cookie():
    """不携带 cookie 访问反代（应被 302 到导航站登录页）"""
    print("\n" + "="*60)
    print("第二阶段：反代 - 未携带 nav_session")
    print("="*60)

    url = f"{QB_PROXY_URL}/"
    headers = {"User-Agent": "Mozilla/5.0"}
    status, headers, body = http_request(url, headers=headers)
    print_request_detail("反代 GET / (无 cookie)", url, status, headers, body)


def diagnose_proxy_with_cookie(nav_session):
    """携带 nav_session 访问反代后的 qBittorrent"""
    print("\n" + "="*60)
    print("第三阶段：反代 - 携带 nav_session 访问 qBittorrent")
    print("="*60)

    base_headers = {
        "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36",
        "Cookie": f"nav_session={nav_session}",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "zh-CN,zh;q=0.9",
    }

    # 1. 获取 qBittorrent 登录页面（通过反代）
    url = f"{QB_PROXY_URL}/"
    status, headers, body = http_request(url, headers=base_headers)
    print_request_detail("反代 GET / (有 nav_session)", url, status, headers, body)

    if status != 200:
        print("⚠️ 无法获取 qBittorrent 页面，可能 nav_session 已过期")
        return

    # 2. 尝试直接调用 qBittorrent 登录 API
    # qBittorrent 在已登录状态下（携带 SID cookie）访问这个接口的行为
    url = f"{QB_PROXY_URL}/api/v2/auth/login"
    login_data = urllib.parse.urlencode({
        "username": "admin",
        "password": "111111"
    }).encode()
    login_headers = dict(base_headers)
    login_headers["Content-Type"] = "application/x-www-form-urlencoded"
    login_headers["Referer"] = QB_PROXY_URL + "/"
    login_headers["X-Requested-With"] = "XMLHttpRequest"

    status, headers, body = http_request(url, method="POST", headers=login_headers, data=login_data)
    print_request_detail("POST /api/v2/auth/login", url, status, headers, body)

    # 提取 SID cookie
    sid = None
    set_cookie = headers.get("Set-Cookie") or headers.get("set-cookie")
    if set_cookie and "SID=" in set_cookie:
        sid = set_cookie.split("SID=")[1].split(";")[0]
        print(f"✅ 提取到 SID: {sid[:10]}...")
    else:
        print("⚠️ 未在响应头中找到 SID cookie")

    # 3. 获取主数据（sync/maindata）——这是主界面初始化的核心 API
    api_headers = dict(base_headers)
    if sid:
        api_headers["Cookie"] = f"nav_session={nav_session}; SID={sid}"
    else:
        api_headers["Cookie"] = f"nav_session={nav_session}"
    api_headers["Referer"] = QB_PROXY_URL + "/"
    api_headers["X-Requested-With"] = "XMLHttpRequest"

    url = f"{QB_PROXY_URL}/api/v2/sync/maindata?rid=0"
    status, headers, body = http_request(url, headers=api_headers)
    print_request_detail("GET /api/v2/sync/maindata?rid=0", url, status, headers, body)

    if status == 200:
        try:
            data = json.loads(body.decode("utf-8", errors="ignore"))
            torrents = data.get("torrents", {})
            server_state = data.get("server_state", {})
            print(f"\n✅ 主数据解析成功:")
            print(f"   - torrents 数量: {len(torrents)}")
            print(f"   - server_state 字段: {list(server_state.keys())[:10]}")
            if server_state:
                print(f"   - 连接状态: dl_info_speed={server_state.get('dl_info_speed')}, up_info_speed={server_state.get('up_info_speed')}")
        except Exception as e:
            print(f"\n⚠️ 主数据 JSON 解析失败: {e}")
    else:
        print(f"\n❌ 获取主数据失败，状态码: {status}")

    # 4. 获取传输信息
    url = f"{QB_PROXY_URL}/api/v2/transfer/info"
    status, headers, body = http_request(url, headers=api_headers)
    print_request_detail("GET /api/v2/transfer/info", url, status, headers, body)

    # 5. 获取应用偏好设置
    url = f"{QB_PROXY_URL}/api/v2/app/preferences"
    status, headers, body = http_request(url, headers=api_headers)
    print_request_detail("GET /api/v2/app/preferences", url, status, headers, body)

    # 6. 获取 WebAPI 版本
    url = f"{QB_PROXY_URL}/api/v2/app/webapiVersion"
    status, headers, body = http_request(url, headers=api_headers)
    print_request_detail("GET /api/v2/app/webapiVersion", url, status, headers, body)


def main():
    print("="*60)
    print("qBittorrent 反代诊断脚本")
    print("="*60)
    print("\n本脚本将测试:")
    print("1. 直接访问 qb 后端 (192.168.2.2:9097)")
    print("2. 反代访问 - 无 cookie (应被 302)")
    print("3. 反代访问 - 有 nav_session (模拟你的浏览器)")
    print("   - 获取 qb 登录页")
    print("   - 调用 /api/v2/auth/login")
    print("   - 调用 /api/v2/sync/maindata (主界面核心数据)")
    print("   - 调用 /api/v2/transfer/info")
    print("   - 调用 /api/v2/app/preferences")
    print("   - 调用 /api/v2/app/webapiVersion")

    # 阶段一、二不需要输入
    diagnose_direct_backend()
    diagnose_proxy_without_cookie()

    # 阶段三需要 nav_session
    print("\n" + "="*60)
    print("第三阶段需要你的 nav_session cookie")
    print("="*60)
    print("获取方法:")
    print("  1. 浏览器访问 https://nav.303066.xyz 并登录")
    print("  2. 按 F12 → Application (应用) → Cookies → https://nav.303066.xyz")
    print("  3. 找到 nav_session，复制它的 Value（很长一串字符）")
    print("="*60)

    nav_session = input("\n请粘贴 nav_session 的值: ").strip()
    if not nav_session:
        print("未提供 nav_session，跳过第三阶段测试")
        return

    diagnose_proxy_with_cookie(nav_session)

    print("\n" + "="*60)
    print("诊断完成。请将以上输出完整复制给我分析。")
    print("="*60)


if __name__ == "__main__":
    main()
