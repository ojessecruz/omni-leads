<?php

namespace App\Domains\Enrichment\Clients;

use App\Domains\Enrichment\Data\EnrichmentResult;
use App\Models\Lead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Step 1 (free): fetches the home and contact pages looking for mailto:, e-mails and phones.
 */
class SiteScraper
{
    private const array IGNORED_EMAIL_HOSTS = ['example.com', 'sentry.io', 'wixpress.com', 'domain.com', 'email.com', 'yoursite.com', 'wordpress.org', 'godaddy.com'];

    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    public function enrich(Lead $lead): ?EnrichmentResult
    {
        if (! $lead->domain) {
            return null;
        }

        $emails = [];
        $phones = [];
        $visited = [];
        $paths = $this->config['paths'] ?? ['/'];

        foreach ($paths as $path) {
            $html = $this->fetch($lead->domain, $path);

            if ($html === null) {
                continue;
            }

            $visited[] = $path;
            $emails = array_merge($emails, $this->extractEmails($html, $lead->domain));
            $phones = array_merge($phones, $this->extractPhones($html));

            // Already has e-mail and phone: nothing left to find.
            if ($emails !== [] && $phones !== []) {
                break;
            }
        }

        if ($visited === []) {
            return new EnrichmentResult('site_scrape', payload: ['error' => 'site unreachable', 'domain' => $lead->domain]);
        }

        $emails = array_values(array_unique($emails));
        $phones = array_values(array_unique($phones));

        return new EnrichmentResult(
            driver: 'site_scrape',
            email: $emails[0] ?? null,
            phone: $this->normalizePhone($phones[0] ?? null, $lead->country),
            payload: ['emails' => $emails, 'phones' => $phones, 'visited' => $visited],
        );
    }

    private function fetch(string $domain, string $path): ?string
    {
        $timeout = (int) ($this->config['timeout'] ?? 5);

        foreach (['https', 'http'] as $scheme) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(3)
                    ->withUserAgent('Mozilla/5.0 (compatible; OmniLeadsBot/1.0)')
                    ->withOptions(['allow_redirects' => ['max' => 3]])
                    ->get("{$scheme}://{$domain}{$path}");
            } catch (ConnectionException) {
                continue;
            }

            if ($response->ok() && str_contains((string) $response->header('Content-Type'), 'html')) {
                return mb_substr($response->body(), 0, 500_000);
            }

            if ($response->status() === 404) {
                return null;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function extractEmails(string $html, string $domain): array
    {
        $found = [];

        preg_match_all('/mailto:([^"\'?\s>]+)/i', $html, $m);
        $found = array_merge($found, $m[1]);

        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', html_entity_decode($html), $m);
        $found = array_merge($found, $m[0]);

        $found = array_map(fn ($e) => strtolower(trim(urldecode($e))), $found);

        $found = array_filter($found, function (string $email) {
            $host = substr($email, strpos($email, '@') + 1);

            if (preg_match('/\.(png|jpe?g|gif|svg|webp|css|js)$/i', $email)) {
                return false;
            }
            if (str_starts_with($email, 'noreply') || str_starts_with($email, 'no-reply')) {
                return false;
            }

            foreach (self::IGNORED_EMAIL_HOSTS as $ignored) {
                if ($host === $ignored || str_ends_with($host, '.'.$ignored)) {
                    return false;
                }
            }

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        });

        $found = array_values(array_unique($found));

        // Prefer e-mails on the lead's own domain.
        usort($found, fn ($a, $b) => (int) str_ends_with($b, '@'.$domain) <=> (int) str_ends_with($a, '@'.$domain));

        return $found;
    }

    /** @return list<string> */
    private function extractPhones(string $html): array
    {
        $found = [];

        preg_match_all('/(?:tel|whatsapp):\+?([\d\s().-]{8,20})/i', $html, $m);
        $found = array_merge($found, $m[1]);

        preg_match_all('/wa\.me\/(\d{10,15})/i', $html, $m);
        $found = array_merge($found, $m[1]);

        $text = strip_tags($html);
        preg_match_all('/(?<!\d)(?:\+?55\s?)?\(?\d{2}\)?\s?9?\d{4}[\s-]?\d{4}(?!\d)/', $text, $m);
        $found = array_merge($found, $m[0]);

        $found = array_map(fn ($p) => preg_replace('/\D+/', '', $p) ?? '', $found);
        $found = array_filter($found, fn ($p) => strlen($p) >= 10 && strlen($p) <= 15);

        return array_values(array_unique($found));
    }

    private function normalizePhone(?string $digits, string $country): ?string
    {
        if ($digits === null) {
            return null;
        }

        if ($country === 'BR' && in_array(strlen($digits), [10, 11], true)) {
            return '55'.$digits;
        }

        return $digits;
    }
}
