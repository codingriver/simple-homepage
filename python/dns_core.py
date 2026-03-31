#!/usr/bin/env python3
import base64
import hashlib
import hmac
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


class DnsError(Exception):
    pass


def fail(message: str, code: int = 1) -> None:
    sys.stdout.write(json.dumps({"ok": False, "msg": message}, ensure_ascii=False))
    sys.exit(code)


def succeed(data=None, message: str = "") -> None:
    sys.stdout.write(json.dumps({"ok": True, "msg": message, "data": data or {}}, ensure_ascii=False))
    sys.exit(0)


def read_payload() -> dict:
    raw = sys.stdin.read()
    if not raw.strip():
        raise DnsError("缺少输入参数")
    payload = json.loads(raw)
    if not isinstance(payload, dict):
        raise DnsError("输入参数格式错误")
    return payload


def http_json(url: str, method: str = "GET", headers=None, body=None, timeout: int = 30) -> dict:
    request = urllib.request.Request(url, data=body, method=method)
    for key, value in (headers or {}).items():
        request.add_header(key, value)
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            content = response.read().decode("utf-8")
            return {
                "ok": True,
                "status": response.status,
                "text": content,
                "json": json.loads(content) if content else {},
            }
    except urllib.error.HTTPError as exc:
        content = exc.read().decode("utf-8", errors="replace")
        parsed = None
        try:
            parsed = json.loads(content) if content else {}
        except json.JSONDecodeError:
            parsed = None
        return {
            "ok": False,
            "status": exc.code,
            "text": content,
            "json": parsed,
        }
    except urllib.error.URLError as exc:
        raise DnsError(f"网络请求失败: {exc.reason}") from exc


def relative_name(fqdn: str, zone_name: str) -> str:
    fqdn = fqdn.rstrip(".")
    zone_name = zone_name.rstrip(".")
    if fqdn == zone_name:
        return "@"
    suffix = "." + zone_name
    if fqdn.endswith(suffix):
        return fqdn[: -len(suffix)]
    return fqdn


def ensure_positive_ttl(value, default: int = 600) -> int:
    try:
        ttl = int(value)
    except (TypeError, ValueError):
        ttl = default
    return ttl if ttl > 0 else default


