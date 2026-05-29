<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('source_file_hash', 64)->unique();
            $table->string('first_line_hash', 64);
            $table->string('source_file', 1024);
            $table->unsignedBigInteger('last_processed_line')->default(0);
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_checkpoints');
    }
};
