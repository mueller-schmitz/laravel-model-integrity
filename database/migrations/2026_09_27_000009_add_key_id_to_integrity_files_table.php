<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * The data subject key a file is encrypted with; null for files stored
     * without encryption. Subject keys are never deleted (shredding leaves a
     * tombstone), so there is no foreign key.
     */
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->char('key_id', 26)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('key_id');
        });
    }

    private function table(): string
    {
        return Config::string('model-integrity.tables.files', 'integrity_files');
    }
};
