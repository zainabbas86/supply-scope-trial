/**
 * The shapes the server actually sends.
 *
 * Hand-maintained mirrors of DocumentController's payloads. Nothing enforces
 * the match — PHP types do not flow into TypeScript — so renaming a key
 * server-side leaves the type-checker perfectly happy while the UI renders
 * `undefined`. Change one, change the other.
 *
 * At ~20 models the answer is spatie/laravel-typescript-transformer generating
 * these from PHP DTOs. At two shapes, a generator costs more than it saves.
 */

export type DocumentStatus = 'queued' | 'processing' | 'completed' | 'failed';

export type ProductType = 'food' | 'non_food' | 'unknown';

/**
 * How much of an allergen statement the document actually carried.
 *
 * `not_completed` is the important one: the manufacturer left the statement
 * blank, so anything we know came from reading the ingredient text — and must
 * never be presented as a declaration.
 */
export type AllergenStatementStatus =
    | 'declared'
    | 'not_completed'
    | 'absent'
    | 'not_applicable';

export interface DocumentSummary {
    id: string;
    filename: string;
    status: DocumentStatus;
    statusLabel: string;
    pageCount: number | null;
    attempts: number;
    failureCode: string | null;
    failureReason: string | null;
    productName: string | null;
    brand: string | null;
    createdAt: string | null;
    finishedAt: string | null;
}

export interface Ingredients {
    raw_text: string | null;
    items: string[];
    source_page: number | null;
}

export interface Allergens {
    statement_status: AllergenStatementStatus;
    declared: string[];
    derived_from_ingredients: string[];
    source_page: number | null;
}

export interface NetWeight {
    value: number | null;
    unit: string | null;
    basis: 'per_pack' | 'per_portion' | 'per_carton' | 'unknown';
    raw_text: string | null;
    source_page: number | null;
}

export interface Extraction {
    productName: string | null;
    productNamePage: number | null;
    brand: string | null;
    brandPage: number | null;
    productType: ProductType;
    ingredients: Ingredients | null;
    allergens: Allergens | null;
    netWeight: NetWeight | null;
    warnings: string[];
    schemaVersion: number;
    raw: unknown;
}

export interface Attempt {
    number: number;
    outcome: string;
    model: string;
    httpStatus: number | null;
    latencyMs: number | null;
    tokens: number | null;
    errorMessage: string | null;
    at: string | null;
}

export interface DocumentDetail extends DocumentSummary {
    sizeBytes: number;
    mimeType: string;
    sha256: string;
    extraction: Extraction | null;
    attempts_log?: Attempt[];
}

/** A file the server refused, with the reason to show beside it. */
export interface RejectedFile {
    filename: string;
    code: string;
    reason: string;
}
