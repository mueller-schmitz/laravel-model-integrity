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
     * Rejects UPDATE and DELETE on anchors and their proofs, like on versions.
     */
    public function up(): void
    {
        if (filter_var(config('model-integrity.append_only_triggers', true), FILTER_VALIDATE_BOOL)) {
            foreach ($this->tables() as $table) {
                app(AppendOnlyTriggers::class)->install($this->connection(), $table);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            app(AppendOnlyTriggers::class)->uninstall($this->connection(), $table);
        }
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return DB::connection($this->getConnection());
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            Config::string('model-integrity.tables.anchors', 'integrity_anchors'),
            Config::string('model-integrity.tables.anchor_proofs', 'integrity_anchor_proofs'),
        ];
    }
};
