<?php

namespace App\Modules;

use App\Models\MailTemplate;
use App\Support\Organization;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Catalogue of the emails the system sends. The core and the modules
 * register each email under a fixed key with its default subject and body
 * and the placeholders it fills ({{name}}); management edits the text at
 * /admin/mail-templates, and the edited text is kept in mail_templates.
 *
 * Placeholder values are escaped unless given as HtmlString (e.g. a list).
 * The common placeholders (association name, portal address...) are
 * available in every template.
 */
class MailTemplates
{
    private array $templates = [];

    private array $groups = [];

    public function group(string $key, string $label, int $order = 100): void
    {
        $this->groups[$key] = compact('label', 'order');
    }

    /**
     * @param  array<string, string>  $placeholders  name => description, without the common ones
     * @param  string|null  $recipient  who receives it, shown to management
     */
    public function register(string $key, string $label, string $group, string $subject, string $body, array $placeholders = [], ?string $recipient = null, int $order = 100): void
    {
        $this->templates[$key] = compact('key', 'label', 'group', 'subject', 'body', 'placeholders', 'recipient', 'order');
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    public function get(string $key): ?array
    {
        return $this->templates[$key] ?? null;
    }

    public function label(string $key): ?string
    {
        return $this->templates[$key]['label'] ?? null;
    }

    /**
     * Templates by group, in order: [['label' => ..., 'templates' => [...]], ...].
     */
    public function grouped(): array
    {
        $templates = $this->templates;
        uasort($templates, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $grouped = [];
        foreach ($templates as $template) {
            $group = $template['group'];
            $grouped[$group] ??= ['label' => $this->groups[$group]['label'] ?? $group, 'order' => $this->groups[$group]['order'] ?? 100, 'templates' => []];
            $grouped[$group]['templates'][] = $template;
        }
        uasort($grouped, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_values($grouped);
    }

    /**
     * Placeholders every template may use.
     *
     * @return array<string, string>
     */
    public static function common(): array
    {
        return [
            'organization' => 'Derneğin adı',
            'organization_short' => 'Derneğin kısa adı',
            'website' => 'Derneğin web sitesi',
            'portal_url' => 'Portalın adresi',
            'contact_email' => 'Derneğin iletişim e-postası',
            'year' => 'İçinde bulunulan yıl',
        ];
    }

    /**
     * Current subject and body: the edited text, else the default.
     *
     * @return array{subject: string, body: string, edited: bool}
     */
    public function source(string $key): array
    {
        $template = $this->templates[$key] ?? throw new \InvalidArgumentException("Unknown mail template [$key].");
        $row = $this->edited($key);

        return [
            'subject' => $row?->subject ?: $template['subject'],
            'body' => $row?->body ?: $template['body'],
            'edited' => (bool) ($row?->subject || $row?->body),
        ];
    }

    /**
     * Subject (plain text) and HTML body with the placeholders filled.
     *
     * @param  array<string, string|int|float|HtmlString|null>  $values
     * @return array{subject: string, body: HtmlString}
     */
    public function render(string $key, array $values = []): array
    {
        $source = $this->source($key);

        return $this->fill($source['subject'], $source['body'], $values);
    }

    /**
     * @param  array<string, string|int|float|HtmlString|null>  $values
     * @return array{subject: string, body: HtmlString}
     */
    public function fill(string $subject, string $body, array $values): array
    {
        $values += $this->commonValues();

        $subject = $this->replace($subject, fn ($value) => trim(strip_tags((string) $value)), $values);
        $body = $this->replace($body, fn ($value) => $value instanceof HtmlString ? $value->toHtml() : e((string) $value), $values);

        return ['subject' => trim(preg_replace('/\s+/', ' ', html_entity_decode($subject, ENT_QUOTES))), 'body' => new HtmlString($body)];
    }

    /**
     * Sample values for a preview: each placeholder shows its description.
     *
     * @return array<string, HtmlString>
     */
    public function samples(string $key): array
    {
        return collect($this->templates[$key]['placeholders'] ?? [])
            ->map(fn (string $description, string $name) => new HtmlString('<mark>['.e($description).']</mark>'))->all();
    }

    private function replace(string $text, \Closure $format, array $values): string
    {
        // {{name}} and {{ name }}; an editor may also have URL-encoded the braces in a link.
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}|%7B%7B([a-z0-9_.]+)%7D%7D/i', function (array $match) use ($format, $values) {
            $name = $match[1] !== '' ? $match[1] : $match[2];

            return array_key_exists($name, $values) ? $format($values[$name]) : $match[0];
        }, $text);
    }

    private function commonValues(): array
    {
        $organization = app(Organization::class);

        return [
            'organization' => $organization->name(),
            'organization_short' => $organization->shortName(),
            'website' => (string) $organization->get('website_url'),
            'portal_url' => rtrim((string) config('app.url'), '/'),
            'contact_email' => (string) $organization->get('contact_email'),
            'year' => (string) now()->year,
        ];
    }

    /**
     * Read at every send, not cached: the queue worker runs for days.
     */
    private function edited(string $key): ?MailTemplate
    {
        try {
            return MailTemplate::where('key', $key)->first();
        } catch (Throwable) {
            return null;
        }
    }
}
