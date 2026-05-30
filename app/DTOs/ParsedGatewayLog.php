<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class ParsedGatewayLog
{
    public function __construct(
        public string $payloadHash,
        public string $sourceFileHash,
        public string $sourceFile,
        public int $lineNumber,
        public ?string $consumerId,
        public ?string $serviceId,
        public ?string $serviceName,
        public ?string $requestMethod,
        public ?string $requestUri,
        public ?int $responseStatus,
        public ?int $requestLatency,
        public ?int $proxyLatency,
        public ?int $gatewayLatency,
        public ?string $clientIp,
        public CarbonImmutable $startedAt,
    ) {}
}
