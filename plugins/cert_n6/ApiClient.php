<?php

namespace McsPluginy\cert_n6;

/**
 * Klient REST n6 (report/inside). Auth jak LMS: Bearer i/lub client cert (mTLS).
 */
class ApiClient
{
    private string $baseUrl;
    private string $reportResource;
    private string $bearerToken;
    private string $clientCertFile;
    private string $clientKeyFile;
    private string $clientCertPassword;
    private bool $forceClientCert;
    private bool $tlsVerifyPeer;
    private string $caFile;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null $httpRequest
     */
    public function __construct(
        string $baseUrl = 'https://n6api.cert.pl',
        string $reportResource = 'report/inside',
        string $bearerToken = '',
        string $clientCertFile = '',
        string $clientKeyFile = '',
        string $clientCertPassword = '',
        bool $forceClientCert = false,
        bool $tlsVerifyPeer = true,
        string $caFile = '',
        ?callable $httpRequest = null,
    ) {
        $this->baseUrl             = rtrim($baseUrl, '/');
        $this->reportResource      = trim($reportResource, '/');
        $this->bearerToken         = $bearerToken;
        $this->clientCertFile      = $clientCertFile;
        $this->clientKeyFile       = $clientKeyFile;
        $this->clientCertPassword  = $clientCertPassword;
        $this->forceClientCert     = $forceClientCert;
        $this->tlsVerifyPeer       = $tlsVerifyPeer;
        $this->caFile              = $caFile;
        $this->httpRequest         = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $base = trim((string) env('mcs.cert_n6.baseUrl', 'https://n6api.cert.pl'));
        $res  = trim((string) env('mcs.cert_n6.reportResource', 'report/inside'));

        $bearer = trim((string) env('mcs.cert_n6.bearerToken', ''));
        if ($bearer === '') {
            $keyFile = trim((string) env('mcs.cert_n6.apiKeyFile', ''));
            if ($keyFile !== '' && is_readable($keyFile)) {
                $raw = @file_get_contents($keyFile);
                if ($raw !== false) {
                    $bearer = trim($raw);
                }
            }
        }

        return new self(
            $base !== '' ? $base : 'https://n6api.cert.pl',
            $res !== '' ? $res : 'report/inside',
            $bearer,
            trim((string) env('mcs.cert_n6.clientCertFile', '')),
            trim((string) env('mcs.cert_n6.clientKeyFile', '')),
            (string) env('mcs.cert_n6.clientCertPassword', ''),
            filter_var(env('mcs.cert_n6.forceClientCert', false), FILTER_VALIDATE_BOOLEAN),
            filter_var(env('mcs.cert_n6.tlsVerifyPeer', true), FILTER_VALIDATE_BOOLEAN),
            trim((string) env('mcs.cert_n6.caFile', '')),
            $httpRequest,
        );
    }

    public function hasAuth(): bool
    {
        if ($this->bearerToken !== '') {
            if (! $this->forceClientCert) {
                return true;
            }

            return $this->clientCertOk();
        }

        return $this->clientCertOk();
    }

    public function buildUrl(string $timeMin, ?int $optLimit = null, ?string $modifiedMin = null): string
    {
        return Parser::buildReportUrl(
            $this->baseUrl,
            $this->reportResource,
            $timeMin,
            $optLimit,
            $modifiedMin,
        );
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string}
     */
    public function fetchReport(string $timeMin, ?int $optLimit = null, ?string $modifiedMin = null): array
    {
        if (! $this->hasAuth()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'body'      => '',
                'error'     => 'CERT N6: ustaw mcs.cert_n6.bearerToken / apiKeyFile albo clientCertFile+clientKeyFile',
            ];
        }

        if ($this->forceClientCert && $this->bearerToken !== '' && ! $this->clientCertOk()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'body'      => '',
                'error'     => 'CERT N6: forceClientCert=true ale brak czytelnych clientCertFile/clientKeyFile',
            ];
        }

        $url = $this->buildUrl($timeMin, $optLimit, $modifiedMin);

        if ($this->httpRequest !== null) {
            return ($this->httpRequest)($url, $this->curlOptionsMeta());
        }

        return $this->curlGet($url);
    }

    /**
     * @return array<string, mixed>
     */
    private function curlOptionsMeta(): array
    {
        return [
            'bearer'       => $this->bearerToken,
            'cert'         => $this->clientCertFile,
            'key'          => $this->clientKeyFile,
            'force_cert'   => $this->forceClientCert,
            'verify_peer'  => $this->tlsVerifyPeer,
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string}
     */
    private function curlGet(string $url): array
    {
        $useCert = ($this->bearerToken === '' || $this->forceClientCert) && $this->clientCertOk();

        $ch = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerifyPeer ? 2 : 0,
            CURLOPT_USERAGENT      => 'MCS-CertN6/1.0 (n6 REST API)',
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => max(1, \App\Libraries\OpcjeMcs::int('mcs.cert_n6.httpTimeout')),
            CURLOPT_CONNECTTIMEOUT => max(1, \App\Libraries\OpcjeMcs::int('mcs.cert_n6.httpConnectTimeout')),
        ];

        if ($useCert) {
            $options[CURLOPT_SSLCERT] = $this->clientCertFile;
            $options[CURLOPT_SSLKEY]  = $this->clientKeyFile;
            if ($this->clientCertPassword !== '') {
                $options[CURLOPT_SSLCERTPASSWD] = $this->clientCertPassword;
            }
        }

        if ($this->caFile !== '' && is_readable($this->caFile)) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }

        $headers = [];
        if ($this->bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        }
        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $options);
        $output   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            return [
                'ok'        => false,
                'http_code' => $httpCode,
                'body'      => '',
                'error'     => 'cURL error: ' . $errstr . " (errno {$errno})",
            ];
        }

        if ($httpCode !== 200) {
            $snippet = substr((string) $output, 0, 500);

            return [
                'ok'        => false,
                'http_code' => $httpCode,
                'body'      => (string) $output,
                'error'     => 'HTTP ' . $httpCode . ($snippet !== '' ? (': ' . $snippet) : ''),
            ];
        }

        return [
            'ok'        => true,
            'http_code' => $httpCode,
            'body'      => (string) $output,
            'error'     => '',
        ];
    }

    private function clientCertOk(): bool
    {
        return $this->clientCertFile !== ''
            && $this->clientKeyFile !== ''
            && is_readable($this->clientCertFile)
            && is_readable($this->clientKeyFile);
    }
}
