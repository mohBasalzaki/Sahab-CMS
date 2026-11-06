<?php
declare(strict_types=1);

namespace Sahab;

/** Outgoing webhooks (Strapi-style): entry.create / update / delete / publish / unpublish, media.create. */
final class Webhooks
{
    public const EVENTS = ['entry.create', 'entry.update', 'entry.delete', 'entry.publish', 'entry.unpublish', 'media.create'];

    public static function register(Kernel $k): void
    {
        Hooks::on('entry.saved', function (string $type, int $id, bool $isNew, ?string $prev = null, ?string $now = null) {
            self::fire($isNew ? 'entry.create' : 'entry.update', ['model' => $type, 'id' => $id]);
            if ($now === 'published' && $prev !== 'published') self::fire('entry.publish', ['model' => $type, 'id' => $id]);
            if ($prev === 'published' && $now !== 'published') self::fire('entry.unpublish', ['model' => $type, 'id' => $id]);
        }, 50);
        Hooks::on('entry.deleted', fn(string $type, int $id) => self::fire('entry.delete', ['model' => $type, 'id' => $id]), 50);
    }

    public static function fire(string $event, array $data): void
    {
        $k = Kernel::$i;
        try {
            $rows = $k->db->all('SELECT * FROM webhooks WHERE enabled = 1');
        } catch (\Throwable) { return; }
        foreach ($rows as $w) {
            if (!in_array($event, array_filter(explode(',', (string)$w['events'])), true)) continue;
            if (!self::urlAllowed((string)$w['url'], $k)) continue;
            $body = json_encode(['event' => $event, 'createdAt' => date('c')] + $data, JSON_UNESCAPED_UNICODE);
            $headers = "Content-Type: application/json\r\nX-Sahab-Event: $event\r\nUser-Agent: SahabCMS/" . Kernel::VERSION . "\r\n";
            if ($w['secret'] !== '' && $w['secret'] !== null) $headers .= 'X-Sahab-Signature: ' . hash_hmac('sha256', $body, (string)$w['secret']) . "\r\n";
            @file_get_contents($w['url'], false, stream_context_create(['http' => [
                'method' => 'POST', 'header' => $headers, 'content' => $body, 'timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0,
            ]]));
        }
    }

    /** http(s) only; private/loopback targets are blocked unless config['allow_private_webhooks'] is true. */
    public static function urlAllowed(string $url, Kernel $k): bool
    {
        $p = parse_url($url);
        if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) return false;
        if (!empty($k->config['allow_private_webhooks'])) return true;
        $ip = filter_var($p['host'], FILTER_VALIDATE_IP) ? $p['host'] : gethostbyname($p['host']);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
