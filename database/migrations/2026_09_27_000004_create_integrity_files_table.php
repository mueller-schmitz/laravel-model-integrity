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
     * Stored files, content-addressed by their SHA-256 hash. Rows are
     * append-only; each row is recorded as a version in the global chain.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->string('disk');
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->string('mime')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::string('model-integrity.tables.files', 'integrity_files');
    }
};
