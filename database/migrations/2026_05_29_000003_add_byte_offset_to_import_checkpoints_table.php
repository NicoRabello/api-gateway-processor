<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('import_checkpoints', 'last_processed_byte_offset')) {
            Schema::table('import_checkpoints', function (Blueprint $table): void {
                $table->unsignedBigInteger('last_processed_byte_offset')
                    ->default(0)
                    ->after('last_processed_line');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('import_checkpoints', 'last_processed_byte_offset')) {
            Schema::table('import_checkpoints', function (Blueprint $table): void {
                $table->dropColumn('last_processed_byte_offset');
            });
        }
    }
};
