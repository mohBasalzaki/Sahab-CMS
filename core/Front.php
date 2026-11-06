<?php
declare(strict_types=1);

namespace Sahab;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Public site: routing + theme rendering. */
final class Front
{
    private Content $content;
    private ?Environment $twig = null;
    private bool $preview = false;

    public function __construct(private Kernel $k)
    {
        $this->content = new Content($k);
    }

    public function register(Router $r): void
    {
        $r->post('/forms/{slug}', fn(Request $q, array $p) => (new Forms($this->k))->submit($p['slug'], $q));
        $r->get('/', fn() => $this->home());
        $r->get('/search', fn(Request $q) => $this->search($q));
        $r->get('/{a}', fn(Request $q, array $p) => $this->one($q, $p['a']));
        $r->get('/{a}/{b}', fn(Request $q, array $p) => $this->two($q, $p['a'], $p['b']));
    }

    // ---------------------------------------------------------------- designs (visual designer, per entry)
    /** Entry design (published, or draft when previewing) falling back to the type template. */
    private function designFor(string $type, int $entryId): ?array
    {
        $t = $this->content->type($type);
        if (!$t || empty($t['designer'])) return null;
        $st = $this->preview ? '' : "status = 'published' AND ";
        try {
            $d = $this->k->db->one("SELECT * FROM designs WHERE {$st}kind = 'entry' AND type = ? AND entry_id = ?", [$type, $entryId]);
            if ($d && ($this->preview || $d['live_json'] !== null)) return $d + ['_draft' => $this->preview];
            $d = $this->k->db->one("SELECT * FROM designs WHERE status = 'published' AND kind = 'template' AND type = ?", [$type]);
            return $d ? $d + ['_draft' => false] : null;
        } catch (\Throwable) { return null; }
    }

