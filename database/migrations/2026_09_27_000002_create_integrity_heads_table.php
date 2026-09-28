<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        // Current head per chain. This table is a mutable pointer and is intentionally
        // excluded from the append-only triggers; the versions table is the evidence.
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->string('chain', 191)->primary();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->char('hash', 64)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        // The global head must exist before the first write, otherwise lockForUpdate()
        // has no row to lock and concurrent writers could claim the same sequence.
        DB::connection($this->getConnection())->table($this->table())->insert([
            'chain' => 'global',
            'sequence' => 0,
            'hash' => null,
            'updated_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::string('model-integrity.tables.heads', 'integrity_heads');
    }
};
