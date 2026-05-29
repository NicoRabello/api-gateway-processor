<?php

namespace App\Exporters;

class ServiceLatencyAveragesCsvExporter extends CsvExporter
{
    protected function headers(): array
    {
        return ['service_name', 'avg_request_latency', 'avg_proxy_latency', 'avg_gateway_latency'];
    }

    protected function row(object $row): array
    {
        return [
            $row->service_name,
            $this->average($row->avg_request_latency),
            $this->average($row->avg_proxy_latency),
            $this->average($row->avg_gateway_latency),
        ];
    }

    private function average(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
