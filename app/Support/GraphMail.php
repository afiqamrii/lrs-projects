<?php

namespace App\Support;

use App\Models\MailboxConnection;
use Illuminate\Support\Facades\Http;

class GraphMail
{
    public function call(MailboxConnection $connection, string $method, string $path, array $data = [], ?string $token = null, bool $text = false): array|string
    {
        if ($token === null) {
            $latest = $connection->fresh();
            if (! $latest->usable() || $latest->identity_hash !== $connection->identity_hash || $latest->generation !== $connection->generation) {
                throw new GraphFailure(401);
            }
        }
        if ($connection->is_demo) {
            return app(MailboxFixture::class)->call($connection, $method, $path, $data);
        }
        if ($token === null && ! $connection->fresh()->usable()) {
            throw new GraphFailure(401);
        }
        $url = str_starts_with($path, 'https://') ? $path : 'https://graph.microsoft.com/v1.0'.$path;
        self::graphUrl($url);
        $token ??= app(Mailboxes::class)->token($connection);
        try {
            $http = Http::withToken($token)->withHeaders(['Prefer' => 'IdType="ImmutableId"'.($text ? ', outlook.body-content-type="text"' : '')])->timeout(25)->connectTimeout(10)->withoutRedirecting();
            $send = $method === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH), '/send');
            $response = ($send ? $http->withHeaders(['Content-Length' => '0'])->withBody('', 'application/octet-stream') : $http)->send($method, $url, $method === 'GET' || $send ? [] : ['json' => $data]);
        } catch (\Throwable $e) {
            throw new GraphFailure(0, 30, true);
        }
        if (! $response->successful()) {
            $retry = $response->header('Retry-After');
            $seconds = is_numeric($retry) ? (int) $retry : ($retry ? max(1, (int) (strtotime($retry) - time())) : 30);
            throw new GraphFailure($response->status(), max(1, $seconds), $response->serverError());
        }
        if (strlen($response->body()) > 48 * 1024 * 1024) {
            throw new GraphFailure(413);
        }
        if (str_contains($path, '/$value')) {
            return $response->body();
        }
        if ($response->status() === 202) {
            return ['status' => 202];
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public static function graphUrl(string $url): void
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== 'graph.microsoft.com' || (isset($p['user']) || isset($p['pass'])) || isset($p['port']) || isset($p['fragment']) || ! str_starts_with($p['path'] ?? '', '/v1.0/')) {
            throw new GraphFailure(400);
        }
    }

    public static function deltaUrl(string $url, string $mailbox, string $folder): void
    {
        self::graphUrl($url);
        $path = rawurldecode(parse_url($url, PHP_URL_PATH));
        $path = preg_replace("/(users|mailFolders)\\('([^']+)'\\)/i", '$1/$2', $path);
        $expected = '/v1.0/users/'.$mailbox.'/mailFolders/'.$folder.'/messages/delta';
        if ($path !== $expected) {
            throw new GraphFailure(400);
        }
    }

    public function list(MailboxConnection $connection, string $path, int $limit = 100): array
    {
        $items = [];
        $expected = self::canonicalPath(parse_url(str_starts_with($path, 'https://') ? $path : 'https://graph.microsoft.com/v1.0'.$path, PHP_URL_PATH));
        do {
            $page = $this->call($connection, 'GET', $path);
            $items = array_merge($items, $page['value'] ?? []);
            if (count($items) > $limit) {
                throw new GraphFailure(413);
            }
            $path = $page['@odata.nextLink'] ?? null;
            if ($path) {
                self::graphUrl($path);
                if (self::canonicalPath(parse_url($path, PHP_URL_PATH)) !== $expected) {
                    throw new GraphFailure(400);
                }
            }
        } while ($path);

        return $items;
    }

    private static function canonicalPath(string $path): string
    {
        return preg_replace("/(users|mailFolders|messages)\\('([^']+)'\\)/i", '$1/$2', rawurldecode($path));
    }

    public static function messages(string $mailbox): string
    {
        return '/users/'.rawurlencode($mailbox).'/messages';
    }

    public function correlated(MailboxConnection $connection, string $mailbox, string $key): array
    {
        $property = config('mailbox.extended_property');

        return $this->list($connection, self::messages($mailbox).'?'.http_build_query(['$filter' => "singleValueExtendedProperties/Any(ep: ep/id eq '".$property."' and ep/value eq '".$key."')", '$expand' => "singleValueExtendedProperties(\$filter=id eq '".$property."')", '$select' => 'id,isDraft,internetMessageId,from,sender,subject']), 10);
    }

    public function upload(MailboxConnection $connection, string $url, string $method, ?string $bytes = null, ?string $range = null): array
    {
        $latest = $connection->fresh();
        if (! $latest->usable() || $latest->identity_hash !== $connection->identity_hash) {
            throw new GraphFailure(401);
        }
        if ($connection->is_demo) {
            return app(MailboxFixture::class)->upload($url, $method, $bytes, $range);
        }
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== 'outlook.office.com' || (isset($p['user']) || isset($p['pass'])) || isset($p['port']) || isset($p['fragment']) || ! str_starts_with($p['path'] ?? '', '/api/v2.0/') || ! str_contains($p['path'], '/AttachmentSessions(')) {
            throw new GraphFailure(400);
        }
        try {
            $http = Http::timeout(25)->connectTimeout(10)->withoutRedirecting();
            if ($bytes !== null) {
                $http = $http->withHeaders(['Content-Range' => $range, 'Content-Length' => (string) strlen($bytes)])->withBody($bytes, 'application/octet-stream');
            }
            $response = $http->send($method, $url);
        } catch (\Throwable $e) {
            throw new GraphFailure(0, 30, true);
        }
        if (! $response->successful()) {
            $retry = $response->header('Retry-After');
            throw new GraphFailure($response->status(), is_numeric($retry) ? max(1, (int) $retry) : 30, $response->serverError());
        }

        return ['status' => $response->status()] + ($response->json() ?? []);
    }
}
