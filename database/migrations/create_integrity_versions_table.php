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

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // Global, gapless sequence assigned under the head lock. Not auto_increment,
            // because rolled back transactions would leave gaps in an auto_increment column.
            $table->unsignedBigInteger('sequence')->unique();

            // String ids support integer, UUID and ULID keys alike.
            $table->string('versionable_type');
            $table->string('versionable_id', 64);
            $table->unsignedInteger('version');

            $table->string('event', 32);
            $table->unsignedSmallInteger('hash_format');
            $table->unsignedInteger('schema_version');
            $table->json('snapshot');

            $table->char('prev_hash', 64)->nullable();
            $table->char('global_prev_hash', 64)->nullable();
            $table->char('hash', 64)->unique();

            $table->string('actor_type')->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();

            // datetime instead of timestamp: no implicit timezone conversion, no 2038 limit.
            // Values are always written in UTC.
            $table->dateTime('created_at', 6);

            $table->unique(['versionable_type', 'versionable_id', 'version']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::string('model-integrity.tables.versions', 'integrity_versions');
    }
};
