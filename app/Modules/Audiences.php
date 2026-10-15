<?php

namespace App\Modules;

use App\Models\Contact;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Catalogue of the criteria that pick the recipients of a bulk message
 * (/admin/bulk-messages) and of the per-recipient placeholders its text may
 * use. The core and the modules register them, so the bulk message screen
 * needs no knowledge of memberships, dues and the like.
 *
 * Criterion types and the value the apply closure receives:
 *   multi   string[]  option keys (any of them)
 *   select  string    one option key
 *   number  float     e.g. a minimum amount
 *   range   array{from: ?int, to: ?int}  e.g. years
 *   flag    true      a condition that is either on or off
 */
class Audiences
{
    public const TYPES = ['multi', 'select', 'number', 'range', 'flag'];

    private array $criteria = [];

    private array $placeholders = [];

    /**
     * @param  Closure(Builder<Contact>, mixed): mixed  $apply  narrows the contact query
     * @param  array<string, string>|Closure(): array<string, string>  $options  key => label, for multi and select
     */
    public function criterion(string $key, string $label, string $type, Closure $apply, array|Closure $options = [], int $order = 100, ?string $hint = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown criterion type [$type].");
        }

        $this->criteria[$key] = compact('key', 'label', 'type', 'apply', 'options', 'order', 'hint');
    }

    /**
     * Criteria in order, their options read.
     *
     * @return array<string, array{key: string, label: string, type: string, options: array<string, string>, hint: ?string}>
     */
    public function criteria(): array
    {
        $criteria = $this->criteria;
        uasort($criteria, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(fn (array $criterion) => ['options' => $this->options($criterion)] + $criterion, $criteria);
    }

    /**
     * Keeps the known criteria with a usable value, in the shape apply() expects.
     *
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $clean = [];

        foreach ($this->criteria as $key => $criterion) {
            $value = $input[$key] ?? null;

            $value = match ($criterion['type']) {
                'multi' => array_values(array_intersect(array_map('strval', (array) $value), array_map('strval', array_keys($this->options($criterion))))) ?: null,
                'select' => is_scalar($value) && array_key_exists((string) $value, $this->options($criterion)) ? (string) $value : null,
                'number' => is_numeric($value) && (float) $value >= 0 ? (float) $value : null,
                'range' => $this->range($value),
                'flag' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ?: null,
            };

            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * The contact query narrowed by the criteria (all must hold).
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function apply(Builder $query, array $criteria): Builder
    {
        foreach ($this->normalize($criteria) as $key => $value) {
            ($this->criteria[$key]['apply'])($query, $value);
        }

        return $query;
    }

    /**
     * The criteria in words, for lists and the process log.
     *
     * @return string[]
     */
    public function describe(array $criteria): array
    {
        $lines = [];

        foreach ($this->normalize($criteria) as $key => $value) {
            $criterion = $this->criteria[$key];
            $options = $this->options($criterion);

            $lines[] = $criterion['label'].match ($criterion['type']) {
                'multi' => ': '.implode(', ', array_map(fn ($option) => $options[$option] ?? $option, $value)),
                'select' => ': '.($options[$value] ?? $value),
                'number' => ': '.rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ','),
                'range' => ': '.($value['from'] ?? '…').' – '.($value['to'] ?? '…'),
                'flag' => '',
            };
        }

        return $lines;
    }

    /**
     * @param  Closure(Contact): (string|int|float|HtmlString|null)  $value
     */
    public function placeholder(string $key, string $description, Closure $value, int $order = 100): void
    {
        $this->placeholders[$key] = compact('key', 'description', 'value', 'order');
    }

    /**
     * Per-recipient placeholders: the registered ones, then the contact
     * fields (first_name, custom.<key>...).
     *
     * @return array<string, string> name => description
     */
    public function placeholders(): array
    {
        $placeholders = $this->placeholders;
        uasort($placeholders, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(fn (array $placeholder) => $placeholder['description'], $placeholders)
            + app(ContactFields::class)->options();
    }

    /**
     * Values of the placeholders the text uses, for one contact.
     *
     * @return array<string, string|int|float|HtmlString|null>
     */
    public function values(?Contact $contact, string $text): array
    {
        if (! $contact || ! preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}|%7B%7B([a-z0-9_.]+)%7D%7D/i', $text, $matches)) {
            return [];
        }

        $fields = app(ContactFields::class);
        $values = [];

        foreach (array_unique(array_filter(array_merge($matches[1], $matches[2]))) as $name) {
            if (isset($this->placeholders[$name])) {
                $values[$name] = ($this->placeholders[$name]['value'])($contact);
            } elseif ($fields->has($name)) {
                $values[$name] = $fields->value($name, $contact);
            }
        }

        return $values;
    }

    private function options(array $criterion): array
    {
        return $criterion['options'] instanceof Closure ? ($criterion['options'])() : $criterion['options'];
    }

    private function range(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $from = isset($value['from']) && is_numeric($value['from']) ? (int) $value['from'] : null;
        $to = isset($value['to']) && is_numeric($value['to']) ? (int) $value['to'] : null;

        return $from === null && $to === null ? null : ['from' => $from, 'to' => $to];
    }
}
