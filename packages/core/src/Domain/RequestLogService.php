<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;

/**
 * Anfragen-Protokoll: Welche PHP-Anfragen kommen beim Webspace an (Art, Adresse, Dauer, User-Agent)? Für Admins, die
 * mit dem Hoster über Antwortzeiten sprechen: Crawler, Cronjobs und Dienste führen kein JavaScript aus, eine Auswertung
 * im Browser sähe sie nicht.
 *
 * - Vorgabe aus. Eingeschaltet läuft es ON_DAYS Tage und schaltet sich dann selbst ab.
 * - Der Adapter ruft record() einmal je Anfrage auf (WordPress: Action shutdown). Ist es aus, kostet das nur das Lesen
 *   einer Einstellung; an schreibt es eine Zeile (JSON) in eine Datei je Tag. Keine Datenbank: kein Schreiben in die
 *   Tabellen bei jedem Seitenaufruf.
 * - Gespeichert: Uhrzeit, Art, Methode, Adresse ohne Werte der Parameter, Dauer, Statuscode, angemeldet ja/nein,
 *   User-Agent und die gekürzte IP-Adresse (IPv4 ohne letzten Teil, IPv6 nur die ersten 48 Bit): Netze von Crawlern
 *   und Diensten lassen sich erkennen, einzelne Besucher nicht. Keine Konten, keine Inhalte, keine Cookies.
 * - Die Dateien liegen in der geschützten Ablage (wie interne Bilder) unter einem zufälligen Namen und werden nach
 *   KEEP_DAYS Tagen gelöscht; ausschalten lässt sie stehen, „Löschen“ entfernt sie sofort.
 */
final class RequestLogService
{
    /** Einstellung: ['until' => Unix-Zeit, bis zu der aufgezeichnet wird (0: aus), 'secret' => Teil der Dateinamen] */
    public const SETTING = 'request_log';
    public const ON_DAYS = 7;
    public const KEEP_DAYS = 14;
    /** Größte Datei je Tag; darüber wird an diesem Tag nichts mehr aufgezeichnet (Schutz vor vollem Webspace). */
    public const MAX_BYTES_PER_DAY = 20_000_000;
    /** Längster gespeicherter User-Agent bzw. längste Adresse */
    private const MAX_FIELD = 300;
    /** Höchstens so viele Einträge in den Listen der Übersicht */
    private const TOP = 15;
    /** Arten von Anfragen (der Adapter ordnet zu) */
    public const KINDS = ['app-api', 'app', 'cron', 'admin', 'login', 'rest', 'page', 'other'];
    private const DIR = 'anfragen';

