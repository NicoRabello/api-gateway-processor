<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealPayloadEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_real_payload_shape_and_generates_consumer_report(): void
    {
        $file = $this->writeLogFile([
            json_encode([
                'request' => [
                    'method' => 'GET',
                    'uri' => '/',
                ],
                'response' => [
                    'status' => 500,
                ],
                'authenticated_entity' => [
                    'consumer_id' => [
                        'uuid' => '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
                    ],
                ],
                'service' => [
                    'id' => 'c3e86413-648a-3552-90c3-b13491ee07d6',
                    'name' => 'ritchie',
                ],
                'latencies' => [
                    'proxy' => 1836,
                    'gateway' => 8,
                    'request' => 1058,
                ],
                'client_ip' => '75.241.168.121',
                'started_at' => 1566660387,
            ], JSON_THROW_ON_ERROR),
        ]);
        $output = 'storage/framework/testing/reports/'.uniqid();

        $this->artisan('logs:process', ['path' => $file])->assertSuccessful();
        $this->artisan('reports:generate', ['--output' => $output])->assertSuccessful();

        $consumerCsv = file(base_path($output.'/consumer_requests.csv'), FILE_IGNORE_NEW_LINES);
        $serviceCsv = file(base_path($output.'/service_requests.csv'), FILE_IGNORE_NEW_LINES);

        $this->assertContains('72b34d31-4c14-3bae-9cc6-516a0939c9d6,1', $consumerCsv);
        $this->assertNotContains(',1', $consumerCsv);
        $this->assertContains('ritchie,1', $serviceCsv);
        $this->assertSame(1, DB::table('processed_logs')->count());
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLogFile(array $lines): string
    {
        $directory = storage_path('framework/testing/logs');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $file = $directory.'/real-payload-'.uniqid().'.ndjson';
        file_put_contents($file, implode(PHP_EOL, $lines));

        return $file;
    }
}
