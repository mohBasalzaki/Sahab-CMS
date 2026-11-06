<?php
declare(strict_types=1);

namespace Sahab\Admin;

use Sahab\{Auth, Content, Hooks, I18n, Kernel, Media, Request, Router, Security, Str};
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Admin shell: routing guard, Twig environment, auth screens, dashboard, content + type management. */
final class Routes
{
    public Content $content;
    private ?Environment $twig = null;

    public function __construct(public Kernel $k)
    {
        $this->content = new Content($k);
    }

    public function register(Router $r): void
    {
        $m = new Manage($this->k, $this);
        $k = $this->k;

        $this->route($r, 'GET', '/admin/login', null, fn() => $this->loginForm());
        $this->route($r, 'POST', '/admin/login', null, fn(Request $q) => $this->login($q));
        $this->route($r, 'GET', '/admin/forgot', null, fn() => $this->forgotForm());
        $this->route($r, 'POST', '/admin/forgot', null, fn(Request $q) => $this->forgot($q));
        $this->route($r, 'GET', '/admin/reset/{token}', null, fn(Request $q, array $p) => $this->resetForm($p['token']));
        $this->route($r, 'POST', '/admin/reset/{token}', null, fn(Request $q, array $p) => $this->reset($q, $p['token']));
        $this->route($r, 'POST', '/admin/logout', null, function () { Auth::logout(); $this->k->redirect('/admin/login'); });
        $this->route($r, 'GET', '/admin', 'view', fn() => $this->dashboard());

        // content
        $this->route($r, 'GET', '/admin/content', 'view', fn() => $this->contentHome());
        $this->route($r, 'GET', '/admin/content/{type}', 'view', fn(Request $q, array $p) => $this->entries($q, $p['type']));
        $this->route($r, 'GET', '/admin/content/{type}/new', 'view', fn(Request $q, array $p) => $this->editEntry($q, $p['type'], null));
        $this->route($r, 'POST', '/admin/content/{type}/new', 'view', fn(Request $q, array $p) => $this->editEntry($q, $p['type'], null));
        $this->route($r, 'GET', '/admin/content/{type}/{id}', 'view', fn(Request $q, array $p) => $this->editEntry($q, $p['type'], (int)$p['id']));
        $this->route($r, 'POST', '/admin/content/{type}/{id}', 'view', fn(Request $q, array $p) => $this->editEntry($q, $p['type'], (int)$p['id']));
        $this->route($r, 'POST', '/admin/content/{type}/{id}/delete', 'view', fn(Request $q, array $p) => $this->deleteEntry($p['type'], (int)$p['id']));

        // content types
        $this->route($r, 'GET', '/admin/types', 'manage', fn() => $this->typesList());
        $this->route($r, 'GET', '/admin/types/new', 'manage', fn(Request $q) => $this->editType($q, null));
        $this->route($r, 'POST', '/admin/types/new', 'manage', fn(Request $q) => $this->editType($q, null));
        $this->route($r, 'GET', '/admin/types/{slug}', 'manage', fn(Request $q, array $p) => $this->editType($q, $p['slug']));
        $this->route($r, 'POST', '/admin/types/{slug}', 'manage', fn(Request $q, array $p) => $this->editType($q, $p['slug']));
        $this->route($r, 'POST', '/admin/types/{slug}/delete', 'manage', fn(Request $q, array $p) => $this->deleteType($q, $p['slug']));

        $m->register($r);
        (new Designer($this->k, $this))->register($r);
        (new Site($this->k, $this))->register($r);
    }

    // ------------------------------------------------------------ plumbing
    public function route(Router $r, string $method, string $path, ?string $perm, callable $h): void
    {
        $r->add($method, $path, function (Request $q, array $p) use ($perm, $h) {
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
            header('X-Frame-Options: DENY');
            header('Cache-Control: no-store');
            if ($perm !== null) {
                if (!Auth::user()) $this->k->redirect('/admin/login');
                if (!Auth::can($perm)) { http_response_code(403); $this->render('error.twig', ['code' => 403, 'message' => 'ليست لديك صلاحية للوصول إلى هذه الصفحة.']); return; }
            }
            if ($q->isPost() && !Security::csrfOk($q)) {
                http_response_code(419);
                $this->render('error.twig', ['code' => 419, 'message' => 'انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول مجدداً.']);
                return;
            }
            $h($q, $p);
        });
    }

    public function flash(string $text, string $type = 'ok'): void { $this->k->flash('msg', ['type' => $type, 'text' => I18n::t($text)]); }