    /** Bekannte Crawler und Dienste: Teil des User-Agents → Name in der Übersicht. Reihenfolge zählt (erster Treffer). */
    private const AGENTS = [
        'Googlebot' => 'Googlebot',
        'Google-InspectionTool' => 'Google',
        'AdsBot-Google' => 'Google',
        'GoogleOther' => 'Google',
        'bingbot' => 'Bingbot',
        'YandexBot' => 'Yandex',
        'Baiduspider' => 'Baidu',
        'DuckDuckBot' => 'DuckDuckGo',
        'Applebot' => 'Applebot',
        'AhrefsBot' => 'Ahrefs',
        'SemrushBot' => 'Semrush',
        'MJ12bot' => 'Majestic',
        'DotBot' => 'Moz',
        'PetalBot' => 'Petal',
        'SeznamBot' => 'Seznam',
        'GPTBot' => 'OpenAI',
        'OAI-SearchBot' => 'OpenAI',
        'ChatGPT-User' => 'OpenAI',
        'ClaudeBot' => 'Anthropic',
        'Claude-User' => 'Anthropic',
        'PerplexityBot' => 'Perplexity',
        'Amazonbot' => 'Amazon',
        'Bytespider' => 'ByteDance',
        'CCBot' => 'Common Crawl',
        'meta-externalagent' => 'Meta',
        'facebookexternalhit' => 'Facebook',
        'Twitterbot' => 'X',
        'WhatsApp' => 'WhatsApp',
        'TelegramBot' => 'Telegram',
        'UptimeRobot' => 'UptimeRobot',
        'Jetpack' => 'Jetpack',
        'WordPress/' => 'WordPress (Server selbst)',
        'Wget' => 'Wget',
        'curl/' => 'curl',
        'python' => 'Python',
        'Go-http-client' => 'Go',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function active(): bool
    {
        return $this->until() > $this->app->time()->timestamp();
    }

    /** @return array{on: bool, until: int|null, on_days: int, keep_days: int} */
    public function state(): array
    {
        $on = $this->active();
        return ['on' => $on, 'until' => $on ? $this->until() : null, 'on_days' => self::ON_DAYS, 'keep_days' => self::KEEP_DAYS];
    }

    /** Ein- (für ON_DAYS Tage, ab jetzt) oder ausschalten. Die Dateien bleiben bis zur Frist bzw. bis „Löschen“. */
    public function set(bool $on): array
    {
        $s = $this->stored();
        $s['until'] = $on ? $this->app->time()->timestamp() + self::ON_DAYS * 86400 : 0;
        if ($s['secret'] === '') {
            $s['secret'] = bin2hex(random_bytes(12));
        }
        $this->app->settings->set(self::SETTING, $s);
        return $this->state();
    }

    /** Alle Dateien sofort löschen. */
    public function clear(): void
    {
        foreach ($this->files() as $file) {
            @unlink($file);
        }
    }

    /**
     * Eine Anfrage aufzeichnen (nur, wenn an). Fehler bleiben still: Das Protokoll darf keine Anfrage stören.
     *
     * @param array{at: float, kind: string, method: string, uri: string, ms: int, status: int, auth: bool, agent: string, ip: string} $r
     */
    public function record(array $r): void
    {
        if (!$this->active()) {
            return;
        }
        try {
            $file = $this->file(gmdate('Y-m-d', (int) $r['at']));
            if (is_file($file) && (int) @filesize($file) >= self::MAX_BYTES_PER_DAY) {
                return;
            }
            $line = json_encode([
                't' => round($r['at'], 3),
                'k' => in_array($r['kind'], self::KINDS, true) ? $r['kind'] : 'other',
                'm' => substr(strtoupper($r['method']), 0, 10),
                'p' => self::path($r['uri']),
                'ms' => max(0, $r['ms']),
                's' => $r['status'],
                'a' => $r['auth'] ? 1 : 0,
                'u' => mb_substr($r['agent'], 0, self::MAX_FIELD),
                'ip' => self::shortenIp($r['ip']),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line !== false) {
                @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {
            // nicht aufzeichnen
        }
    }

    /** Dateien nach KEEP_DAYS löschen (tägliche Aufgabe der Nutzungsauswertung, ActivityService::TASK). */
    public function prune(): void
    {
        $oldest = gmdate('Y-m-d', $this->app->time()->timestamp() - self::KEEP_DAYS * 86400);
        foreach ($this->files() as $day => $file) {
            if ($day < $oldest) {
                @unlink($file);
            }
        }
    }

    /**
     * Übersicht über alle gespeicherten Tage.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $total = 0;
        $kinds = array_fill_keys(self::KINDS, ['count' => 0, 'ms' => 0]);
        $days = [];
        $hours = array_fill(0, 24, 0);
        $paths = [];
        $agents = [];
        $cron = [];
        $tz = $this->app->time()->timezone();
        $first = null;
        $last = null;
        foreach ($this->files() as $day => $file) {
            $fp = @fopen($file, 'r');
            if ($fp === false) {
                continue;
            }
            while (($line = fgets($fp)) !== false) {
                $e = json_decode($line, true);
                if (!is_array($e) || !is_numeric($e['t'] ?? null)) {
                    continue;
                }
                $t = (float) $e['t'];
                $kind = in_array($e['k'] ?? '', self::KINDS, true) ? (string) $e['k'] : 'other';
                $ms = (int) ($e['ms'] ?? 0);
                $agent = self::agentName((string) ($e['u'] ?? ''));
                $total++;
                $first = $first === null ? $t : min($first, $t);
                $last = $last === null ? $t : max($last, $t);
                $kinds[$kind]['count']++;
                $kinds[$kind]['ms'] += $ms;
                $local = (new \DateTimeImmutable('@' . (int) $t))->setTimezone($tz);
                $d = $local->format('Y-m-d');
                $days[$d] ??= array_fill_keys(self::KINDS, 0);
                $days[$d][$kind]++;
                $hours[(int) $local->format('G')]++;
                $key = ($e['m'] ?? 'GET') . ' ' . ($e['p'] ?? '');
                $paths[$key] ??= ['kind' => $kind, 'count' => 0, 'ms' => 0, 'max' => 0, 'bots' => 0];
                $paths[$key]['count']++;
                $paths[$key]['ms'] += $ms;
                $paths[$key]['max'] = max($paths[$key]['max'], $ms);
                $paths[$key]['bots'] += self::isBrowser($agent) ? 0 : 1;
                $agents[$agent] ??= ['count' => 0, 'ms' => 0];
                $agents[$agent]['count']++;
                $agents[$agent]['ms'] += $ms;
                if ($kind === 'cron') {
                    $from = $agent . "\n" . (string) ($e['ip'] ?? '');
                    $cron[$from] = ($cron[$from] ?? 0) + 1;
                }
            }
            fclose($fp);
        }
        ksort($days);
        $list = static function (array $rows, callable $order): array {
            uasort($rows, $order);
            return array_slice($rows, 0, self::TOP, true);
        };
        $withAvg = static fn (array $r): array => $r + ['avg' => $r['count'] > 0 ? (int) round($r['ms'] / $r['count']) : 0];
        $top = $list($paths, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        // Langsamste: nur Adressen mit mindestens drei Aufrufen, sonst zählt ein einzelner Ausreißer
        $slow = $list(array_filter($paths, static fn (array $p): bool => $p['count'] >= 3), static fn (array $a, array $b): int => $b['ms'] / $b['count'] <=> $a['ms'] / $a['count']);
        $named = static fn (array $rows): array => array_map(static fn (string $k, array $r): array => ['name' => $k] + $withAvg($r), array_keys($rows), array_values($rows));
        arsort($cron);
        return $this->state() + [
            'total' => $total,
            'from' => $first !== null ? (int) $first : null,
            'to' => $last !== null ? (int) $last : null,
            'kinds' => array_map($withAvg, $kinds),
            'days' => array_map(static fn (string $d, array $k): array => ['date' => $d] + $k, array_keys($days), array_values($days)),
            'hours' => $hours,
            'paths' => $named($top),
            'slowest' => $named($slow),
            'agents' => $named($list($agents, static fn (array $a, array $b): int => $b['count'] <=> $a['count'])),
            // Woher die Aufrufe von wp-cron.php kommen: der Server selbst (WP-Cron), ein Cronjob des Hosters oder ein Dienst
            'cron' => array_map(static function (string $k, int $n): array {
                [$agent, $ip] = explode("\n", $k, 2);
                return ['agent' => $agent, 'ip' => $ip, 'count' => $n];
            }, array_map('strval', array_keys(array_slice($cron, 0, self::TOP, true))), array_values(array_slice($cron, 0, self::TOP, true))),
        ];
    }

    /**
     * Adresse ohne Werte der Parameter und mit Platzhaltern für Zahlen und lange Kennungen: /app/foto/12/thumb?v=ab →
     * /app/foto/{n}/thumb?v. So zählen gleiche Aufrufe zusammen, und Suchbegriffe, Schlüssel oder Namen in Parametern
     * landen nicht im Protokoll.
     */
    public static function path(string $uri): string
    {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        $path = (string) preg_replace(['#/\d+(?=/|$)#', '#/[0-9a-f]{16,}(?=/|$)#i'], ['/{n}', '/{id}'], $path);
        $keys = [];
        foreach (explode('&', $query) as $pair) {
            $k = rawurldecode(explode('=', $pair, 2)[0]);
            if ($k !== '') {
                // rest_route trägt die Adresse der REST-API: die behalten (ohne Zahlen), sonst nur den Namen
                $keys[] = $k === 'rest_route' && str_contains($pair, '=')
                    ? 'rest_route=' . preg_replace('#/\d+(?=/|$)#', '/{n}', rawurldecode(explode('=', $pair, 2)[1]))
                    : $k;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return mb_substr($path . ($keys !== [] ? '?' . implode('&', $keys) : ''), 0, self::MAX_FIELD);
    }

    /** IPv4 ohne letzten Teil (203.0.113.x), IPv6 nur die ersten 48 Bit (2001:db8:1::x); sonst leer. */
    public static function shortenIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.x', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return '';
            }
            $full = str_split(bin2hex($bin), 4);
            return implode(':', array_map(static fn (string $g): string => ltrim($g, '0') ?: '0', array_slice($full, 0, 3))) . '::x';
        }
        return '';
    }

    /** Name eines bekannten Crawlers oder Dienstes, sonst „Browser“ bzw. „Unbekannt“. */
    public static function agentName(string $agent): string
    {
        foreach (self::AGENTS as $needle => $name) {
            if (stripos($agent, $needle) !== false) {
                return $name;
            }
        }
        if ($agent === '') {
            return 'Ohne User-Agent';
        }
        if (preg_match('/bot|crawl|spider|slurp|fetch|scan|monitor|http-client|headless/i', $agent)) {
            return 'Anderer Crawler';
        }
        return str_contains($agent, 'Mozilla/') ? 'Browser' : 'Unbekannt';
    }

    private static function isBrowser(string $name): bool
    {
        return $name === 'Browser';
    }

    private function until(): int
    {
        return $this->stored()['until'];
    }

    /** @return array{until: int, secret: string} */
    private function stored(): array
    {
        $s = $this->app->settings->get(self::SETTING);
        $s = is_array($s) ? $s : [];
        return ['until' => (int) ($s['until'] ?? 0), 'secret' => is_string($s['secret'] ?? null) ? $s['secret'] : ''];
    }

    private function file(string $day): string
    {
        return $this->app->files->path(self::DIR . '/' . $this->stored()['secret'] . '-' . $day . '.log', false);
    }

    /** @return array<string, string> Tag (JJJJ-MM-TT, UTC) → Datei, aufsteigend */
    private function files(): array
    {
        $secret = $this->stored()['secret'];
        if ($secret === '') {
            return [];
        }
        $dir = dirname($this->app->files->path(self::DIR . '/x', false));
        $out = [];
        foreach (glob($dir . '/' . $secret . '-*.log') ?: [] as $file) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $out[$m[1]] = $file;
            }
        }
        ksort($out);
        return $out;
    }
}
