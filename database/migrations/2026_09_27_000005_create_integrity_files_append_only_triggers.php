<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Database\AppendOnlyTriggers;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * Rejects UPDATE and DELETE on stored file records, like on versions.
     */
    public function up(): void
    {
        if (filter_var(config('model-integrity.append_only_triggers', true), FILTER_VALIDATE_BOOL)) {
            app(AppendOnlyTriggers::class)->install($this->connection(), $this->table());
        }
    }

    public function down(): void
    {
        app(AppendOnlyTriggers::class)->uninstall($this->connection(), $this->table());
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return DB::connection($this->getConnection());
    }

    private function table(): string
    {
        return Config::string('model-integrity.tables.files', 'integrity_files');
    }
};
