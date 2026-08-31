<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // No unique constraint on document_id: the whole point of this
            // table is that there are many rows per document. It records every
            // attempt, successful or not — which is what makes "why did this
            // document fail?" answerable from the database instead of by
            // grepping container logs that have already rotated away.
            $table->foreignUuid('document_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('attempt_no');

            // Which model and which prompt produced this. Both change over the
            // life of the app, and without them a bad batch of results cannot
            // be attributed to the change that caused it.
            $table->string('model', 64);
            $table->string('prompt_version', 32);

            $table->string('outcome', 32);

            // Exception class and message are separate: the class is stable
            // enough to group and alert on, the message is for a human reading
            // one row.
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();

            // Latency and tokens are what turn this from a log into something
            // you can answer capacity and cost questions with: how long does a
            // typical extraction take, and what would 50,000 of them cost.
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->jsonb('raw_response')->nullable();

            $table->timestamps();

            // One row per attempt number per document. If a retry races and
            // tries to write attempt 2 twice, the database refuses rather than
            // leaving two conflicting records of the same attempt.
            $table->unique(['document_id', 'attempt_no']);

            // "Show me this document's attempts, newest first" — the detail view.
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_attempts');
    }
};
