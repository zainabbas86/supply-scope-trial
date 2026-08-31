<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $filename = fake()->slug(3).'.pdf';

        return [
            // A real user by default: an unowned document should never be
            // reachable, so the factory must not make one accidentally.
            'owner_type' => (new User)->getMorphClass(),
            'owner_id' => User::factory(),
            'uploaded_by_user_id' => null,

            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(50_000, 5_000_000),

            // A plausible 64-char hex digest — tests that assert on dedupe
            // override this to force a collision.
            'sha256' => hash('sha256', $filename.fake()->uuid()),

            'page_count' => fake()->numberBetween(1, 5),
            'disk' => 'local',
            'storage_path' => 'documents/'.date('Y/m').'/'.fake()->uuid().'.pdf',

            'status' => DocumentStatus::Queued,
            'failure_code' => null,
            'failure_reason' => null,
            'attempts' => 0,

            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /** Owned by a specific user — the basis of every cross-owner isolation test. */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => [
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'uploaded_by_user_id' => $user->getKey(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Processing,
            'attempts' => 1,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Completed,
            'attempts' => 1,
            'started_at' => now()->subSeconds(20),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $code = 'openai_timeout', string $reason = 'The AI service timed out after 3 attempts.'): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Failed,
            'failure_code' => $code,
            'failure_reason' => $reason,
            'attempts' => 3,
            'started_at' => now()->subSeconds(120),
            'finished_at' => now(),
        ]);
    }

    /** Force a specific digest, for dedupe tests. */
    public function withSha256(string $sha256): static
    {
        return $this->state(fn () => ['sha256' => $sha256]);
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'original_filename' => fake()->slug(2).'.jpg',
            'mime_type' => 'image/jpeg',
            'page_count' => null,
        ]);
    }
}
