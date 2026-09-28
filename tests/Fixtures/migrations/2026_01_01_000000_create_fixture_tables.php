<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number');
            $table->decimal('total', 10, 2);
            $table->double('rate')->nullable();
            $table->boolean('paid')->default(false);
            $table->unsignedInteger('quantity')->default(1);
            $table->dateTime('issued_at')->nullable();
            $table->date('due_on')->nullable();
            $table->json('meta')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('priority')->default(1);
            $table->text('secret')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('post_tag', function (Blueprint $table): void {
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('cast_samples', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('happened_at')->nullable();
            $table->unsignedInteger('stamp')->nullable();
            $table->dateTime('frozen_at')->nullable();
            $table->date('born_on')->nullable();
            $table->json('payload')->nullable();
            $table->json('items')->nullable();
            $table->json('tags')->nullable();
            $table->json('statuses')->nullable();
            $table->string('label')->nullable();
            $table->integer('amount')->nullable();
            $table->string('password')->nullable();
        });

        Schema::create('ulid_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->timestamps();
        });
    }
};
