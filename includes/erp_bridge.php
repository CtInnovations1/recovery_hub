<?php
declare(strict_types=1);

final class ERPBridgeException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}

final class ERPBridge
{
    public function __construct(private readonly array $config) {}

    public function request(string $method, string $path, ?array $payload = null, ?string $token = null): array
    {
        $base = rtrim((string) ($this->config['erp_base_url'] ?? ''), '/');
        if (($this->config['environment'] ?? '') === 'local' && !in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '[::1]'], true)) {
            throw new ERPBridgeException('Local mode requires a local ERP API endpoint.');
        }
        $localHttp = ($this->config['environment'] ?? '') === 'local' &&
            parse_url($base, PHP_URL_SCHEME) === 'http' && in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '[::1]'], true);
        if ((!str_starts_with($base, 'https://') && !$localHttp) || strlen((string) ($this->config['erp_key'] ?? '')) < 32) {
            throw new ERPBridgeException('Configure the ERP HTTPS URL and integration key on Hostinger.');
        }
        if (!str_starts_with($path, '/') || str_contains($path, '://')) {
            throw new InvalidArgumentException('Invalid ERP API path.');
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Recovery-Key: '.$this->config['erp_key']];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer '.$token;
        }
        $curl = curl_init($base.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $failed = $body === false;
        curl_close($curl);
        if ($failed) {
            throw new ERPBridgeException('ERP response was not received. Retry the same operation to recover its receipt.');
        }
        $data = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) ? ($data['message'] ?? 'ERP rejected the request.') : 'ERP returned HTTP '.$status;
            if (is_array($data['errors'] ?? null)) {
                $message = implode(' ', array_merge(...array_values($data['errors'])));
            }
            throw new ERPBridgeException((string) $message, $status);
        }
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            throw new ERPBridgeException('ERP returned an invalid integration response.', $status);
        }
        return $data;
    }
}
