<?php

declare(strict_types=1);

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // unique(): one extraction per document. This is the database
            // enforcing idempotency rather than trusting application code — if
            // a job somehow runs twice, the second insert fails loudly instead
            // of quietly producing a duplicate result.
            $table->foreignUuid('document_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // Stamped on every row so that rows written under an older prompt
            // and schema stay interpretable after the schema changes.
            $table->unsignedSmallInteger('schema_version')->default(1);

            // --- Scalar fields, each with the page it was read from ---------
            // Kept as plain columns rather than buried in JSON: these are what
            // the list view renders and what anyone would search on. The
            // companion `_page` column preserves the citation without forcing
            // a JSON extraction for every row in a table.
            $table->string('product_name')->nullable();
            $table->unsignedSmallInteger('product_name_page')->nullable();

            $table->string('brand')->nullable();
            $table->unsignedSmallInteger('brand_page')->nullable();

            $table->string('product_type', 32)->default(ProductType::Unknown->value);

            // --- Structured fields -----------------------------------------
            // jsonb, not json. Postgres stores jsonb parsed and indexable;
            // `json` is kept as text and re-parsed on every read.
            //
            // These are objects rather than scalars for a reason found in the
            // sample documents:
            //  - allergens carries statement_status + declared +
            //    derived_from_ingredients, so the app can say "the allergen
            //    statement was left incomplete, but Fish/Wheat/Milk appear in
            //    the ingredients" instead of choosing between a dangerous "none"
            //    and an invented list.
            //  - net_weight carries value + unit + basis, because one page
            //    offers 112 g per portion, 800 g per pack and 800 g x 4 per
            //    carton. "800g" alone throws away which one it is.
            $table->jsonb('ingredients')->nullable();
            $table->jsonb('allergens')->nullable();
            $table->jsonb('net_weight')->nullable();

            // Model-reported ambiguity — the things it wants a human to check.
            $table->jsonb('warnings')->nullable();

            // The unmodified provider response. Kept so that a parsing or
            // mapping bug can be re-run against real data without paying for
            // another extraction, and so a disputed value can be traced to
            // exactly what the model said.
            $table->jsonb('raw_response')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extractions');
    }
};
