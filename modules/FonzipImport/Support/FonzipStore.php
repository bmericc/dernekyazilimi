<?php

namespace Modules\FonzipImport\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The import's files on the private disk: the snapshot of Fonzip data (member
 * data, removed after the import) and the state shown on the admin page.
 */
class FonzipStore
{
    public const SNAPSHOT = 'fonzip-import/snapshot.json';

    public const STATE = 'fonzip-import/state.json';

    public const IDLE = 'idle';

    public const FETCHING = 'fetching';

    public const FETCHED = 'fetched';

    public const APPLYING = 'applying';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function state(): array
    {
        $state = json_decode((string) (Storage::disk('local')->get(self::STATE) ?? ''), true);

        return (is_array($state) ? $state : []) + ['phase' => self::IDLE, 'progress' => null, 'error' => null, 'summary' => null, 'updated_at' => null];
    }

    public function setState(array $changes): array
    {
        $state = array_merge($this->state(), $changes, ['updated_at' => now()->toIso8601String()]);
        Storage::disk('local')->put(self::STATE, json_encode($state, JSON_UNESCAPED_UNICODE));

        return $state;
    }

    public function snapshot(): ?array
    {
        if (! Storage::disk('local')->exists(self::SNAPSHOT)) {
            return null;
        }

        return json_decode(Storage::disk('local')->get(self::SNAPSHOT), true) ?: null;
    }

    public function saveSnapshot(array $snapshot): void
    {
        Storage::disk('local')->put(self::SNAPSHOT, json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Removes the fetched data (the state keeps only the summary).
     */
    public function discard(): void
    {
        Storage::disk('local')->delete(self::SNAPSHOT);
    }

    /**
     * Whether a job is (or should be) working: the page then polls.
     */
    public function busy(): bool
    {
        return in_array($this->state()['phase'], [self::FETCHING, self::APPLYING], true);
    }
}
