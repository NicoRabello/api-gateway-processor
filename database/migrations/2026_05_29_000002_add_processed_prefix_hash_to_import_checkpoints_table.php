<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('import_checkpoints', 'processed_prefix_hash')) {
            Schema::table('import_checkpoints', function (Blueprint $table): void {
                $table->string('processed_prefix_hash', 64)
                    ->default(hash('sha256', ''))
                    ->after('first_line_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('import_checkpoints', 'processed_prefix_hash')) {
            Schema::table('import_checkpoints', function (Blueprint $table): void {
                $table->dropColumn('processed_prefix_hash');
            });
        }
    }
};
