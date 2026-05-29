<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedLog extends Model
{
    protected $fillable = [
        'payload_hash',
        'source_file_hash',
        'source_file',
        'line_number',
        'consumer_id',
        'service_id',
        'service_name',
        'request_method',
        'request_uri',
        'response_status',
        'request_latency',
        'proxy_latency',
        'gateway_latency',
        'client_ip',
        'started_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'response_status' => 'integer',
            'request_latency' => 'integer',
            'proxy_latency' => 'integer',
            'gateway_latency' => 'integer',
            'started_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