    public function twig(): Environment
    {
        if ($this->twig) return $this->twig;
        $k = $this->k;
        $cache = $k->root . '/storage/cache/admin';
        $env = new Environment(new FilesystemLoader($k->root . '/admin/views'), [
            'cache' => (is_dir($k->root . '/storage/cache') && is_writable($k->root . '/storage/cache')) ? $cache : false,
            'auto_reload' => true, 'autoescape' => 'html',
        ]);
        $env->addFunction(new TwigFunction('t', fn(string $s, array $v = []) => I18n::t($s, $v)));
        $env->addFunction(new TwigFunction('url', fn(string $p = '') => $k->url($p)));
        $env->addFunction(new TwigFunction('csrf', fn() => Security::csrfToken()));
        $env->addFunction(new TwigFunction('can', fn(string $p) => Auth::can($p)));
        $env->addFunction(new TwigFunction('icon', fn(string $n, int $s = 20, string $c = '') => \Sahab\Icons::svg($n, $s, $c), ['is_safe' => ['html']]));
        $env->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Security::csrfToken(), ENT_QUOTES) . '">', ['is_safe' => ['html']]));
        $env->addFilter(new TwigFilter('ar_digits', fn($s) => Str::arDigits((string)$s)));
        $env->addFilter(new TwigFilter('ar_date', fn($s) => $s ? Str::arDate($s) : ''));
        $env->addFilter(new TwigFilter('bytes', function ($n) { $n = (int)$n; return $n > 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, round($n / 1024)) . ' KB'; }));
        $this->twig = $env;
        return $env;
    }

    public function render(string $tpl, array $ctx = []): void
    {
        $k = $this->k; $user = Auth::user();
        $nav = []; $subnav = null;
        if ($user) {
            $path = $k->req->path;
            $in = fn(string ...$p) => (bool)array_filter($p, fn($x) => $path === $x || str_starts_with($path, $x . '/'));
            $nav[] = ['label' => 'الرئيسية', 'url' => '/admin', 'icon' => 'home', 'active' => $path === '/admin'];
            $nav[] = ['label' => 'مدير المحتوى', 'url' => '/admin/content', 'icon' => 'collection', 'active' => $in('/admin/content')];
            if (Auth::can('manage')) $nav[] = ['label' => 'منشئ الأنواع', 'url' => '/admin/types', 'icon' => 'types', 'group' => 'الأدوات', 'active' => $in('/admin/types')];
            if (Auth::can('design')) $nav[] = ['label' => 'مكتبة الكتل', 'url' => '/admin/blocks', 'icon' => 'layers', 'group' => 'الأدوات', 'active' => $in('/admin/blocks')];
            if (Auth::can('media')) $nav[] = ['label' => 'مكتبة الوسائط', 'url' => '/admin/media', 'icon' => 'media', 'group' => 'الأدوات', 'active' => $in('/admin/media')];
            if (Auth::can('forms.view')) $nav[] = ['label' => 'النماذج والرسائل', 'url' => '/admin/forms', 'icon' => 'forms', 'group' => 'الأدوات', 'active' => $in('/admin/forms')];
            if (Auth::can('manage')) {
                $nav[] = ['label' => 'الواجهة (استيراد موقع)', 'url' => '/admin/site', 'icon' => 'site', 'group' => 'الأدوات', 'active' => $in('/admin/site')];
                $nav[] = ['label' => 'الإعدادات', 'url' => '/admin/settings', 'icon' => 'settings', 'group' => 'عام', 'active' => $in('/admin/settings', '/admin/roles', '/admin/users', '/admin/api', '/admin/webhooks')];
            }
            $nav = Hooks::filter('admin.menu', $nav);

            // contextual second column (Strapi-style)
            $lists = function (string $base, bool $onlyReadable) {
                $g = [];
                foreach (['collection' => ['أنواع المجموعات', 'إنشاء نوع مجموعة جديد'], 'single' => ['الأنواع المفردة', 'إنشاء نوع مفرد جديد']] as $kind => [$label, $act]) {
                    $items = [];
                    foreach ($this->content->types() as $ty) {
                        if ($ty['kind'] !== $kind || ($onlyReadable && !Auth::canType($ty['slug'], 'read'))) continue;
                        $u = $base . '/' . $ty['slug'];
                        $cur = $this->k->req->path;
                        $items[] = ['label' => $ty['plural'], 'url' => $u, 'active' => $cur === $u || str_starts_with($cur, $u . '/')];
                    }
                    $g[] = ['label' => $label, 'count' => count($items), 'items' => $items, 'action' => $onlyReadable ? null : ['label' => $act, 'url' => '/admin/types/new?kind=' . $kind],
                        'empty' => $onlyReadable ? 'لا توجد أنواع بعد.' : null];
                }
                return $g;
            };
            if ($in('/admin/content')) $subnav = ['title' => 'مدير المحتوى', 'groups' => $lists('/admin/content', true)];
            elseif ($in('/admin/types') && Auth::can('manage')) $subnav = ['title' => 'منشئ الأنواع', 'groups' => $lists('/admin/types', false)];
            elseif ($in('/admin/settings', '/admin/roles', '/admin/users', '/admin/api', '/admin/webhooks') && Auth::can('manage')) {
                $subnav = ['title' => 'الإعدادات', 'groups' => [['label' => 'عام', 'count' => null, 'items' => array_map(fn($x) => ['label' => $x[0], 'url' => $x[1], 'active' => $path === $x[1] || str_starts_with($path, $x[1] . '/')],
                    [['الإعدادات العامة', '/admin/settings'], ['الأدوار والصلاحيات', '/admin/roles'], ['المستخدمون', '/admin/users'], ['مفاتيح API', '/admin/api'], ['Webhooks', '/admin/webhooks']]), 'action' => null]]];
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        foreach (['error', 'message'] as $kx) if (isset($ctx[$kx]) && is_string($ctx[$kx])) $ctx[$kx] = I18n::t($ctx[$kx]);
        if (isset($ctx['errors']) && is_array($ctx['errors'])) $ctx['errors'] = array_map(fn($e) => is_string($e) ? I18n::t($e) : $e, $ctx['errors']);
        echo $this->twig()->render($tpl, $ctx + [
            'i18n' => I18n::json(),
            'user' => $user, 'nav' => $nav, 'subnav' => $subnav, 'flash' => $k->flash('msg'), 'path' => $k->req->path,
            'site_title' => $k->setting('site_title'), 'lang' => I18n::$lang, 'dir' => I18n::dir(),
            'version' => Kernel::VERSION, 'av' => Kernel::assetVersion(), 'role_names' => $user ? Auth::roles() : [], 'site_url' => $k->url('/'),
        ]);
    }

    // ------------------------------------------------------------ auth
    private function loginForm(array $ctx = []): void
    {
        if (Auth::user()) $this->k->redirect('/admin');
        $this->render('login.twig', $ctx);
    }

    private function login(Request $q): void
    {
        $err = Auth::attempt(mb_strtolower($q->str('email')), (string)($q->post['password'] ?? ''), $q->ip());
        if ($err) { $this->loginForm(['error' => $err, 'email' => $q->str('email')]); return; }
        $this->k->redirect('/admin');
    }

    /** A content-type preset with its visible texts in the panel language (new types created in English get English labels). */
    private static function trPreset(array $p): array
    {
        foreach (['label', 'desc', 'name', 'plural'] as $k) if (isset($p[$k])) $p[$k] = I18n::t((string)$p[$k]);
        foreach ($p['fields'] ?? [] as $i => $f) {
            if (isset($f['label'])) $p['fields'][$i]['label'] = I18n::t((string)$f['label']);
            if (!empty($f['options']) && is_array($f['options'])) $p['fields'][$i]['options'] = array_map(fn($o) => I18n::t((string)$o), $f['options']);
        }
        return $p;
    }

    private function forgotForm(array $ctx = []): void
    {
        if (Auth::user()) $this->k->redirect('/admin');
        $this->render('forgot.twig', $ctx);
    }

    private function forgot(Request $q): void
    {
        $email = mb_strtolower($q->str('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) Auth::requestReset($email, $q->ip());
        $this->forgotForm(['sent' => true, 'email' => $email]);   // same answer whether or not the account exists
    }

    private function resetForm(string $token, array $ctx = []): void
    {
        $u = Auth::resetUser($token);
        $this->render('reset.twig', $ctx + ['valid' => (bool)$u, 'token' => $token, 'account' => $u ? $u['email'] : '']);
    }

    private function reset(Request $q, string $token): void
    {
        $pass = (string)($q->post['password'] ?? '');
        if (mb_strlen($pass) < 8) { $this->resetForm($token, ['error' => 'كلمة المرور الجديدة 8 أحرف على الأقل.']); return; }
        if ($pass !== (string)($q->post['confirm'] ?? '')) { $this->resetForm($token, ['error' => 'كلمتا المرور غير متطابقتين.']); return; }
        if (!Auth::resetPassword($token, $pass)) { $this->resetForm($token); return; }
        $this->flash('تم تغيير كلمة المرور. سجّل الدخول بكلمة المرور الجديدة.');
        $this->k->redirect('/admin/login');
    }

    private function dashboard(): void
    {
        $db = $this->k->db;
        $counts = $this->content->counts();
        $types = [];
        foreach ($this->content->types() as $s => $t) {
            if (Auth::canType($s, 'read')) $types[] = ['slug' => $s, 'plural' => $t['plural'], 'kind' => $t['kind'], 'count' => $counts[$s] ?? 0, 'can_create' => Auth::canType($s, 'create') && $t['kind'] !== 'single'];
        }
        $this->render('dashboard.twig', [
            'types' => $types,
            'media_count' => (int)$db->val('SELECT COUNT(*) FROM media'),
            'unread' => (int)$db->val('SELECT COUNT(*) FROM submissions WHERE is_read = 0'),
            'users_count' => (int)$db->val('SELECT COUNT(*) FROM users'),
            'php' => PHP_VERSION, 'driver' => $db->driver,
            'warn_install' => is_dir($this->k->root . '/install') && count(glob($this->k->root . '/install/*') ?: []) > 0,
        ]);
    }

    // ------------------------------------------------------------ content
    private function typeOr404(string $slug): array
    {
        $t = $this->content->type($slug);
        if (!$t) { http_response_code(404); $this->render('error.twig', ['code' => 404, 'message' => 'نوع المحتوى غير موجود.']); exit; }
        return $t;
    }

    private function contentHome(): void
    {
        foreach ($this->content->types() as $ty) {
            if (Auth::canType($ty['slug'], 'read')) $this->k->redirect('/admin/content/' . $ty['slug']);
        }
        $this->render('empty_content.twig', ['is_admin' => Auth::can('manage')]);
    }

    private function deny(string $msg = 'ليست لديك صلاحية لهذا الإجراء.'): never
    {
        http_response_code(403);
        $this->render('error.twig', ['code' => 403, 'message' => $msg]);
        exit;
    }

    private function entries(Request $q, string $slug): void
    {
        $t = $this->typeOr404($slug);
        if (!Auth::canType($slug, 'read')) $this->deny();
        if ($t['kind'] === 'single') $this->k->redirect('/admin/content/' . $slug . '/' . (int)$this->content->singleRow($t)['id']);
        $res = $this->content->list($t, [
            'status' => in_array($q->get('status'), ['draft', 'published'], true) ? $q->get('status') : 'any',
            'q' => $q->str('q'), 'limit' => 20, 'page' => (int)$q->get('page', 1),
            'author' => Auth::can('content.others') ? null : Auth::user()['id'],
            'order' => $q->get('sort') ?: 'updated_at:desc',
        ]);
        $this->render('content_list.twig', [
            'type' => $t, 'res' => $res, 'q' => $q->str('q'), 'status' => $q->get('status', ''),
            'can' => ['create' => Auth::canType($slug, 'create'), 'update' => Auth::canType($slug, 'update'), 'delete' => Auth::canType($slug, 'delete')],
        ]);
    }

    private function editEntry(Request $q, string $slug, ?int $id): void
    {
        $t = $this->typeOr404($slug);
        $user = Auth::user();
        $isSingle = $t['kind'] === 'single';
        if ($isSingle && $id === null) $this->k->redirect('/admin/content/' . $slug);
        $row = $id ? $this->content->find($t, $id, false) : null;
        if ($id && !$row) { http_response_code(404); $this->render('error.twig', ['code' => 404, 'message' => 'المحتوى غير موجود.']); return; }
        if (!$id && !Auth::canType($slug, 'create')) $this->deny();
        if ($id && !Auth::canType($slug, 'update')) $this->deny();
        if ($row && !$isSingle && !Auth::can('content.others') && (int)$row['author_id'] !== (int)$user['id']) $this->deny('لا يمكنك تعديل محتوى غيرك.');
        $canPublish = Auth::canType($slug, 'publish');
        $errors = [];
        $raw = $row ?? [];
        if ($q->isPost()) {
            // Strapi-style actions: save (draft/unchanged), publish, unpublish
            $act = $q->str('action', 'save');
            $status = $row['status'] ?? 'draft';
            if ($canPublish && $act === 'publish') $status = 'published';
            if ($canPublish && $act === 'unpublish') $status = 'draft';
            $in = ['title' => $q->str('title'), 'slug' => $q->str('slug'), 'status' => $status, 'published_at' => $q->str('published_at')];
            $f = $q->post['f'] ?? [];
            foreach ($t['fields'] as $fd) {
                $v = $f[$fd['name']] ?? null;
                $in[$fd['name']] = $v;
                $raw['f_' . $fd['name']] = is_array($v) ? implode(',', $v) : $v;
            }
            if (!Auth::can('content.all') && !$id) { /* authors may publish own content */ }
            $res = $this->content->saveEntry($t, $id, $in, (int)$user['id']);
            if (!$res['errors']) {
                $this->flash('تم الحفظ بنجاح.');
                $this->k->redirect('/admin/content/' . $slug . '/' . $res['id']);
            }
            $errors = $res['errors'];
            $raw = array_merge($raw, ['title' => $in['title'], 'slug' => $in['slug'], 'status' => $in['status']]);
        }
        // media + relation helpers for the form
        $ids = [];
        foreach ($t['fields'] as $fd) if ($fd['type'] === 'media') foreach (explode(',', (string)($raw['f_' . $fd['name']] ?? '')) as $i) $ids[] = (int)$i;
        $rel = [];
        foreach ($t['fields'] as $fd) {
            if ($fd['type'] === 'relation' && ($tt = $this->content->type($fd['target']))) {
                $rel[$fd['name']] = array_map(fn($e) => ['id' => $e['id'], 'title' => $e['title']], $this->content->list($tt, ['status' => 'any', 'limit' => 200])['items']);
            }
        }
        $entry = $row ? $this->content->hydrate($t, [$row])[0] : null;
        $this->render('content_edit.twig', [
            'type' => $t, 'raw' => $raw, 'id' => $id, 'errors' => $errors, 'mediaMap' => (new Media($this->k))->byIds($ids),
            'rel' => $rel, 'entry' => $entry, 'preview_url' => $entry ? $entry['url'] . '?preview=1' : null,
            'can_publish' => $canPublish, 'design_url' => ($t['designer'] && $id && Auth::can('design') && Auth::canType($slug, 'update')) ? $this->k->url('/admin/design/entry/' . $slug . '/' . $id) : null, 'can_delete' => Auth::canType($slug, 'delete') && !$isSingle, 'is_single' => $isSingle,
        ]);
    }

    private function deleteEntry(string $slug, int $id): void
    {
        $t = $this->typeOr404($slug);
        if (!Auth::canType($slug, 'delete') || $t['kind'] === 'single') $this->deny();
        $row = $this->content->find($t, $id, false);
        if ($row && (Auth::can('content.others') || (int)$row['author_id'] === (int)Auth::user()['id'])) {
            $this->content->deleteEntry($t, $id);
            $this->flash('تم حذف المحتوى.');
        }
        $this->k->redirect('/admin/content/' . $slug);
    }

    // ------------------------------------------------------------ types
    private function typesList(): void
    {
        $first = array_key_first($this->content->types());
        if ($first !== null) $this->k->redirect('/admin/types/' . $first);
        $this->render('types_list.twig', []);
    }

    private function editType(Request $q, ?string $slug): void
    {
        $t = $slug ? $this->typeOr404($slug) : null;
        $errors = [];
        $form = $t ?? ['slug' => '', 'name' => '', 'plural' => '', 'kind' => $q->get('kind') === 'single' ? 'single' : 'collection', 'designer' => 0, 'fields' => []];
        if ($q->isPost()) {
            $in = ['slug' => $q->str('slug'), 'name' => $q->str('name'), 'plural' => $q->str('plural'), 'kind' => $q->str('kind', 'collection'), 'designer' => $q->str('designer') === '1', 'fields' => array_values((array)($q->post['fields'] ?? []))];
            $errors = $this->content->saveType($in, $slug);
            if (!$errors) {
                $this->flash('تم حفظ نوع المحتوى.');
                $this->k->redirect('/admin/types/' . ($slug ?? strtolower($in['slug'])));
            }
            $form = ['slug' => $slug ?? $in['slug'], 'name' => $in['name'], 'plural' => $in['plural'], 'kind' => $in['kind'], 'designer' => $in['designer'] ? 1 : 0, 'fields' => $in['fields']];
        }
        $this->render('type_edit.twig', [
            'presets' => $pr = ($slug === null ? array_map([self::class, 'trPreset'], array_values(array_filter(Content::presets(), fn($p) => !$this->content->type($p['slug'])))) : []), 'presets_json' => json_encode($pr, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'form' => $form, 'is_new' => $slug === null, 'errors' => $errors, 'field_types' => Content::FIELD_TYPES,
            'all_types' => array_values($this->content->types()),
        ]);
    }

    private function deleteType(Request $q, string $slug): void
    {
        $this->typeOr404($slug);
        if ($q->str('confirm') !== $slug) { $this->flash('اكتب معرّف النوع للتأكيد.', 'err'); $this->k->redirect('/admin/types/' . $slug); }
        $this->content->deleteType($slug);
        $this->flash('تم حذف النوع وجميع محتواه.');
        $this->k->redirect('/admin/types');
    }
}
