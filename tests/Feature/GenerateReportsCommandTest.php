<?php

namespace Tests\Feature;

use App\Services\ReportGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerateReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_consumer_report(): void
    {
        $this->seedLogs();
        $output = 'storage/framework/testing/reports/'.uniqid();

        $this->artisan('reports:generate', ['type' => 'consumers', '--output' => $output])
            ->assertSuccessful();

        $csv = file(base_path($output.'/consumer_requests.csv'), FILE_IGNORE_NEW_LINES);

        $this->assertSame('consumer_id,total_requests', $csv[0]);
        $this->assertContains('11111111-1111-1111-1111-111111111111,2', $csv);
        $this->assertContains('33333333-3333-3333-3333-333333333333,1', $csv);
    }

    public function test_it_generates_service_report(): void
    {
        $this->seedLogs();
        $output = 'storage/framework/testing/reports/'.uniqid();

        $this->artisan('reports:generate', ['type' => 'services', '--output' => $output])
            ->assertSuccessful();

        $csv = file(base_path($output.'/service_requests.csv'), FILE_IGNORE_NEW_LINES);

        $this->assertSame('service_name,total_requests', $csv[0]);
        $this->assertContains('billing-api,2', $csv);
        $this->assertContains('catalog-api,1', $csv);
    }

    public function test_it_generates_latency_report(): void
    {
        $this->seedLogs();
        $output = 'storage/framework/testing/reports/'.uniqid();

        $this->artisan('reports:generate', ['type' => 'latencies', '--output' => $output])
            ->assertSuccessful();

        $csv = file(base_path($output.'/service_latency_averages.csv'), FILE_IGNORE_NEW_LINES);

        $this->assertSame('service_name,avg_request_latency,avg_proxy_latency,avg_gateway_latency', $csv[0]);
        $this->assertContains('billing-api,150,100,15', $csv);
        $this->assertContains('catalog-api,300,200,30', $csv);
    }

    public function test_it_generates_all_reports_when_type_is_omitted(): void
    {
        $this->seedLogs();
        $output = 'storage/framework/testing/reports/'.uniqid();

        $this->artisan('reports:generate', ['--output' => $output])
            ->assertSuccessful();

        $this->assertFileExists(base_path($output.'/consumer_requests.csv'));
        $this->assertFileExists(base_path($output.'/service_requests.csv'));
        $this->assertFileExists(base_path($output.'/service_latency_averages.csv'));
    }

    public function test_it_sanitizes_csv_injection_values(): void
    {
        $this->insertLog(['service_name' => '=SUM(A1:A2)']);
        $output = 'storage/framework/testing/reports/'.uniqid();

        $this->artisan('reports:generate', ['type' => 'services', '--output' => $output])
            ->assertSuccessful();

        $csv = file(base_path($output.'/service_requests.csv'), FILE_IGNORE_NEW_LINES);

        $this->assertContains('\'=SUM(A1:A2),1', $csv);
    }

    public function test_it_uses_explicit_buckets_for_missing_group_keys(): void
    {
        $this->insertLog([
            'consumer_id' => null,
            'service_name' => null,
            'request_latency' => null,
            'proxy_latency' => null,
            'gateway_latency' => null,
        ]);
        $output = 'storage/framework/testing/reports/'.uniqid();

        $this->artisan('reports:generate', ['--output' => $output])
            ->assertSuccessful();

        $consumerCsv = file(base_path($output.'/consumer_requests.csv'), FILE_IGNORE_NEW_LINES);
        $serviceCsv = file(base_path($output.'/service_requests.csv'), FILE_IGNORE_NEW_LINES);
        $latencyCsv = file(base_path($output.'/service_latency_averages.csv'), FILE_IGNORE_NEW_LINES);

        $this->assertContains('UNKNOWN_CONSUMER,1', $consumerCsv);
        $this->assertContains('UNKNOWN_SERVICE,1', $serviceCsv);
        $this->assertContains('UNKNOWN_SERVICE,,,', $latencyCsv);
    }

    public function test_it_rejects_output_path_traversal(): void
    {
        $this->artisan('reports:generate', ['type' => 'consumers', '--output' => '../outside'])
            ->expectsOutput('Output directory must be a relative path inside the project.')
            ->assertFailed();
    }

    public function test_it_handles_exporter_runtime_errors_without_stack_trace(): void
    {
        $service = $this->mock(ReportGenerationService::class);
        $service->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('Unable to open CSV output file.'));

        $this->artisan('reports:generate', ['type' => 'consumers'])
            ->expectsOutput('Unable to open CSV output file.')
            ->doesntExpectOutput('RuntimeException')
            ->assertFailed();
    }

    private function seedLogs(): void
    {
        $this->insertLog([
            'payload_hash' => hash('sha256', 'one'),
            'consumer_id' => '11111111-1111-1111-1111-111111111111',
            'service_name' => 'billing-api',
            'request_latency' => 100,
            'proxy_latency' => 80,
            'gateway_latency' => 10,
        ]);
        $this->insertLog([
            'payload_hash' => hash('sha256', 'two'),
            'consumer_id' => '11111111-1111-1111-1111-111111111111',
            'service_name' => 'billing-api',
            'request_latency' => 200,
            'proxy_latency' => 120,
            'gateway_latency' => 20,
        ]);
        $this->insertLog([
            'payload_hash' => hash('sha256', 'three'),
            'consumer_id' => '33333333-3333-3333-3333-333333333333',
            'service_name' => 'catalog-api',
            'request_latency' => 300,
            'proxy_latency' => 200,
            'gateway_latency' => 30,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertLog(array $overrides = []): void
    {
        DB::table('processed_logs')->insert(array_merge([
            'payload_hash' => hash('sha256', uniqid('', true)),
            'source_file_hash' => hash('sha256', '/tmp/logs.txt'),
            'source_file' => '/tmp/logs.txt',
            'line_number' => random_int(1, 100000),
            'consumer_id' => '11111111-1111-1111-1111-111111111111',
            'service_id' => '22222222-2222-2222-2222-222222222222',
            'service_name' => 'billing-api',
            'request_method' => 'GET',
            'request_uri' => '/invoices',
            'response_status' => 200,
            'request_latency' => 120,
            'proxy_latency' => 80,
            'gateway_latency' => 15,
            'client_ip' => '127.0.0.1',
            'started_at' => now()->subDay(),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
