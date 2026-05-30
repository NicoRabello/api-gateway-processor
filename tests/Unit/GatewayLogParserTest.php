<?php

namespace Tests\Unit;

use App\Services\GatewayLogParser;
use JsonException;
use PHPUnit\Framework\TestCase;

class GatewayLogParserTest extends TestCase
{
    public function test_it_parses_a_valid_line(): void
    {
        $log = (new GatewayLogParser)->parse($this->line(), '/tmp/logs.txt', 1);

        $this->assertSame('11111111-1111-1111-1111-111111111111', $log->consumerId);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $log->serviceId);
        $this->assertSame('billing-api', $log->serviceName);
        $this->assertSame('GET', $log->requestMethod);
        $this->assertSame('/invoices', $log->requestUri);
        $this->assertSame(200, $log->responseStatus);
        $this->assertSame(120, $log->requestLatency);
        $this->assertSame(80, $log->proxyLatency);
        $this->assertSame(15, $log->gatewayLatency);
        $this->assertSame('127.0.0.1', $log->clientIp);
    }

    public function test_it_rejects_invalid_json(): void
    {
        $this->expectException(JsonException::class);

        (new GatewayLogParser)->parse('{invalid-json');
    }

    public function test_it_rejects_valid_json_that_is_not_an_object(): void
    {
        $parser = new GatewayLogParser;

        foreach (['null', '123', '"text"', '[]'] as $line) {
            try {
                $parser->parse($line);
                $this->fail("Expected InvalidArgumentException for {$line}.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_allows_missing_fields(): void
    {
        $log = (new GatewayLogParser)->parse('{"started_at":1433209822425}');

        $this->assertNull($log->consumerId);
        $this->assertNull($log->serviceName);
        $this->assertNull($log->requestLatency);
        $this->assertNull($log->proxyLatency);
        $this->assertNull($log->gatewayLatency);
    }

    public function test_it_converts_started_at_from_milliseconds(): void
    {
        $log = (new GatewayLogParser)->parse($this->line());

        $this->assertSame('2015-06-02 01:50:22.425000', $log->startedAt?->format('Y-m-d H:i:s.u'));
    }

    public function test_it_converts_started_at_from_seconds(): void
    {
        $log = (new GatewayLogParser)->parse($this->line(['started_at' => 1566660387]));

        $this->assertSame('2019-08-24 15:26:27.000000', $log->startedAt?->format('Y-m-d H:i:s.u'));
    }

    public function test_it_parses_consumer_id_from_uuid_object(): void
    {
        $log = (new GatewayLogParser)->parse($this->line([
            'authenticated_entity' => [
                'consumer_id' => [
                    'uuid' => '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
                ],
            ],
        ]));

        $this->assertSame('72b34d31-4c14-3bae-9cc6-516a0939c9d6', $log->consumerId);
    }

    public function test_it_parses_consumer_id_from_fallback_paths(): void
    {
        $parser = new GatewayLogParser;

        $fromConsumer = $parser->parse(json_encode([
            'consumer' => ['id' => '44444444-4444-4444-4444-444444444444'],
            'started_at' => 1433209822425,
        ], JSON_THROW_ON_ERROR));

        $fromTopLevel = $parser->parse(json_encode([
            'consumer_id' => '55555555-5555-5555-5555-555555555555',
            'started_at' => 1433209822425,
        ], JSON_THROW_ON_ERROR));

        $fromHeader = $parser->parse(json_encode([
            'request' => [
                'headers' => [
                    'x-consumer-id' => '66666666-6666-6666-6666-666666666666',
                ],
            ],
            'started_at' => 1433209822425,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('44444444-4444-4444-4444-444444444444', $fromConsumer->consumerId);
        $this->assertSame('55555555-5555-5555-5555-555555555555', $fromTopLevel->consumerId);
        $this->assertSame('66666666-6666-6666-6666-666666666666', $fromHeader->consumerId);
    }

    public function test_it_rejects_missing_started_at(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new GatewayLogParser)->parse('{"service":{"name":"billing-api"}}');
    }

    public function test_it_generates_a_stable_payload_hash(): void
    {
        $parser = new GatewayLogParser;

        $first = $parser->parse($this->line())->payloadHash;
        $second = $parser->parse($this->line())->payloadHash;

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    private function line(array $overrides = []): string
    {
        $payload = array_replace_recursive([
            'request' => [
                'method' => 'GET',
                'uri' => '/invoices',
            ],
            'response' => [
                'status' => 200,
            ],
            'authenticated_entity' => [
                'consumer_id' => '11111111-1111-1111-1111-111111111111',
            ],
            'service' => [
                'id' => '22222222-2222-2222-2222-222222222222',
                'name' => 'billing-api',
            ],
            'latencies' => [
                'request' => 120,
                'proxy' => 80,
                'gateway' => 15,
            ],
            'client_ip' => '127.0.0.1',
            'started_at' => 1433209822425,
        ], $overrides);

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
