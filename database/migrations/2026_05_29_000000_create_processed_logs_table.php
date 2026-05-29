<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('payload_hash', 64);
            $table->string('source_file_hash', 64);
            $table->string('source_file', 1024);
            $table->unsignedBigInteger('line_number');
            $table->uuid('consumer_id')->nullable();
            $table->uuid('service_id')->nullable();
            $table->string('service_name')->nullable();
            $table->string('request_method', 16)->nullable();
            $table->text('request_uri')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('request_latency')->nullable();
            $table->unsignedInteger('proxy_latency')->nullable();
            $table->unsignedInteger('gateway_latency')->nullable();
            $table->ipAddress('client_ip')->nullable();
            $table->dateTime('started_at', 3);
            $table->dateTime('processed_at', 3);
            $table->timestamps(3);

            $table->unique(['source_file_hash', 'line_number', 'payload_hash'], 'processed_logs_source_line_payload_unique');
            $table->index('payload_hash');
            $table->index('consumer_id');
            $table->index('service_name');
            $table->index('service_id');
            $table->index('started_at');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_logs');
    }
};
