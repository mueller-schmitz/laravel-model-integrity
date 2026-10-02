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
     * One key per data subject for the personal data in snapshots. Shredding
     * removes the key and keeps the row, so a shredded subject never gets a
     * new key. Rows are updated, never deleted.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('subject', 191)->unique();
            $table->text('key')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('shredded_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::string('model-integrity.tables.subject_keys', 'integrity_subject_keys');
    }
};
