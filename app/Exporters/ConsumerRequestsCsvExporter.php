<?php

namespace App\Exporters;

class ConsumerRequestsCsvExporter extends CsvExporter
{
    protected function headers(): array
    {
        return ['consumer_id', 'total_requests'];
    }

    protected function row(object $row): array
    {
        return [$row->consumer_id, $row->total_requests];
    }
}
