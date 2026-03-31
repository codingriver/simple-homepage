<?php
/**
 * 阿里云解析（Alidns）RPC 调用与 dns_config 存储
 */
require_once __DIR__ . '/../../shared/auth.php';

define('DNS_CONFIG_FILE', DATA_DIR . '/dns_config.json');

function dns_config_defaults(): array {
    return [
        'access_key_id'     => '',
        'access_key_secret' => '',
        'domain_name'       => '',
        'schedule_enabled'  => false,
        'schedule_cron'     => '0 * * * *',
        'last_sync_at'      => '',
    ];
}

function load_dns_config(): array {
    $defaults = dns_config_defaults();
    if (!file_exists(DNS_CONFIG_FILE)) {
        return $defaults;
    }
    $raw = json_decode(file_get_contents(DNS_CONFIG_FILE), true);
    if (!is_array($raw)) {
        return $defaults;
    }
    return $raw + $defaults;
}

function save_dns_config(array $cfg): void {
    $cfg = $cfg + dns_config_defaults();
    file_put_contents(DNS_CONFIG_FILE,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX);
}

function alidns_percent_encode(string $str): string {
    $res = rawurlencode($str);
    return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $res);
}

final class AliyunDnsClient {
    public function __construct(
        private string $accessKeyId,
        private string $accessKeySecret
    ) {}

    /**
     * @return array{ok:bool,http:int,body:string,json:array|null,err:string}
     */
    public function request(array $params): array {
        $params['Format']           = 'JSON';
        $params['Version']          = '2015-01-09';
        $params['AccessKeyId']      = $this->accessKeyId;
        $params['SignatureMethod']  = 'HMAC-SHA1';
        $params['SignatureVersion'] = '1.0';
        $params['SignatureNonce']   = bin2hex(random_bytes(10));
        $params['Timestamp']        = gmdate('Y-m-d\TH:i:s\Z');
        unset($params['Signature']);

        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = alidns_percent_encode((string)$k) . '=' . alidns_percent_encode((string)$v);
        }
        $canonicalizedQueryString = implode('&', $pairs);
        $stringToSign = 'GET&' . alidns_percent_encode('/') . '&' . alidns_percent_encode($canonicalizedQueryString);
        $signature    = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true));
        $params['Signature'] = $signature;

        ksort($params);
        $q = [];
        foreach ($params as $k => $v) {
            $q[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        $url = 'https://alidns.aliyuncs.com/?' . implode('&', $q);

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header'  => "Accept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'json' => null, 'err' => 'HTTP 请求失败'];
        }
        $json = json_decode($body, true);
        return ['ok' => true, 'http' => 200, 'body' => $body, 'json' => is_array($json) ? $json : null, 'err' => ''];
    }

    /** @return array{ok:bool,records:array,msg:string} */
    public function describeDomainRecords(string $domain, int $page = 1, int $pageSize = 100): array {
        $r = $this->request([
            'Action'     => 'DescribeDomainRecords',
            'DomainName' => $domain,
            'PageNumber' => (string)$page,
            'PageSize'   => (string)$pageSize,
        ]);
        if (!$r['ok']) {
            return ['ok' => false, 'records' => [], 'msg' => $r['err']];
        }
        $j = $r['json'] ?? [];
        if (isset($j['Code'])) {
            return ['ok' => false, 'records' => [], 'msg' => ($j['Message'] ?? $j['Code'])];
        }
        $rec = $j['DomainRecords']['Record'] ?? [];
        if ($rec !== [] && !isset($rec[0])) {
            $rec = [$rec];
        }
        return ['ok' => true, 'records' => $rec, 'msg' => ''];
    }

    public function addDomainRecord(string $domain, string $rr, string $type, string $value, int $ttl = 600): array {
        $r = $this->request([
            'Action'     => 'AddDomainRecord',
            'DomainName' => $domain,
            'RR'         => $rr,
            'Type'       => $type,
            'Value'      => $value,
            'TTL'        => (string)$ttl,
        ]);
        return $this->wrapSimple($r, '添加');
    }

    public function updateDomainRecord(string $recordId, string $rr, string $type, string $value, int $ttl = 600): array {
        $r = $this->request([
            'Action'   => 'UpdateDomainRecord',
            'RecordId' => $recordId,
            'RR'       => $rr,
            'Type'     => $type,
            'Value'    => $value,
            'TTL'      => (string)$ttl,
        ]);
        return $this->wrapSimple($r, '更新');
    }

    public function deleteDomainRecord(string $recordId): array {
        $r = $this->request([
            'Action'   => 'DeleteDomainRecord',
            'RecordId' => $recordId,
        ]);
        return $this->wrapSimple($r, '删除');
    }

    /** @return array{ok:bool,msg:string} */
    private function wrapSimple(array $r, string $verb): array {
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['err']];
        }
        $j = $r['json'] ?? [];
        if (isset($j['Code'])) {
            return ['ok' => false, 'msg' => ($j['Message'] ?? $j['Code'])];
        }
        return ['ok' => true, 'msg' => $verb . '成功'];
    }
}
