<?php

namespace Modules\FonzipImport\Support;

use Illuminate\Support\Carbon;

/**
 * Copies the association's Fonzip data into a snapshot, a little at a time:
 * step() works for a limited time and remembers where it stopped, so each
 * queued job stays short. The whole copy takes about one request per person
 * (custom field values come only with the person's details), ~15 minutes for
 * 750 people at Fonzip's 60 requests a minute.
 */
class FonzipFetcher
{
    private const PAGE = 100;

    /** Stages in order; each pages through one listing. */
    private const STAGES = ['meta', 'users', 'details', 'debts', 'payments', 'refunds', 'donations', 'done'];

    public function __construct(private FonzipClient $client, private FonzipStore $store) {}

    /**
     * Start a new copy (the previous snapshot is dropped).
     */
    public function start(): void
    {
        $this->store->saveSnapshot([
            'started_at' => now()->toIso8601String(),
            'fetched_at' => null,
            'stage' => 'meta',
            'page' => 1,
            'fields' => [], 'tags' => [], 'categories' => [],
            'users' => [], 'debts' => [], 'payments' => [], 'refunds' => [], 'donations' => [],
            'totals' => [],
        ]);
        $this->store->setState(['phase' => FonzipStore::FETCHING, 'progress' => 'Başlıyor', 'error' => null, 'summary' => null]);
    }

    /**
     * Fetch for about $seconds; true when the copy is complete.
     */
    public function step(float $seconds = 40): bool
    {
        $snapshot = $this->store->snapshot();
        if (! $snapshot) {
            return true;
        }

        $until = microtime(true) + $seconds;
        do {
            $done = $this->unit($snapshot);
        } while (! $done && microtime(true) < $until);

        if ($done) {
            $snapshot['fetched_at'] = now()->toIso8601String();
        }
        $this->store->saveSnapshot($snapshot);
        $this->store->setState($done
            ? ['phase' => FonzipStore::FETCHED, 'progress' => null]
            : ['progress' => $this->progress($snapshot)]);

        return $done;
    }

    /**
     * One request (or a few for the small lists); true when all is fetched.
     */
    private function unit(array &$snapshot): bool
    {
        switch ($snapshot['stage']) {
            case 'meta':
                $snapshot['fields'] = $this->client->get('user-defined-values')['udv_list'] ?? [];
                $snapshot['tags'] = $this->client->get('tags', ['how_many' => self::PAGE])['tag_list'] ?? [];
                $snapshot['categories'] = $this->client->get('donation-categories', ['how_many' => self::PAGE])['category_list'] ?? [];

                return $this->next($snapshot);

            case 'users':
                $data = $this->client->post('users', [
                    'search' => ['start_page' => $snapshot['page'], 'how_many' => self::PAGE, 'order_by' => 'id'],
                    'values_list' => FonzipImport::USER_FIELDS,
                ]);
                foreach ($data['user_list'] ?? [] as $user) {
                    $snapshot['users'][(string) $user['id']] = $user + ['detail' => null, 'tag_names' => null];
                }

                return $this->paged($snapshot, $data, 'user_list', count($snapshot['users']));

            case 'details':
                foreach ($snapshot['users'] as $id => $user) {
                    if ($user['detail'] !== null) {
                        continue;
                    }
                    $snapshot['users'][$id]['detail'] = $this->client->get("user/$id")['user'] ?? [];
                    $snapshot['users'][$id]['tag_names'] = trim((string) ($user['tags_as_text'] ?? '')) === ''
                        ? []
                        : array_values(array_filter(array_column($this->client->get("user/$id/tags")['tags'] ?? [], 'name')));

                    return false;
                }

                return $this->next($snapshot);

            case 'debts':
                $data = $this->client->get('debts', ['start_page' => $snapshot['page'], 'how_many' => self::PAGE, 'order_by' => 'id']);
                array_push($snapshot['debts'], ...($data['debt_list'] ?? []));

                return $this->paged($snapshot, $data, 'debt_list', count($snapshot['debts']));

            case 'payments':
            case 'refunds':
                $key = $snapshot['stage'];
                $data = $this->client->get('subscriptions', $this->range() + [
                    'status' => $key === 'payments' ? 'paid' : 'refund',
                    'start_page' => $snapshot['page'], 'how_many' => self::PAGE,
                ]);
                array_push($snapshot[$key], ...($data['payment_list'] ?? []));

                return $this->paged($snapshot, $data, 'payment_list', count($snapshot[$key]));

            case 'donations':
                $data = $this->client->get('donations', $this->range() + [
                    'status' => 'paid', 'start_page' => $snapshot['page'], 'how_many' => self::PAGE,
                    'list' => ['name_surname', 'amount', 'sub_donation_type', 'complete_date', 'payment_method', 'fonzip_id', 'details', 'phone'],
                ]);
                array_push($snapshot['donations'], ...($data['donation_list'] ?? []));

                return $this->paged($snapshot, $data, 'donation_list', count($snapshot['donations']));
        }

        return true;
    }

    /**
     * Move to the next page, or to the next stage after the last page.
     */
    private function paged(array &$snapshot, array $data, string $list, int $have): bool
    {
        $snapshot['totals'][$snapshot['stage']] = (int) ($data['total'] ?? $have);
        if (($data[$list] ?? []) === [] || $have >= $snapshot['totals'][$snapshot['stage']]) {
            return $this->next($snapshot);
        }
        $snapshot['page']++;

        return false;
    }

    private function next(array &$snapshot): bool
    {
        $snapshot['stage'] = self::STAGES[array_search($snapshot['stage'], self::STAGES, true) + 1];
        $snapshot['page'] = 1;

        return $snapshot['stage'] === 'done';
    }

    /**
     * Payments and donations are listed only with a date range.
     */
    private function range(): array
    {
        return [
            'start_date' => Carbon::parse(config('fonzip-import.history_from'), 'Europe/Istanbul')->startOfDay()->toIso8601String(),
            'end_date' => now('Europe/Istanbul')->endOfDay()->toIso8601String(),
        ];
    }

    private function progress(array $snapshot): string
    {
        $users = count($snapshot['users']);

        return match ($snapshot['stage']) {
            'meta' => 'Alan ve etiket tanımları',
            'users' => "Kişi listesi: $users kişi",
            'details' => 'Kişi ayrıntıları: '.collect($snapshot['users'])->whereNotNull('detail')->count()." / $users",
            'debts' => 'Aidat borçları: '.count($snapshot['debts']).' / '.($snapshot['totals']['debts'] ?? '?'),
            'payments' => 'Aidat ödemeleri: '.count($snapshot['payments']).' / '.($snapshot['totals']['payments'] ?? '?'),
            'refunds' => 'Aidat iadeleri',
            'donations' => 'Bağışlar: '.count($snapshot['donations']),
            default => 'Bitiyor',
        };
    }
}
