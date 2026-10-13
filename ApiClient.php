<?php
/**
 * WISECP · Web Hosting Billing and Digital Services Platform
 *
 * Copyright (c) WISECP LLC — All rights reserved.
 * Unlicensed copying, distribution or use is prohibited.
 *
 * Terms of service: https://wisecp.com/terms-of-service
 */
namespace WISECP\Modules\Registrars\Dynadot;

class ApiClient
{
    public ?string $error = null;
    public ?\Closure $logger = null;
    public int $timeout = 30;
    public bool $answered = false;

    private string $key;

    public function __construct(array $settings = [])
    {
        $this->key = (string) ($settings['key'] ?? '');
    }

    public function call(string $command, array $params = []): array|false
    {
        if (!$this->key) {
            $this->error = 'It is necessary to enter API information.';
            return false;
        }
        $this->error = null;
        $this->answered = false;

        $params['command'] = $command;
        $params['key'] = $this->key;

        $query = http_build_query(array_map(function ($value) {
            if ($value === true)
                return 'true';
            if ($value === false)
                return 'false';
            return $value;
        }, $params));

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.dynadot.com/api3.json?' . $query,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $cerr = curl_error($curl);
        curl_close($curl);

        if ($raw === false || $errno) {
            $this->error = $cerr ?: "Couldn't get a response from the API";
            $this->log($command, $params, $raw);
            return false;
        }

        $response = json_decode($raw, true);
        $this->log($command, $params, $response);

        if (!$response) {
            $this->error = 'The response from the API could not be resolved.';
            return false;
        }
        $this->answered = true;

        foreach ($response as $node) {
            if (is_array($node) && isset($node['ResponseCode']) && (string) $node['ResponseCode'] !== '0') {
                $this->error = (string) ($node['Error'] ?? 'Unknown API error');
                return false;
            }
        }

        return $response;
    }

    public function rest(string $path, array $query = []): array|false
    {
        if (!$this->key) {
            $this->error = 'It is necessary to enter API information.';
            return false;
        }
        $this->error = null;
        $this->answered = false;

        $url = 'https://api.dynadot.com/restful/v2/' . ltrim($path, '/');
        if ($query)
            $url .= '?' . http_build_query($query);

        $raw = false;
        $http = 0;
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->key,
                ],
            ]);
            $raw = curl_exec($curl);
            $errno = curl_errno($curl);
            $cerr = curl_error($curl);
            $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($raw === false || $errno) {
                $this->error = $cerr ?: "Couldn't get a response from the API";
                $this->log('rest:' . $path, $query, $raw);
                return false;
            }

            $body = json_decode($raw, true);
            $code = is_array($body) ? (int) ($body['code'] ?? $http) : $http;
            if ($http !== 429 && $code !== 429)
                break;
            sleep(1); // rate limited, wait and retry
        }

        $response = json_decode($raw, true);
        $this->log('rest:' . $path, $query, $response);

        if (!is_array($response)) {
            $this->error = 'The response from the API could not be resolved.';
            return false;
        }
        $this->answered = true;

        if ((int) ($response['code'] ?? $http) !== 200) {
            $this->error = (string) ($response['message'] ?? 'Unknown API error');
            return false;
        }

        return $response;
    }

    private function log(string $command, array $params, $response): void
    {
        if (!$this->logger)
            return;
        unset($params['key']);
        ($this->logger)($command, $params, $response, '');
    }
}