    private function renderDesign(array $d, array $ctx = []): void
    {
        $dz = (new Design($this->k, $this->content))->withTwig(fn(string $code, array $c) => $this->twig()->createTemplate($code)->render($c));
        $r = $dz->render(Design::normalize(!empty($d['_draft']) ? $d['tree_json'] : $d['live_json']), $ctx);
        $meta = json_decode((string)($d['meta_json'] ?? ''), true) ?: [];
        $title = trim((string)($meta['title'] ?? '')) ?: ($ctx['entry']['title'] ?? $d['title']);
        $desc = (string)($meta['description'] ?? $this->k->setting('site_description'));
        $loader = $this->twig()->getLoader();
        if ($d['layout'] === 'theme' && $loader->exists('design.twig')) {
            $this->render(['design'], [
                'title' => $title, 'description' => $desc,
                'design' => ['html' => new Markup($r['html'], 'UTF-8'), 'css' => new Markup('<style>' . $r['css'] . '</style>', 'UTF-8')],
            ]);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="' . $e(I18n::$lang) . '" dir="' . I18n::dir() . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . $e($title) . '</title><meta name="description" content="' . $e($desc) . '"><style>body{margin:0;font-family:"IBM Plex Sans","IBM Plex Sans Arabic","Segoe UI",Tahoma,sans-serif}' . $r['css'] . '</style>'
            . (string)Hooks::filter('front.head', '') . '</head><body>' . $r['html'] . (string)Hooks::filter('front.footer', '') . '</body></html>';
    }

    /** Render one entry: its own/type design when the type has the designer on, otherwise the theme templates. */
    private function showEntry(array $t, array $raw): void
    {
        if ($d = $this->designFor($t['slug'], (int)$raw['id'])) {
            $this->renderDesign($d, ['entry' => $this->content->hydrate($t, [$raw])[0], 'type' => $t['slug']]);
            return;
        }
        $e = $this->expand($this->content->hydrate($t, [$raw], true)[0]);
        $cand = ["single-{$t['slug']}"];
        if ($t['slug'] === 'page') $cand[] = 'page';
        $cand[] = 'single';
        $this->render($cand, ['entry' => $e, 'type' => $t, 'title' => $e['title']]);
    }

    private function home(): void
    {
        $slug = (string)$this->k->setting('home_page', '');
        if ($slug !== '' && ($pt = $this->content->type('page')) && ($row = $this->content->findBySlug($pt, $slug, 'published', false))) {
            $this->showEntry($pt, $this->content->find($pt, (int)$row['id'], false));
            return;
        }
        $this->render(['home', 'index'], ['title' => $this->k->setting('site_title')]);
    }

    private function one(Request $q, string $a): void
    {
        if (preg_match('/^[\p{L}\p{N}_-]+$/u', $a) && $this->twig()->getLoader()->exists("static-$a.twig")) {   // pages from an imported site
            $this->render(["static-$a"], ['title' => $this->k->setting('site_title')]);
            return;
        }
        if (($t = $this->content->type($a)) && $t['kind'] === 'single') { $this->single($q, $t); return; }
        if ($this->content->type($a)) { $this->archive($q, $a); return; }
        $page = $this->content->type('page');
        if (!$page) { $this->notFound(); return; }
        if ($this->lookup($q, $page, $a, $row)) $this->showEntry($page, $row);
    }

    private function two(Request $q, string $a, string $b): void
    {
        $t = $this->content->type($a);
        if (!$t) { $this->notFound(); return; }
        if ($a === 'page') { $this->k->redirect('/' . rawurlencode($b), 301); }
        if ($this->lookup($q, $t, $b, $row)) $this->showEntry($t, $row);
    }

    private function single(Request $q, array $t): void
    {
        $row = $this->content->singleRow($t);
        $this->preview = $q->get('preview') !== null && Auth::can('view');
        if ($row['status'] !== 'published' && !$this->preview) { $this->notFound(); return; }
        $this->showEntry($t, $row);
    }

    /** Find a raw entry row by slug (published only, or any when an editor previews). */
    private function lookup(Request $q, array $type, string $slug, ?array &$row): bool
    {
        $this->preview = $q->get('preview') !== null && Auth::can('view');
        $hit = $this->content->findBySlug($type, $slug, $this->preview ? 'any' : 'published', false);
        if (!$hit) { $this->notFound(); return false; }
        if ($this->preview) header('Cache-Control: no-store');
        $row = $this->content->find($type, (int)$hit['id'], false);
        return true;
    }
    private function archive(Request $q, string $slug): void
    {
        $t = $this->content->type($slug);
        $per = max(1, (int)$this->k->setting('posts_per_page', 10));
        $res = $this->content->list($t, ['limit' => $per, 'page' => (int)$q->get('page', 1), 'markup' => true]);
        $this->render(["archive-$slug", 'archive'], [
            'type' => $t, 'items' => array_map([$this, 'expand'], $res['items']),
            'pager' => ['page' => $res['page'], 'pages' => $res['pages'], 'total' => $res['total']],
            'base_url' => $this->k->url('/' . $slug), 'title' => $t['plural'],
        ]);
    }

    private function search(Request $q): void
    {
        $term = mb_substr($q->str('q'), 0, 100);
        $items = [];
        if ($term !== '') {
            foreach ($this->content->types() as $t) {
                $res = $this->content->list($t, ['q' => $term, 'limit' => 20, 'markup' => true]);
                foreach ($res['items'] as $it) $items[] = $it;
            }
        }
        $this->twig();   // loads the site language first
        $this->render(['search', 'archive'], [
            'items' => $items, 'query' => $term, 'type' => ['slug' => 'search', 'name' => I18n::t('بحث'), 'plural' => I18n::t('نتائج البحث')],
            'pager' => ['page' => 1, 'pages' => 1, 'total' => count($items)], 'base_url' => $this->k->url('/search'), 'title' => I18n::t('بحث: ') . $term,
        ]);
    }

    public function notFound(): void
    {
        http_response_code(404);
        try {
            $this->render(['404'], ['title' => I18n::t('غير موجود')], 404);
        } catch (\Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>404</title><h1 style="text-align:center;font-family:sans-serif;margin-top:4rem">404 — الصفحة غير موجودة</h1>';
        }
    }

    /** Replace {{form:slug}} shortcodes inside rich-text fields. */
    private function expand(array $e): array
    {
        foreach ($e as $key => $v) {
            if ($v instanceof Markup && str_contains((string)$v, '{{form:')) {
                $html = preg_replace_callback('#(?:<p>\s*)?\{\{form:([a-z0-9_\-]+)\}\}(?:\s*</p>)?#', function ($m) {
                    $f = (new Forms($this->k))->get($m[1]);
                    return $f ? '<div id="form-' . $m[1] . '">' . (new Forms($this->k))->render($f) . '</div>' : '';
                }, (string)$v);
                $e[$key] = new Markup($html, 'UTF-8');
            }
        }
        return $e;
    }

    // ---------------------------------------------------------------- render
    private function render(array $candidates, array $ctx, int $status = 200): void
    {
        $twig = $this->twig();
        $loader = $twig->getLoader();
        $tpl = null;
        foreach ($candidates as $c) if ($loader->exists($c . '.twig')) { $tpl = $c . '.twig'; break; }
        if (!$tpl) {
            if ($status === 404) { echo '<!doctype html><meta charset="utf-8"><title>404</title><h1>404</h1>'; return; }
            throw new \RuntimeException('Template not found: ' . implode(', ', $candidates));
        }
        $ctx += ['title' => (string)$this->k->setting('site_title')];
        header('Content-Type: text/html; charset=utf-8');
        echo $twig->render($tpl, Hooks::filter('front.context', $ctx, $tpl));
    }

    public function twig(): Environment
    {
        if ($this->twig) return $this->twig;
        $k = $this->k;
        if (I18n::siteLang() !== I18n::$lang) I18n::load(I18n::siteLang());   // public pages follow the site language, not the panel's
        $chain = Themes::chain($k);
        $paths = [];
        foreach ($chain as $id) {
            $p = Themes::dir($k) . "/$id/templates";
            if (is_dir($p)) $paths[] = $p;
        }
        $loader = new FilesystemLoader($paths);
        $cache = $k->root . '/storage/cache/twig';
        $env = new Environment($loader, [
            'cache' => is_dir(dirname($cache)) && is_writable(dirname($cache)) ? $cache : false,
            'auto_reload' => true, 'autoescape' => 'html', 'strict_variables' => false,
        ]);

        $tags = ['if', 'for', 'set', 'extends', 'block', 'include', 'embed', 'macro', 'import', 'from', 'apply', 'with', 'autoescape'];
        $filters = ['abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default', 'escape', 'e', 'first', 'format', 'join', 'json_encode',
            'keys', 'last', 'length', 'lower', 'merge', 'nl2br', 'number_format', 'raw', 'replace', 'reverse', 'round', 'slice', 'sort', 'split',
            'striptags', 'title', 'trim', 'upper', 'url_encode', 'excerpt', 'ar_digits', 'ar_date', 'hijri', 'slug', 'safe_url'];
        $functions = ['range', 'cycle', 'date', 'max', 'min', 'random', 'include', 'block', 'parent',
            'entries', 'query', 'entry', 'media', 'form', 'form_data', 'setting', 'menu', 't', 'asset', 'url', 'csrf_token', 'hook', 'types'];
        $filters = Hooks::filter('sandbox.filters', $filters);
        $functions = Hooks::filter('sandbox.functions', $functions);
        $env->addExtension(new SandboxExtension(new SecurityPolicy($tags, $filters, [], [], $functions), true));

        $content = $this->content;
        $media = new Media($k);
        $forms = new Forms($k);
        $safe = ['is_safe' => ['html']];

        $env->addFunction(new TwigFunction('entries', fn(string $type, array $o = []) => ($t = $content->type($type)) ? array_map([$this, 'expand'], $content->list($t, $o + ['markup' => true])['items']) : []));
        $env->addFunction(new TwigFunction('query', fn(string $type, array $o = []) => ($t = $content->type($type)) ? $content->list($t, $o + ['markup' => true]) : ['items' => [], 'total' => 0, 'pages' => 1, 'page' => 1]));
        $env->addFunction(new TwigFunction('entry', function (string $type, $idOrSlug) use ($content) {
            $t = $content->type($type);
            if (!$t) return null;
            $e = ctype_digit((string)$idOrSlug) ? $content->find($t, (int)$idOrSlug, true, true) : $content->findBySlug($t, (string)$idOrSlug, 'published', true);
            return $e && $e['status'] === 'published' ? $this->expand($e) : null;
        }));
        $env->addFunction(new TwigFunction('media', fn($id) => $media->find((int)$id)));
        $env->addFunction(new TwigFunction('form', fn(string $slug) => ($f = $forms->get($slug)) ? $forms->render($f) : '', $safe));
        $env->addFunction(new TwigFunction('form_data', fn(string $slug) => ($f = $forms->get($slug)) ? $forms->describe($f) : null));
        $env->addFunction(new TwigFunction('types', fn() => array_values(array_map(fn($t) => ['slug' => $t['slug'], 'name' => $t['name'], 'plural' => $t['plural'], 'kind' => $t['kind'], 'url' => $k->url('/' . $t['slug'])], $content->types()))));
        $env->addFunction(new TwigFunction('setting', fn(string $key) => in_array($key, ['site_title', 'site_description', 'lang'], true) ? $k->setting($key) : (str_starts_with($key, 'theme_') ? $k->setting($key) : null)));
        $env->addFunction(new TwigFunction('menu', fn(string $name) => $this->menu($name)));
        $env->addFunction(new TwigFunction('t', fn(string $s, array $v = []) => I18n::t($s, $v)));
        $env->addFunction(new TwigFunction('url', fn(string $p = '') => $k->url($p)));
        $env->addFunction(new TwigFunction('csrf_token', fn() => Security::csrfToken()));
        $env->addFunction(new TwigFunction('asset', function (string $path) use ($k, $chain) {
            $path = ltrim($path, '/');
            foreach ($chain as $id) if (is_file(Themes::dir($k) . "/$id/assets/$path")) return $k->url("/themes/$id/assets/$path");
            return $k->url("/themes/{$chain[0]}/assets/$path");
        }));
        $env->addFunction(new TwigFunction('hook', fn(string $n) => (string)Hooks::filter('front.' . $n, ''), $safe));

        $env->addFilter(new TwigFilter('excerpt', fn($s, int $n = 160) => Str::excerpt((string)$s, $n)));
        $env->addFilter(new TwigFilter('ar_digits', fn($s) => Str::arDigits((string)$s)));
        $env->addFilter(new TwigFilter('ar_date', fn($s) => $s ? Str::arDate($s) : ''));
        $env->addFilter(new TwigFilter('hijri', fn($s) => $s ? Str::hijri(is_int($s) ? $s : (strtotime((string)$s) ?: time())) : ''));
        $env->addFilter(new TwigFilter('slug', fn($s) => Str::slug((string)$s)));
        $env->addFilter(new TwigFilter('safe_url', function ($u) { $u = trim(preg_replace('/[\x00-\x20]+/', '', (string)$u)); return ($u === '' || preg_match('#^(https?://|mailto:|tel:|/|\#|[^:]*$)#i', $u)) ? $u : '#'; }));

        $info = Themes::all($k)[$chain[0]] ?? [];
        $env->addGlobal('site', [
            'title' => (string)$k->setting('site_title'), 'description' => (string)$k->setting('site_description'),
            'lang' => I18n::$lang, 'dir' => I18n::dir(), 'url' => $k->url('/'), 'path' => $k->req->path,
            'theme' => $info, 'user' => Auth::user() ? ['name' => Auth::user()['name']] : null,
            'admin_url' => $k->url('/admin'),
        ]);
        Hooks::emit('twig.front', $env);
        return $this->twig = $env;
    }

    private function menu(string $name): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) return [];
        $items = [];
        $path = $this->k->req->path;
        foreach (preg_split('/\R/u', (string)$this->k->setting('menu_' . $name, '')) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '/');
            if (preg_match('#^\s*(javascript|data|vbscript):#i', $url)) continue;
            $href = $this->k->url($url);
            $items[] = ['label' => $label, 'url' => $href, 'active' => $url === $path || ($url !== '/' && str_starts_with($path, $url))];
        }
        return $items;
    }
}
