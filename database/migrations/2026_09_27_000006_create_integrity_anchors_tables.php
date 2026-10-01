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

    /**
     * Anchors attest ranges of the global chain outside the database; proofs
     * hold what each driver returned, including later upgrades. Both tables
     * are append-only.
     */
    public function up(): void
    {
        Schema::create($this->anchors(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('anchor_format');
            $table->unsignedBigInteger('from_sequence');
            $table->unsignedBigInteger('to_sequence')->unique();
            $table->char('merkle_root', 64);
            $table->char('prev_digest', 64)->nullable();
            $table->char('digest', 64)->unique();
            $table->dateTime('created_at', 6);
        });

        Schema::create($this->proofs(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('anchor_id');
            $table->string('driver', 64);
            $table->longText('proof');
            $table->dateTime('created_at', 6);

            $table->index(['anchor_id', 'driver'], $this->proofs().'_anchor_driver_index');
        });

        // Serializes anchor runs like the global head serializes writes:
        // sequence is the last anchored sequence, hash the last digest.
        DB::connection($this->getConnection())->table($this->heads())->insert([
            'chain' => 'anchors',
            'sequence' => 0,
            'hash' => null,
            'updated_at' => null,
        ]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table($this->heads())->where('chain', 'anchors')->delete();
        Schema::dropIfExists($this->proofs());
        Schema::dropIfExists($this->anchors());
    }

    private function anchors(): string
    {
        return Config::string('model-integrity.tables.anchors', 'integrity_anchors');
    }

    private function proofs(): string
    {
        return Config::string('model-integrity.tables.anchor_proofs', 'integrity_anchor_proofs');
    }

    private function heads(): string
    {
        return Config::string('model-integrity.tables.heads', 'integrity_heads');
    }
};
