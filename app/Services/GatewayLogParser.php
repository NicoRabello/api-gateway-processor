<?php

namespace App\Services;

use App\DTOs\ParsedGatewayLog;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

class GatewayLogParser
{
    /**
     * @throws JsonException|InvalidArgumentException
     */
    public function parse(string $line, string $sourceFile = '', int $lineNumber = 0): ParsedGatewayLog
    {
        $payload = trim($line);

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Log line must be a JSON object.');
        }

        return new ParsedGatewayLog(
            payloadHash: hash('sha256', $payload),
            sourceFile: $sourceFile,
            lineNumber: $lineNumber,
            consumerId: $this->uuidValue($this->firstStringValue($data, [
                ['authenticated_entity', 'consumer_id', 'uuid'],
                ['authenticated_entity', 'consumer_id'],
                ['consumer', 'id'],
                ['consumer', 'uuid'],
                ['consumer_id'],
                ['request', 'headers', 'x-consumer-id'],
                ['request', 'headers', 'X-Consumer-ID'],
            ])),
            serviceId: $this->uuidValue($this->stringValue($data, ['service', 'id'])),
            serviceName: $this->boundedStringValue($data, ['service', 'name'], 255),
            requestMethod: $this->boundedStringValue($data, ['request', 'method'], 16),
            requestUri: $this->stringValue($data, ['request', 'uri']),
            responseStatus: $this->responseStatus($data),
            requestLatency: $this->unsignedIntValue($data, ['latencies', 'request']),
            proxyLatency: $this->unsignedIntValue($data, ['latencies', 'proxy']),
            gatewayLatency: $this->unsignedIntValue($data, ['latencies', 'gateway']),
            clientIp: $this->ipValue($this->stringValue($data, ['client_ip'])),
            startedAt: $this->startedAt($data['started_at'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<list<string>>  $paths
     */
    private function firstStringValue(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->stringValue($data, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $path
     */
    private function stringValue(array $data, array $path): ?string
    {
        $value = $this->value($data, $path);

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $path
     */
    private function boundedStringValue(array $data, array $path, int $maxLength): ?string
    {
        $value = $this->stringValue($data, $path);

        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $path
     */
    private function unsignedIntValue(array $data, array $path): ?int
    {
        $value = $this->value($data, $path);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer >= 0 ? $integer : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function responseStatus(array $data): ?int
    {
        $status = $this->unsignedIntValue($data, ['response', 'status']);

        if ($status === null) {
            return null;
        }

        return $status >= 100 && $status <= 599 ? $status : null;
    }

    private function uuidValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1
            ? strtolower($value)
            : null;
    }

    private function ipValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $path
     */
    private function value(array $data, array $path): mixed
    {
        $cursor = $data;

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    private function startedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Missing or invalid started_at.');
        }

        $milliseconds = (int) $value;

        if ($milliseconds < 100000000000) {
            $milliseconds *= 1000;
        }

        $seconds = intdiv($milliseconds, 1000);
        $microseconds = ($milliseconds % 1000) * 1000;

        return CarbonImmutable::createFromTimestamp($seconds, 'UTC')
            ->setMicroseconds($microseconds);
    }
}