def ensure_int(value, default: int = 0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def parse_aliyun_srv_value(value: str) -> dict:
    parts = (value or "").split()
    if len(parts) >= 4:
        return {
            "priority": ensure_int(parts[0], 0),
            "weight": ensure_int(parts[1], 0),
            "port": ensure_int(parts[2], 0),
            "target": " ".join(parts[3:]),
        }
    return {
        "priority": 0,
        "weight": 0,
        "port": 0,
        "target": value or "",
    }


class AliyunProvider:
    endpoint = "https://alidns.aliyuncs.com/"

    def __init__(self, account: dict):
        credentials = account.get("credentials") or {}
        self.access_key_id = (credentials.get("access_key_id") or "").strip()
        self.access_key_secret = credentials.get("access_key_secret") or ""
        if not self.access_key_id or not self.access_key_secret:
            raise DnsError("Aliyun 账号缺少 AccessKey 配置")

    @staticmethod
    def percent_encode(value: str) -> str:
        return urllib.parse.quote(str(value), safe="~")

    def request(self, params: dict) -> dict:
        params = dict(params)
        params["Format"] = "JSON"
        params["Version"] = "2015-01-09"
        params["AccessKeyId"] = self.access_key_id
        params["SignatureMethod"] = "HMAC-SHA1"
        params["SignatureVersion"] = "1.0"
        params["SignatureNonce"] = uuid.uuid4().hex
        params["Timestamp"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        params.pop("Signature", None)

        ordered = sorted((str(key), "" if value is None else str(value)) for key, value in params.items())
        canonical = "&".join(
            f"{self.percent_encode(key)}={self.percent_encode(value)}"
            for key, value in ordered
        )
        string_to_sign = "GET&%2F&" + self.percent_encode(canonical)
        signature = base64.b64encode(
            hmac.new(
                f"{self.access_key_secret}&".encode("utf-8"),
                string_to_sign.encode("utf-8"),
                hashlib.sha1,
            ).digest()
        ).decode("utf-8")
        params["Signature"] = signature
        query = urllib.parse.urlencode(params, quote_via=urllib.parse.quote)
        response = http_json(f"{self.endpoint}?{query}")
        if not response["ok"]:
            message = ""
            parsed = response.get("json")
            if isinstance(parsed, dict):
                message = parsed.get("Message") or parsed.get("Code") or ""
            raise DnsError(message or f"Aliyun API 请求失败（HTTP {response['status']}）")
        parsed = response.get("json")
        if not isinstance(parsed, dict):
            raise DnsError("Aliyun API 返回了无效 JSON")
        if parsed.get("Code"):
            raise DnsError(parsed.get("Message") or parsed.get("Code"))
        return parsed

    def list_zones(self) -> list:
        data = self.request({
            "Action": "DescribeDomains",
            "PageNumber": "1",
            "PageSize": "100",
        })
        raw = []
        domains = data.get("Domains")
        if isinstance(domains, dict):
            raw = domains.get("Domain") or []
        if isinstance(raw, dict):
            raw = [raw]
        zones = []
        for item in raw if isinstance(raw, list) else []:
            name = (item.get("DomainName") or "").strip()
            if not name:
                continue
            zones.append({
                "id": name,
                "name": name,
                "status": item.get("DomainStatus") or "",
            })
        zones.sort(key=lambda row: row["name"])
        return zones

    def list_records(self, zone: dict) -> list:
        zone_name = (zone.get("name") or zone.get("id") or "").strip()
        if not zone_name:
            raise DnsError("缺少域名")
        data = self.request({
            "Action": "DescribeDomainRecords",
            "DomainName": zone_name,
            "PageNumber": "1",
            "PageSize": "500",
        })
        raw = []
        domain_records = data.get("DomainRecords")
        if isinstance(domain_records, dict):
            raw = domain_records.get("Record") or []
        if isinstance(raw, dict):
            raw = [raw]
        records = []
        for item in raw if isinstance(raw, list) else []:
            rr = (item.get("RR") or "@").strip() or "@"
            fqdn = zone_name if rr == "@" else f"{rr}.{zone_name}"
            records.append({
                "id": item.get("RecordId") or "",
                "name": rr,
                "fqdn": fqdn,
                "type": item.get("Type") or "",
                "value": item.get("Value") or "",
                "ttl": ensure_positive_ttl(item.get("TTL"), 600),
                "enabled": True,
                "proxied": None,
                "priority": ensure_int(item.get("Priority"), 0) or None,
                "weight": None,
                "port": None,
                "target": None,
                "provider_extra": {
                    "line": item.get("Line") or "",
                    "status": item.get("Status") or "",
                    "locked": item.get("Locked") or False,
                },
            })
            current = records[-1]
            if current["type"] == "SRV":
                srv = parse_aliyun_srv_value(current["value"])
                current["priority"] = srv["priority"]
                current["weight"] = srv["weight"]
                current["port"] = srv["port"]
                current["target"] = srv["target"]
                current["value"] = srv["target"]
            elif current["type"] == "MX":
                current["target"] = current["value"]
        return records

    def create_record(self, zone: dict, record: dict) -> dict:
        zone_name = (zone.get("name") or zone.get("id") or "").strip()
        name = (record.get("name") or "@").strip() or "@"
        payload = {
            "Action": "AddDomainRecord",
            "DomainName": zone_name,
            "RR": name,
            "Type": (record.get("type") or "A").strip().upper(),
            "Value": (record.get("value") or "").strip(),
            "TTL": str(ensure_positive_ttl(record.get("ttl"), 600)),
        }
        if payload["Type"] == "MX":
            payload["Priority"] = str(max(1, ensure_int(record.get("priority"), 10)))
        elif payload["Type"] == "SRV":
            target = (record.get("target") or "").strip()
            payload["Value"] = "{} {} {} {}".format(
                max(0, ensure_int(record.get("priority"), 0)),
                max(0, ensure_int(record.get("weight"), 0)),
                max(1, ensure_int(record.get("port"), 1)),
                target,
            ).strip()
        if not payload["Value"]:
            raise DnsError("记录值不能为空")
        data = self.request(payload)
        return {"id": data.get("RecordId") or ""}

    def update_record(self, zone: dict, record: dict) -> dict:
        record_id = (record.get("id") or "").strip()
        if not record_id:
            raise DnsError("缺少记录 ID")
        payload = {
            "Action": "UpdateDomainRecord",
            "RecordId": record_id,
            "RR": (record.get("name") or "@").strip() or "@",
            "Type": (record.get("type") or "A").strip().upper(),
            "Value": (record.get("value") or "").strip(),
            "TTL": str(ensure_positive_ttl(record.get("ttl"), 600)),
        }
        if payload["Type"] == "MX":
            payload["Priority"] = str(max(1, ensure_int(record.get("priority"), 10)))
        elif payload["Type"] == "SRV":
            target = (record.get("target") or "").strip()
            payload["Value"] = "{} {} {} {}".format(
                max(0, ensure_int(record.get("priority"), 0)),
                max(0, ensure_int(record.get("weight"), 0)),
                max(1, ensure_int(record.get("port"), 1)),
                target,
            ).strip()
        if not payload["Value"]:
            raise DnsError("记录值不能为空")
        data = self.request(payload)
        return {"id": data.get("RecordId") or record_id}

    def delete_record(self, zone: dict, record_id: str) -> dict:
        if not record_id.strip():
            raise DnsError("缺少记录 ID")
        self.request({
            "Action": "DeleteDomainRecord",
            "RecordId": record_id.strip(),
        })
        return {"id": record_id.strip()}

    def verify(self) -> dict:
        zones = self.list_zones()
        return {"zones_count": len(zones)}

    def delete_many(self, zone: dict, record_ids: list) -> dict:
        success = 0
        failed = []
        for record_id in record_ids:
            try:
                self.delete_record(zone, str(record_id))
                success += 1
            except DnsError as exc:
                failed.append({"id": str(record_id), "msg": str(exc)})
        return {"success_count": success, "failed": failed}


class CloudflareProvider:
    endpoint = "https://api.cloudflare.com/client/v4"

    def __init__(self, account: dict):
        credentials = account.get("credentials") or {}
        self.api_token = self.normalize_api_token(credentials.get("api_token") or "")
        if not self.api_token:
            raise DnsError("Cloudflare 账号缺少 API Token")

    @staticmethod
    def normalize_api_token(raw_token) -> str:
        token = str(raw_token or "")
        token = token.replace("\r", "").replace("\n", "").replace("\t", "").strip()
        if token.lower().startswith("bearer "):
            token = token[7:].strip()
        if any(char.isspace() for char in token):
            raise DnsError("Cloudflare API Token 格式无效，请只粘贴 token 本体，不要带 Bearer 前缀、空格或换行")
        return token

    @classmethod
    def format_error_item(cls, item) -> list:
        if isinstance(item, dict):
            parts = []
            code = item.get("code")
            message = str(item.get("message") or "").strip()
            if code not in (None, ""):
                code_text = f"[{code}]"
                parts.append(f"{code_text} {message}".strip())
            elif message:
                parts.append(message)
            chain = item.get("error_chain") or []
            if isinstance(chain, list):
                for child in chain:
                    parts.extend(cls.format_error_item(child))
            return parts
        text = str(item or "").strip()
        return [text] if text else []

    @classmethod
    def format_api_error(cls, response: dict, parsed, path: str = "") -> str:
        messages = []
        errors = parsed.get("errors") if isinstance(parsed, dict) else []
        if isinstance(errors, list):
            for item in errors:
                messages.extend(cls.format_error_item(item))
        deduped = []
        seen = set()
        for item in messages:
            if item not in seen:
                deduped.append(item)
                seen.add(item)
        detail = "; ".join(deduped)
        detail_lower = detail.lower()
        if (
            "invalid request headers" in detail_lower
            or "authorization header" in detail_lower
            or "[6003]" in detail
            or "[6111]" in detail
        ):
            return "Cloudflare API Token 格式无效，请只粘贴 token 本体，不要带 Bearer 前缀、空格或换行"
        if "[10000]" in detail or "authentication error" in detail_lower:
            if "/dns_records" in path:
                return "Cloudflare API Token 缺少 DNS 记录权限。当前可以读取 Zone，但无法读取或修改解析记录；请为该 Token 至少补充 DNS Read 权限，若要新增/修改/删除记录，还需要 DNS Write 权限。"
            if path == "/zones" or path.startswith("/zones?") or path.startswith("/zones/"):
                return "Cloudflare API Token 鉴权失败，请确认该 Token 至少具备 Zone Read 权限，并且已授权当前 Zone。"
            return "Cloudflare API Token 鉴权失败，请确认 Token 有效，且已授予当前操作所需权限。"
        if detail:
            return f"Cloudflare API 请求失败：{detail}"
        return f"Cloudflare API 请求失败（HTTP {response['status']}）"

    def request(self, path: str, method: str = "GET", payload=None, query=None) -> dict:
        query = query or {}
        query_string = urllib.parse.urlencode(query)
        url = f"{self.endpoint}{path}"
        if query_string:
            url += ("&" if "?" in url else "?") + query_string
        body = None
        headers = {
            "Authorization": f"Bearer {self.api_token}",
            "Accept": "application/json",
        }
        if payload is not None:
            body = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"
        response = http_json(url, method=method, headers=headers, body=body)
        parsed = response.get("json")
        if not isinstance(parsed, dict):
            raise DnsError("Cloudflare API 返回了无效 JSON")
        if not response["ok"] or not parsed.get("success", False):
            raise DnsError(self.format_api_error(response, parsed, path))
        return parsed

    def list_zones(self) -> list:
        zones = []
        page = 1
        while True:
            parsed = self.request("/zones", query={"page": page, "per_page": 50})
            result = parsed.get("result") or []
            if not isinstance(result, list):
                break
            for item in result:
                name = (item.get("name") or "").strip()
                zone_id = (item.get("id") or "").strip()
                if not name or not zone_id:
                    continue
                zones.append({
                    "id": zone_id,
                    "name": name,
                    "status": item.get("status") or "",
                })
            info = parsed.get("result_info") or {}
            total_pages = int(info.get("total_pages") or 1)
            if page >= total_pages:
                break
            page += 1
        zones.sort(key=lambda row: row["name"])
        return zones

    def list_records(self, zone: dict) -> list:
        zone_id = (zone.get("id") or "").strip()
        zone_name = (zone.get("name") or "").strip()
        if not zone_id or not zone_name:
            raise DnsError("缺少 Zone 信息")
        records = []
        page = 1
        while True:
            parsed = self.request(
                f"/zones/{zone_id}/dns_records",
                query={"page": page, "per_page": 100},
            )
            result = parsed.get("result") or []
            if not isinstance(result, list):
                break
            for item in result:
                fqdn = (item.get("name") or "").strip()
                record_type = (item.get("type") or "").strip().upper()
                records.append({
                    "id": item.get("id") or "",
                    "name": relative_name(fqdn, zone_name),
                    "fqdn": fqdn,
                    "type": record_type,
                    "value": item.get("content") or "",
                    "ttl": int(item.get("ttl") or 1),
                    "enabled": not bool(item.get("meta", {}).get("disabled", False)),
                    "proxied": item.get("proxied") if record_type in {"A", "AAAA", "CNAME"} else None,
                    "priority": item.get("priority"),
                    "weight": None,
                    "port": None,
                    "target": None,
                    "provider_extra": {
                        "comment": item.get("comment") or "",
                        "tags": item.get("tags") or [],
                    },
                })
                current = records[-1]
                if record_type == "SRV":
                    data_fields = item.get("data") or {}
                    current["priority"] = ensure_int(data_fields.get("priority", item.get("priority")), 0)
                    current["weight"] = ensure_int(data_fields.get("weight"), 0)
                    current["port"] = ensure_int(data_fields.get("port"), 0)
                    current["target"] = data_fields.get("target") or current["value"]
                    current["value"] = current["target"]
                elif record_type == "MX":
                    current["target"] = current["value"]
            info = parsed.get("result_info") or {}
            total_pages = int(info.get("total_pages") or 1)
            if page >= total_pages:
                break
            page += 1
        return records

    @staticmethod
    def build_record_name(zone_name: str, relative: str) -> str:
        relative = (relative or "@").strip() or "@"
        if relative == "@":
            return zone_name
        return f"{relative}.{zone_name}"

    def create_record(self, zone: dict, record: dict) -> dict:
        zone_id = (zone.get("id") or "").strip()
        zone_name = (zone.get("name") or "").strip()
        if not zone_id or not zone_name:
            raise DnsError("缺少 Zone 信息")
        record_type = (record.get("type") or "A").strip().upper()
        payload = {
            "type": record_type,
            "name": self.build_record_name(zone_name, record.get("name") or "@"),
            "ttl": int(record.get("ttl") or 1),
        }
        if record_type == "SRV":
            target = (record.get("target") or "").strip()
            payload["priority"] = max(0, ensure_int(record.get("priority"), 0))
            payload["data"] = {
                "priority": payload["priority"],
                "weight": max(0, ensure_int(record.get("weight"), 0)),
                "port": max(1, ensure_int(record.get("port"), 1)),
                "target": target,
            }
            if not target:
                raise DnsError("SRV 目标不能为空")
        else:
            payload["content"] = (record.get("value") or "").strip()
            if record_type == "MX":
                payload["priority"] = max(1, ensure_int(record.get("priority"), 10))
            if not payload["content"]:
                raise DnsError("记录值不能为空")
        if record_type in {"A", "AAAA", "CNAME"} and isinstance(record.get("proxied"), bool):
            payload["proxied"] = record.get("proxied")
        parsed = self.request(f"/zones/{zone_id}/dns_records", method="POST", payload=payload)
        result = parsed.get("result") or {}
        return {"id": result.get("id") or ""}

    def update_record(self, zone: dict, record: dict) -> dict:
        zone_id = (zone.get("id") or "").strip()
        zone_name = (zone.get("name") or "").strip()
        record_id = (record.get("id") or "").strip()
        if not zone_id or not zone_name or not record_id:
            raise DnsError("缺少记录更新参数")
        record_type = (record.get("type") or "A").strip().upper()
        old_type = (record.get("old_type") or record_type).strip().upper()
        if old_type != record_type:
            self.delete_record(zone, record_id)
            return self.create_record(zone, record)
        payload = {
            "type": record_type,
            "name": self.build_record_name(zone_name, record.get("name") or "@"),
            "ttl": int(record.get("ttl") or 1),
        }
        if record_type == "SRV":
            target = (record.get("target") or "").strip()
            payload["priority"] = max(0, ensure_int(record.get("priority"), 0))
            payload["data"] = {
                "priority": payload["priority"],
                "weight": max(0, ensure_int(record.get("weight"), 0)),
                "port": max(1, ensure_int(record.get("port"), 1)),
                "target": target,
            }
            if not target:
                raise DnsError("SRV 目标不能为空")
        else:
            payload["content"] = (record.get("value") or "").strip()
            if record_type == "MX":
                payload["priority"] = max(1, ensure_int(record.get("priority"), 10))
            if not payload["content"]:
                raise DnsError("记录值不能为空")
        if record_type in {"A", "AAAA", "CNAME"} and isinstance(record.get("proxied"), bool):
            payload["proxied"] = record.get("proxied")
        parsed = self.request(f"/zones/{zone_id}/dns_records/{record_id}", method="PUT", payload=payload)
        result = parsed.get("result") or {}
        return {"id": result.get("id") or record_id}

    def delete_record(self, zone: dict, record_id: str) -> dict:
        zone_id = (zone.get("id") or "").strip()
        record_id = record_id.strip()
        if not zone_id or not record_id:
            raise DnsError("缺少记录删除参数")
        self.request(f"/zones/{zone_id}/dns_records/{record_id}", method="DELETE")
        return {"id": record_id}

    def verify(self) -> dict:
        zones = self.list_zones()
        if zones:
            first_zone = zones[0]
            self.list_records({
                "id": first_zone.get("id") or "",
                "name": first_zone.get("name") or "",
            })
        return {"zones_count": len(zones)}

    def delete_many(self, zone: dict, record_ids: list) -> dict:
        success = 0
        failed = []
        for record_id in record_ids:
            try:
                self.delete_record(zone, str(record_id))
                success += 1
            except DnsError as exc:
                failed.append({"id": str(record_id), "msg": str(exc)})
        return {"success_count": success, "failed": failed}


def make_provider(account: dict):
    provider = (account.get("provider") or "").strip().lower()
    if provider == "aliyun":
        return AliyunProvider(account)
    if provider == "cloudflare":
        return CloudflareProvider(account)
    raise DnsError(f"暂不支持的 DNS 厂商: {provider}")


def handle(payload: dict) -> None:
    action = (payload.get("action") or "").strip()
    if action == "providers.list":
        succeed({
            "providers": [
                {"id": "aliyun", "label": "Aliyun DNS"},
                {"id": "cloudflare", "label": "Cloudflare"},
            ]
        })

    account = payload.get("account")
    if not isinstance(account, dict):
        raise DnsError("缺少账号信息")
    provider = make_provider(account)

    if action == "account.verify":
        succeed(provider.verify(), "连接测试通过")

    if action == "zones.list":
        succeed({"zones": provider.list_zones()})

    zone = payload.get("zone") or {}
    if not isinstance(zone, dict):
        raise DnsError("缺少 Zone 信息")

    if action == "records.list":
        succeed({"records": provider.list_records(zone)})

    record = payload.get("record") or {}
    if not isinstance(record, dict):
        raise DnsError("缺少记录信息")

    if action == "record.create":
        result = provider.create_record(zone, record)
        succeed(result, "记录已创建")

    if action == "record.update":
        result = provider.update_record(zone, record)
        succeed(result, "记录已更新")

    if action == "record.delete":
        record_id = (record.get("id") or "").strip()
        result = provider.delete_record(zone, record_id)
        succeed(result, "记录已删除")

    if action == "records.delete_many":
        record_ids = payload.get("record_ids") or []
        if not isinstance(record_ids, list):
            raise DnsError("批量删除参数格式错误")
        result = provider.delete_many(zone, record_ids)
        succeed(result, "批量删除完成")

    raise DnsError(f"不支持的操作: {action}")


def main() -> None:
    try:
        payload = read_payload()
        handle(payload)
    except DnsError as exc:
        fail(str(exc))
    except json.JSONDecodeError:
        fail("输入参数不是合法 JSON")
    except Exception as exc:
        fail(f"DNS 核心异常: {exc}")


if __name__ == "__main__":
    main()
