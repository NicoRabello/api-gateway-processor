<?php

namespace Tests\Feature;

use App\Models\ProcessedLog;
use App\Repositories\ProcessedLogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_a_valid_file_with_one_line(): void
    {
        $file = $this->writeLogFile([$this->line()]);

        $this->artisan('logs:process', ['path' => $file])
            ->expectsOutput('Log processing finished.')
            ->assertSuccessful();

        $this->assertDatabaseCount('processed_logs', 1);

        $log = ProcessedLog::firstOrFail();
        $this->assertSame('11111111-1111-1111-1111-111111111111', $log->consumer_id);
        $this->assertSame('billing-api', $log->service_name);
        $this->assertSame('2015-06-02 01:50:22', $log->started_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($log->processed_at);
        $this->assertNotSame($log->started_at->timestamp, $log->processed_at->timestamp);
    }

    public function test_it_processes_multiple_lines(): void
    {
        $file = $this->writeLogFile([
            $this->line(['service' => ['name' => 'billing-api']]),
            $this->line(['service' => ['name' => 'catalog-api'], 'started_at' => 1433209823425]),
        ]);

        $this->artisan('logs:process', ['path' => $file])->assertSuccessful();

        $this->assertDatabaseCount('processed_logs', 2);
    }

    public function test_it_processes_real_consumer_uuid_shape(): void
    {
        $file = $this->writeLogFile([
            $this->line([
                'authenticated_entity' => [
                    'consumer_id' => [
                        'uuid' => '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
                    ],
                ],
                'started_at' => 1566660387,
            ]),
        ]);

        $this->artisan('logs:process', ['path' => $file])->assertSuccessful();

        $this->assertDatabaseHas('processed_logs', [
            'consumer_id' => '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
        ]);

        $log = ProcessedLog::firstOrFail();

        $this->assertSame('2019-08-24 15:26:27', $log->started_at->format('Y-m-d H:i:s'));
    }

    public function test_it_handles_an_empty_file(): void
    {
        $file = $this->writeLogFile([]);

        $this->artisan('logs:process', ['path' => $file])
            ->expectsOutput('Processed lines: 0')
            ->assertSuccessful();

        $this->assertDatabaseCount('processed_logs', 0);
    }

    public function test_it_fails_for_a_missing_path(): void
    {
        $this->artisan('logs:process', ['path' => storage_path('framework/testing/missing.ndjson')])
            ->expectsOutput('The informed log file does not exist.')
            ->assertFailed();
    }

    public function test_it_counts_invalid_json_without_stopping_processing(): void
    {
        $file = $this->writeLogFile([
            '{invalid-json',
            $this->line(),
        ]);

        $this->artisan('logs:process', ['path' => $file])
            ->expectsOutput('Invalid lines: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('processed_logs', 1);
    }

    public function test_it_allows_missing_optional_fields(): void
    {
        $file = $this->writeLogFile([
            json_encode(['started_at' => 1433209822425], JSON_THROW_ON_ERROR),
        ]);

        $this->artisan('logs:process', ['path' => $file])->assertSuccessful();

        $this->assertDatabaseHas('processed_logs', [
            'consumer_id' => null,
            'service_name' => null,
            'request_latency' => null,
            'proxy_latency' => null,
            'gateway_latency' => null,
        ]);
    }

    public function test_reprocessing_does_not_duplicate_records(): void
    {
        $file = $this->writeLogFile([$this->line()]);

        $this->artisan('logs:process', ['path' => $file])->assertSuccessful();
        $this->artisan('logs:process', ['path' => $file])
            ->expectsOutput('Ignored/skipped records: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('processed_logs', 1);
    }

    public function test_identical_payload_on_different_lines_counts_as_distinct_events(): void
    {
        $line = $this->line();
        $file = $this->writeLogFile([$line, $line]);

        $this->artisan('logs:process', ['path' => $file])
            ->expectsOutput('Inserted records: 2')
            ->assertSuccessful();

        $this->assertDatabaseCount('processed_logs', 2);
    }

    public function test_checkpoint_does_not_advance_when_database_insert_fails(): void
    {
        $file = $this->writeLogFile([
            $this->line(['started_at' => 1433209822425]),
            $this->line(['started_at' => 1433209823425]),
        ]);

        $repository = $this->mock(ProcessedLogRepository::class);
        $repository->shouldReceive('insertBatchIgnoringDuplicates')
            ->once()
            ->andThrow(new RuntimeException('database unavailable'));

        $this->artisan('logs:process', ['path' => $file])
            ->expectsOutput('Failed to persist log batch ending at line 2.')
            ->assertFailed();

        $this->assertDatabaseCount('import_checkpoints', 0);
    }

    public function test_overwritten_file_at_same_path_is_processed_from_the_beginning(): void
    {
        $directory = storage_path('framework/testing/logs');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $file = $directory.'/overwrite.ndjson';
        file_put_contents($file, $this->line(['started_at' => 1433209822425]));

        $this->artisan('logs:process', ['path' => $file])->assertSuccessful();

        file_put_contents($file, $this->line([
            'authenticated_entity' => [
                'consumer_id' => '77777777-7777-7777-7777-777777777777',
            ],
            'started_at' => 1433209823425,
        ]));

        $this->artisan('logs:process', ['path' => $file])->assertSuccessful();

        $this->assertDatabaseHas('processed_logs', [
            'consumer_id' => '77777777-7777-7777-7777-777777777777',
        ]);
        $this->assertDatabaseCount('processed_logs', 2);
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

        $file = $directory.'/logs-'.uniqid().'.ndjson';
        file_put_contents($file, implode(PHP_EOL, $lines));

        return $file;
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
