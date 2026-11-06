<?php
declare(strict_types=1);

namespace Sahab;

/**
 * REST API modelled on Strapi's content API:
 *   GET /api/{type}?filters[field][$eq]=x&sort=field:desc&pagination[page]=1&pagination[pageSize]=25&status=draft
 *   GET /api/{type}/{id}     POST /api/{type}  {"data": {...}}     PUT /api/{type}/{id}     DELETE /api/{type}/{id}
 *   single types: GET|PUT|DELETE /api/{type}
 * Anonymous access is governed by the "Public" role permissions; Bearer tokens (Settings > API Tokens) get full access.
 */
final class Api
{
    private Content $content;
    private const OPS = ['$eq' => '', '$ne' => '__ne', '$lt' => '__lt', '$lte' => '__lte', '$gt' => '__gt', '$gte' => '__gte',
        '$contains' => '__like', '$in' => '__in', '$notIn' => '__notin', '$null' => '__null'];

    public function __construct(private Kernel $k)
    {
        $this->content = new Content($k);
    }

    public function register(Router $r): void
    {
        $r->add('OPTIONS', '/api/{rest}', fn() => $this->cors());
        $r->add('OPTIONS', '/api/{rest}/{id}', fn() => $this->cors());
        $r->get('/api/content-types', fn() => $this->guard(false, fn() => $this->schema()));
        $r->get('/api/{type}', fn(Request $q, array $p) => $this->guard(false, fn($tok) => $this->index($q, $p['type'], $tok)));
        $r->get('/api/{type}/{ref}', fn(Request $q, array $p) => $this->guard(false, fn($tok) => $this->show($q, $p['type'], $p['ref'], $tok)));
        $r->post('/api/{type}', fn(Request $q, array $p) => $this->guard(true, fn() => $this->write($q, $p['type'], null)));
        $r->add('PUT', '/api/{type}', fn(Request $q, array $p) => $this->guard(true, fn() => $this->write($q, $p['type'], null, true)));
        $r->add('DELETE', '/api/{type}', fn(Request $q, array $p) => $this->guard(true, fn() => $this->destroySingle($p['type'])));
        $r->add('PUT', '/api/{type}/{ref}', fn(Request $q, array $p) => $this->guard(true, fn() => $this->write($q, $p['type'], (int)$p['ref'])));
        $r->add('DELETE', '/api/{type}/{ref}', fn(Request $q, array $p) => $this->guard(true, fn() => $this->destroy($p['type'], (int)$p['ref'])));
    }

    private function cors(): never
    {
        http_response_code(204);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        exit;
    }

    private function out(array $data, int $code = 200): never
    {
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
        $this->k->json($data, $code);
    }

    private function err(string $name, string $msg, int $code, array $details = []): never
    {
        $this->out(['data' => null, 'error' => ['status' => $code, 'name' => $name, 'message' => $msg, 'details' => (object)$details]], $code);
    }

    private function guard(bool $needWrite, callable $fn): void
    {
        $r = $this->k->req;
        if (!Security::rateLimit('api:' . $r->ip(), 240, 60)) $this->err('TooManyRequestsError', 'Too many requests', 429);
        $tok = null;
        $h = $r->header('Authorization') ?: (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            $tok = $this->k->db->one('SELECT * FROM api_tokens WHERE token_hash = ?', [hash('sha256', $m[1])]);
            if (!$tok) $this->err('UnauthorizedError', 'Invalid token', 401);
            $this->k->db->exec('UPDATE api_tokens SET last_used = ? WHERE id = ?', [$this->k->db->now(), $tok['id']]);
        }
        if ($needWrite && (!$tok || !$tok['can_write'])) {
            $tok ? $this->err('ForbiddenError', 'This token is read-only', 403) : $this->err('UnauthorizedError', 'Missing or invalid credentials', 401);
        }
        $fn($tok);
    }

    private function typeOr404(string $slug): array
    {
        return $this->content->type($slug) ?? $this->err('NotFoundError', 'Not Found', 404);
    }

    private function allowRead(string $slug, ?array $tok): void
    {
        if (!$tok && !Auth::publicCan($slug, 'read')) $this->err('ForbiddenError', 'Forbidden', 403);
    }

    private function schema(): void
    {
        $this->out(['data' => array_values(array_map(fn($t) => [
            'uid' => $t['slug'], 'kind' => $t['kind'], 'singularName' => $t['name'], 'pluralName' => $t['plural'], 'attributes' => $t['fields'],
        ], $this->content->types()))]);
    }

