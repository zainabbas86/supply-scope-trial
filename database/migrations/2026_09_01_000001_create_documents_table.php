<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            // UUID, not an auto-increment integer. Document ids appear in URLs
            // and inside queue payloads. Sequential integers would advertise how
            // many documents exist and let anyone walk the range — and with
            // per-owner scoping, an enumerable id is the difference between a
            // 404 and a data leak.
            $table->uuid('id')->primary();

            // --- Ownership -------------------------------------------------
            // Polymorphic from day one. `owner_type` is always User today; a
            // Tenant model later needs a data migration, not a schema rewrite.
            // Creates owner_type + owner_id and indexes them together.
            $table->morphs('owner');

            // Separate from the owner, deliberately. Today this duplicates
            // owner_id, but the moment an organisation owns a document the two
            // diverge — the org owns it, a person uploaded it. Recording it now
            // avoids backfilling attribution that was never captured.
            // nullOnDelete: deleting a user must not delete their org's documents.
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // --- The file --------------------------------------------------
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');

            // Fixed width: a hex SHA-256 is always exactly 64 characters.
            $table->char('sha256', 64);

            // Null until a PDF is parsed; images have no page count.
            $table->unsignedSmallInteger('page_count')->nullable();

            // --- Where it lives --------------------------------------------
            // The disk is stored PER DOCUMENT so that files written under a
            // local disk stay readable after a later migration to S3/GCS.
            // Hardcoding the disk in config would strand existing rows.
            $table->string('disk', 64);
            $table->string('storage_path');

            // --- Lifecycle --------------------------------------------------
            $table->string('status', 32)->default(DocumentStatus::Queued->value);

            // Two failure fields with different audiences: a stable machine code
            // to branch and aggregate on, and prose for the person looking at
            // the screen. Collapsing them into one means either the UI shows
            // `openai_timeout` or the code matches on English.
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_reason')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);

            // Three timestamps, not just updated_at. These are what separate
            // "it was queued a long time" from "the model was slow" — with a
            // single timestamp that question is unanswerable after the fact.
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // --- Indexes ----------------------------------------------------
            // THE dedupe index, and it is a security boundary, not a
            // performance tweak. A bare index on sha256 alone invites a global
            // dedupe lookup, and a global dedupe hands owner B a document
            // belonging to owner A. Scoping the index to the owner makes the
            // cheap query the correct query.
            $table->index(['owner_type', 'owner_id', 'sha256'], 'documents_owner_sha256_index');

            // Backs the status-polling endpoint, which asks "does this owner
            // have anything still in flight?" on a 2.5s cadence.
            $table->index(['owner_type', 'owner_id', 'status'], 'documents_owner_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
