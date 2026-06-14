<?php
// includes/s3_client.php
//
// Minimal, dependency-free S3 client for an S3-compatible object store
// (here: a Ceph RADOS Gateway at nz.in.s3.joy.services:7480). Implements
// AWS Signature V4 in pure PHP (hash_hmac), path-style addressing.
//
// Capabilities:
//   s3_presign_put($key,$expiry,$type)  → one-time upload URL (curl -T)
//   s3_presign_get($key,$expiry,$name)  → one-time download URL (browser)
//   s3_list($prefix,$max,$token)        → ListObjectsV2
//   s3_head($key)                       → [exists, size]
//   s3_delete($key)                     → delete one object
//
// Config comes from .env via vormox_env(): S3_ENDPOINT, S3_REGION, S3_BUCKET,
// S3_ACCESS_KEY, S3_SECRET_KEY, S3_PRESIGN_EXPIRY.
//
// Presigned URLs sign only the `host` header with UNSIGNED-PAYLOAD, so the
// uploader (backend host) never needs the secret and curl can send extra
// unsigned headers (Content-Type/Length) without breaking the signature.

require_once __DIR__ . '/env.php';

if (!function_exists('s3_cfg')) {

    function s3_cfg(): array {
        static $c = null;
        if ($c !== null) return $c;
        $endpoint = rtrim((string) vormox_env('S3_ENDPOINT', ''), '/');
        $parts = parse_url($endpoint) ?: [];
        $host = $parts['host'] ?? '';
        if (!empty($parts['port'])) $host .= ':' . $parts['port'];
        $c = [
            'endpoint' => $endpoint,
            'host'     => $host,
            'region'   => (string) vormox_env('S3_REGION', 'us-east-1'),
            'bucket'   => (string) vormox_env('S3_BUCKET', ''),
            'access'   => (string) vormox_env('S3_ACCESS_KEY', ''),
            'secret'   => (string) vormox_env('S3_SECRET_KEY', ''),
            'expiry'   => (int) vormox_env('S3_PRESIGN_EXPIRY', 900),
        ];
        return $c;
    }

    /** True if the four credential fields are present. */
    function s3_configured(): bool {
        $c = s3_cfg();
        return $c['endpoint'] !== '' && $c['bucket'] !== '' && $c['access'] !== '' && $c['secret'] !== '';
    }

    /** RFC3986 percent-encoding (AWS variant). Optionally preserve '/'. */
    function s3_uri_encode(string $s, bool $keepSlash = false): string {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if (($ch >= 'A' && $ch <= 'Z') || ($ch >= 'a' && $ch <= 'z') ||
                ($ch >= '0' && $ch <= '9') || $ch === '-' || $ch === '_' || $ch === '.' || $ch === '~') {
                $out .= $ch;
            } elseif ($ch === '/' && $keepSlash) {
                $out .= '/';
            } else {
                $out .= '%' . strtoupper(bin2hex($ch));
            }
        }
        return $out;
    }

    /** Extract the first <tag>…</tag> inner text from an XML fragment (no extension needed). */
    function s3_xml_tag(string $xml, string $tag): ?string {
        $t = preg_quote($tag, '#');
        if (preg_match('#<' . $t . '\b[^>]*>(.*?)</' . $t . '>#s', $xml, $mm)) return $mm[1];
        return null;
    }

    /** Derive the SigV4 signing key (raw binary). */
    function s3_signing_key(string $secret, string $date, string $region, string $service = 's3'): string {
        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * Build a presigned (query-auth) URL for one object.
     * $extraQuery lets callers add e.g. response-content-disposition for GET.
     */
    function s3_presign(string $method, string $key, ?int $expiry = null, array $extraQuery = []): string {
        $cfg     = s3_cfg();
        $expiry  = $expiry ?? $cfg['expiry'];
        $amzdate = gmdate('Ymd\THis\Z');
        $date    = gmdate('Ymd');
        $scope   = "{$date}/{$cfg['region']}/s3/aws4_request";
        $uri     = '/' . $cfg['bucket'] . '/' . s3_uri_encode($key, true);

        $query = array_merge([
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $cfg['access'] . '/' . $scope,
            'X-Amz-Date'          => $amzdate,
            'X-Amz-Expires'       => (string) $expiry,
            'X-Amz-SignedHeaders' => 'host',
        ], $extraQuery);
        ksort($query);
        $pairs = [];
        foreach ($query as $k => $v) $pairs[] = s3_uri_encode($k) . '=' . s3_uri_encode((string) $v);
        $canonicalQuery = implode('&', $pairs);

        $canonicalRequest = implode("\n", [
            $method, $uri, $canonicalQuery,
            "host:{$cfg['host']}\n",   // canonical headers (trailing \n is part of the block)
            'host',                    // signed headers
            'UNSIGNED-PAYLOAD',
        ]);
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzdate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signature    = hash_hmac('sha256', $stringToSign, s3_signing_key($cfg['secret'], $date, $cfg['region']));

        return $cfg['endpoint'] . $uri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    function s3_presign_put(string $key, ?int $expiry = null, string $contentType = 'application/x-xz'): string {
        return s3_presign('PUT', $key, $expiry, []);
    }

    function s3_presign_get(string $key, ?int $expiry = null, string $downloadName = ''): string {
        $extra = [];
        if ($downloadName !== '') {
            $extra['response-content-disposition'] = 'attachment; filename="' . str_replace('"', '', $downloadName) . '"';
        }
        return s3_presign('GET', $key, $expiry, $extra);
    }

    /**
     * Header-auth SigV4 request (for list/head/delete). Returns
     * ['status','body','headers','error'].
     */
    function s3_request(string $method, string $uri, array $query = [], string $body = ''): array {
        $cfg     = s3_cfg();
        $amzdate = gmdate('Ymd\THis\Z');
        $date    = gmdate('Ymd');
        $scope   = "{$date}/{$cfg['region']}/s3/aws4_request";
        $payloadHash = hash('sha256', $body);

        ksort($query);
        $pairs = [];
        foreach ($query as $k => $v) $pairs[] = s3_uri_encode($k) . '=' . s3_uri_encode((string) $v);
        $canonicalQuery = implode('&', $pairs);

        $headers = [
            'host'                 => $cfg['host'],
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzdate,
        ];
        ksort($headers);
        $canonicalHeaders = '';
        $signed = [];
        foreach ($headers as $k => $v) { $canonicalHeaders .= $k . ':' . trim($v) . "\n"; $signed[] = $k; }
        $signedHeaders = implode(';', $signed);

        $canonicalRequest = implode("\n", [
            $method, $uri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash,
        ]);
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzdate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signature    = hash_hmac('sha256', $stringToSign, s3_signing_key($cfg['secret'], $date, $cfg['region']));
        $authz = "AWS4-HMAC-SHA256 Credential={$cfg['access']}/{$scope}, "
               . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = $cfg['endpoint'] . $uri . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authz,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzdate,
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$respHeaders) {
                $p = explode(':', $h, 2);
                if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($h);
            },
        ]);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== '' && in_array($method, ['PUT', 'POST'], true)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'body' => ($resp === false ? '' : $resp), 'headers' => $respHeaders, 'error' => $err];
    }

    /** ListObjectsV2. Returns ['ok','objects'=>[[key,size,modified]],'next','status','error']. */
    function s3_list(string $prefix = '', int $maxKeys = 1000, ?string $token = null): array {
        $cfg = s3_cfg();
        $query = ['list-type' => '2', 'max-keys' => (string) $maxKeys];
        if ($prefix !== '')                    $query['prefix'] = $prefix;
        if ($token !== null && $token !== '')  $query['continuation-token'] = $token;

        $res = s3_request('GET', '/' . $cfg['bucket'], $query, '');
        $out = ['ok' => false, 'objects' => [], 'next' => null, 'status' => $res['status'], 'error' => $res['error']];
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $out['error'] = $res['error'] ?: trim($res['body']);
            return $out;
        }
        // Parse without any XML extension — prod PHP may lack ext-simplexml/dom.
        if (preg_match_all('#<Contents>(.*?)</Contents>#s', $res['body'], $m)) {
            foreach ($m[1] as $block) {
                $key = s3_xml_tag($block, 'Key');
                if ($key === null) continue;
                $out['objects'][] = [
                    'key'      => html_entity_decode($key, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    'size'     => (int) (s3_xml_tag($block, 'Size') ?? 0),
                    'modified' => (string) (s3_xml_tag($block, 'LastModified') ?? ''),
                ];
            }
        }
        if (s3_xml_tag($res['body'], 'IsTruncated') === 'true') {
            $out['next'] = s3_xml_tag($res['body'], 'NextContinuationToken');
        }
        $out['ok'] = true;
        return $out;
    }

    /** HEAD one object. Returns ['exists','size','status']. */
    function s3_head(string $key): array {
        $cfg = s3_cfg();
        $uri = '/' . $cfg['bucket'] . '/' . s3_uri_encode($key, true);
        $res = s3_request('HEAD', $uri, [], '');
        $exists = $res['status'] >= 200 && $res['status'] < 300;
        return [
            'exists' => $exists,
            'size'   => $exists ? (int) ($res['headers']['content-length'] ?? 0) : 0,
            'status' => $res['status'],
        ];
    }

    /**
     * Stream an object to the browser THROUGH this server, so the S3 endpoint is
     * never exposed to the client (the presigned URL is used server-side only).
     * Sets attachment headers and pipes the bytes. Returns false if not found.
     */
    function s3_stream_to_browser(string $key, string $downloadName): bool {
        if (!s3_configured()) { http_response_code(500); return false; }
        $head = s3_head($key);
        if (!$head['exists']) { http_response_code(404); return false; }

        $name = str_replace(['"', "\r", "\n"], '', $downloadName !== '' ? $downloadName : basename($key));
        header('Content-Type: application/x-xz');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        if ($head['size'] > 0) header('Content-Length: ' . $head['size']);
        while (ob_get_level() > 0) { ob_end_flush(); }

        $url = s3_presign_get($key, 120);   // server-side only — never reaches the client
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => true,   // don't stream an S3 error body as if it were the file
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) { echo $data; flush(); return strlen($data); },
        ]);
        $ok = curl_exec($ch);
        curl_close($ch);
        return $ok !== false;
    }

    /** DELETE one object. Returns ['ok','status','error']. */
    function s3_delete(string $key): array {
        $cfg = s3_cfg();
        $uri = '/' . $cfg['bucket'] . '/' . s3_uri_encode($key, true);
        $res = s3_request('DELETE', $uri, [], '');
        $ok  = $res['status'] >= 200 && $res['status'] < 300;
        return ['ok' => $ok, 'status' => $res['status'], 'error' => $ok ? '' : ($res['error'] ?: trim($res['body']))];
    }
}