    private function filters(array $f): array
    {
        $where = [];
        foreach ($f as $field => $cond) {
            if (!is_array($cond)) { $where[(string)$field] = $cond; continue; }
            foreach ($cond as $op => $val) {
                if (!isset(self::OPS[$op])) continue;
                $where[(string)$field . self::OPS[$op]] = $val;
            }
        }
        return $where;
    }

    private function shape(array $e, array $fields): array
    {
        if (!$fields) return $e;
        $keep = ['id' => 1, 'slug' => 1, 'status' => 1, 'url' => 1, 'type' => 1];
        foreach ($fields as $f) $keep[(string)$f] = 1;
        return array_intersect_key($e, $keep);
    }

    private function index(Request $q, string $slug, ?array $tok): void
    {
        $t = $this->typeOr404($slug);
        $this->allowRead($slug, $tok);
        $fields = (array)$q->get('fields', []);
        if ($t['kind'] === 'single') {
            $row = $this->content->singleRow($t);
            $e = $this->content->hydrate($t, [$row])[0];
            if ($e['status'] !== 'published' && !$tok) $this->err('NotFoundError', 'Not Found', 404);
            $this->out(['data' => $this->shape($e, $fields), 'meta' => (object)[]]);
        }
        $want = (string)$q->get('status', 'published');
        $status = $tok && in_array($want, ['draft', 'any'], true) ? $want : 'published';
        $pg = (array)$q->get('pagination', []);
        $size = max(1, min(100, (int)($pg['pageSize'] ?? 25)));
        $sort = $q->get('sort');
        if (is_array($sort)) $sort = reset($sort);
        $res = $this->content->list($t, [
            'status' => $status, 'q' => $q->get('q'), 'where' => $this->filters((array)$q->get('filters', [])),
            'order' => $sort ? str_replace(',', '', explode(',', (string)$sort)[0]) : null, 'limit' => $size, 'page' => (int)($pg['page'] ?? 1),
        ]);
        $this->out(['data' => array_map(fn($e) => $this->shape($e, $fields), $res['items']),
            'meta' => ['pagination' => ['page' => $res['page'], 'pageSize' => $size, 'pageCount' => $res['pages'], 'total' => $res['total']]]]);
    }

    private function show(Request $q, string $slug, string $ref, ?array $tok): void
    {
        $t = $this->typeOr404($slug);
        $this->allowRead($slug, $tok);
        $e = ctype_digit($ref) ? $this->content->find($t, (int)$ref) : $this->content->findBySlug($t, $ref, 'any');
        if (!$e || ($e['status'] !== 'published' && !$tok)) $this->err('NotFoundError', 'Not Found', 404);
        $this->out(['data' => $this->shape($e, (array)$q->get('fields', [])), 'meta' => (object)[]]);
    }

    private function write(Request $q, string $slug, ?int $id, bool $single = false): void
    {
        $t = $this->typeOr404($slug);
        $in = $q->json() ?: $q->post;
        if (isset($in['data']) && is_array($in['data'])) $in = $in['data'];
        if (!$in) $this->err('BadRequestError', 'Missing "data" payload', 400);
        $partial = $id !== null || $single;
        if ($t['kind'] === 'single') { $id = (int)$this->content->singleRow($t)['id']; $partial = true; }
        elseif ($single) $this->err('MethodNotAllowedError', 'Method Not Allowed', 405);
        $res = $this->content->saveEntry($t, $id, $in, 0, $partial);
        if ($res['errors']) $this->err('ValidationError', $res['errors'][0], 400, ['errors' => $res['errors']]);
        $this->out(['data' => $this->content->find($t, (int)$res['id']), 'meta' => (object)[]], $id && !$single && $t['kind'] !== 'single' ? 200 : ($id ? 200 : 201));
    }

    private function destroy(string $slug, int $id): void
    {
        $t = $this->typeOr404($slug);
        if (!$this->content->find($t, $id, false)) $this->err('NotFoundError', 'Not Found', 404);
        $this->content->deleteEntry($t, $id);
        $this->out(['data' => ['id' => $id], 'meta' => (object)[]]);
    }

    private function destroySingle(string $slug): void
    {
        $t = $this->typeOr404($slug);
        if ($t['kind'] !== 'single') $this->err('MethodNotAllowedError', 'Method Not Allowed', 405);
        $this->destroy($slug, (int)$this->content->singleRow($t)['id']);
    }
}
