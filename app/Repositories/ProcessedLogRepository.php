<?php

namespace App\Repositories;

use App\DTOs\ParsedGatewayLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ProcessedLogRepository
{
    public function insertIgnoringDuplicate(ParsedGatewayLog $log, CarbonInterface $processedAt): bool
    {
        $inserted = $this->insertBatchIgnoringDuplicates([$log], $processedAt);

        return $inserted === 1;
    }

    /**
     * @param  list<ParsedGatewayLog>  $logs
     */
    public function insertBatchIgnoringDuplicates(array $logs, CarbonInterface $processedAt): int
    {
        if ($logs === []) {
            return 0;
        }

        $now = now();

        return DB::table('processed_logs')->insertOrIgnore(array_map(
            fn (ParsedGatewayLog $log): array => [
                'payload_hash' => $log->payloadHash,
                'source_file_hash' => $log->sourceFileHash,
                'source_file' => $log->sourceFile,
                'line_number' => $log->lineNumber,
                'consumer_id' => $log->consumerId,
                'service_id' => $log->serviceId,
                'service_name' => $log->serviceName,
                'request_method' => $log->requestMethod,
                'request_uri' => $log->requestUri,
                'response_status' => $log->responseStatus,
                'request_latency' => $log->requestLatency,
                'proxy_latency' => $log->proxyLatency,
                'gateway_latency' => $log->gatewayLatency,
                'client_ip' => $log->clientIp,
                'started_at' => $log->startedAt,
                'processed_at' => $processedAt,
                'created_at' => $log->startedAt,
                'updated_at' => $now,
            ],
            $logs,
        ));
    }

    public function totalsByConsumer(): LazyCollection
    {
        return DB::table('processed_logs')
            ->selectRaw("COALESCE(NULLIF(consumer_id, ''), 'UNKNOWN_CONSUMER') as consumer_id")
            ->selectRaw('COUNT(*) as total_requests')
            ->groupByRaw("COALESCE(NULLIF(consumer_id, ''), 'UNKNOWN_CONSUMER')")
            ->orderBy('consumer_id')
            ->cursor();
    }

    public function totalsByService(): LazyCollection
    {
        return DB::table('processed_logs')
            ->selectRaw("COALESCE(NULLIF(service_name, ''), 'UNKNOWN_SERVICE') as service_name")
            ->selectRaw('COUNT(*) as total_requests')
            ->groupByRaw("COALESCE(NULLIF(service_name, ''), 'UNKNOWN_SERVICE')")
            ->orderBy('service_name')
            ->cursor();
    }

    public function latencyAveragesByService(): LazyCollection
    {
        return DB::table('processed_logs')
            ->selectRaw("COALESCE(NULLIF(service_name, ''), 'UNKNOWN_SERVICE') as service_name")
            ->selectRaw('AVG(request_latency) as avg_request_latency')
            ->selectRaw('AVG(proxy_latency) as avg_proxy_latency')
            ->selectRaw('AVG(gateway_latency) as avg_gateway_latency')
            ->groupByRaw("COALESCE(NULLIF(service_name, ''), 'UNKNOWN_SERVICE')")
            ->orderBy('service_name')
            ->cursor();
    }
}
