<?php

namespace App\Exporters;

class ServiceRequestsCsvExporter extends CsvExporter
{
    protected function headers(): array
    {
        return ['service_name', 'total_requests'];
    }

    protected function row(object $row): array
    {
        return [$row->service_name, $row->total_requests];
    }
}
