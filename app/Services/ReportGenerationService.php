<?php

namespace App\Services;

use App\Exporters\ConsumerRequestsCsvExporter;
use App\Exporters\ServiceLatencyAveragesCsvExporter;
use App\Exporters\ServiceRequestsCsvExporter;
use App\Repositories\ProcessedLogRepository;
use InvalidArgumentException;

class ReportGenerationService
{
    public function __construct(
        private readonly ProcessedLogRepository $repository,
        private readonly ConsumerRequestsCsvExporter $consumerRequestsExporter,
        private readonly ServiceRequestsCsvExporter $serviceRequestsExporter,
        private readonly ServiceLatencyAveragesCsvExporter $serviceLatencyAveragesExporter,
    ) {}

    public function generate(string $type, string $outputDirectory): string
    {
        return match ($type) {
            'consumers' => $this->consumerRequestsExporter->export(
                $this->repository->totalsByConsumer(),
                $outputDirectory.DIRECTORY_SEPARATOR.'consumer_requests.csv',
            ),
            'services' => $this->serviceRequestsExporter->export(
                $this->repository->totalsByService(),
                $outputDirectory.DIRECTORY_SEPARATOR.'service_requests.csv',
            ),
            'latencies' => $this->serviceLatencyAveragesExporter->export(
                $this->repository->latencyAveragesByService(),
                $outputDirectory.DIRECTORY_SEPARATOR.'service_latency_averages.csv',
            ),
            default => throw new InvalidArgumentException('Invalid report type. Use consumers, services, or latencies.'),
        };
    }

    /**
     * @return list<string>
     */
    public function generateAll(string $outputDirectory): array
    {
        return [
            $this->generate('consumers', $outputDirectory),
            $this->generate('services', $outputDirectory),
            $this->generate('latencies', $outputDirectory),
        ];
    }
}
