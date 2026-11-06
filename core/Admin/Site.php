<?php
declare(strict_types=1);

namespace Sahab\Admin;

use Sahab\{Forms, Icons, Kernel, Request, Router, Security, SiteImporter, Themes, ZipInstaller};

/** Site importer: upload a static website, map its elements to CMS data, activate it as the public theme. */
final class Site
{
    private SiteImporter $imp;

    public function __construct(private Kernel $k, private Routes $a)
    {
        $this->imp = new SiteImporter($k);
    }

    public function register(Router $r): void
    {
        $a = $this->a;
        $a->route($r, 'GET', '/admin/site', 'manage', fn() => $this->index());
        $a->route($r, 'POST', '/admin/site/upload', 'manage', fn(Request $q) => $this->upload($q));
        $a->route($r, 'POST', '/admin/site/{id}/activate', 'manage', fn(Request $q, array $p) => $this->activate($p['id']));
        $a->route($r, 'POST', '/admin/site/{id}/delete', 'manage', fn(Request $q, array $p) => $this->delete($p['id']));
        $a->route($r, 'GET', '/admin/site/{id}', 'manage', fn(Request $q, array $p) => $this->files($p['id']));
        $a->route($r, 'GET', '/admin/site/{id}/map', 'manage', fn(Request $q) => $this->mapper($q));
        $a->route($r, 'GET', '/admin/site/{id}/frame', 'manage', fn(Request $q, array $p) => $this->frame($q, $p['id']));
        $a->route($r, 'GET', '/admin/site/{id}/render', 'manage', fn(Request $q, array $p) => $this->render($q, $p['id']));
        $a->route($r, 'POST', '/admin/site/{id}/snapshot', 'manage', fn(Request $q, array $p) => $this->snapshot($q, $p['id']));
        $a->route($r, 'POST', '/admin/site/{id}/block', 'manage', fn(Request $q, array $p) => $this->block($q, $p['id']));
        $a->route($r, 'POST', '/admin/site/{id}/save', 'manage', fn(Request $q, array $p) => $this->save($q, $p['id']));
    }

    private function theme(string $id): array
    {
        $t = Themes::all($this->k)[$id] ?? null;
        if (!$t || empty($t['imported'])) { $this->a->flash('الموقع غير موجود.', 'err'); $this->k->redirect('/admin/site'); }
        return $t;
    }

    private function index(): void
    {
        $this->a->render('site.twig', ['sites' => Themes::all($this->k)]);
    }

    private function upload(Request $q): void
    {
        $name = $q->str('name');
        $zip = $q->files['zip'] ?? null;
        $loose = $q->files['files'] ?? null;
        $res = null;
        if ($zip && ($zip['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file($zip['tmp_name'])) {
            $res = $this->imp->importZip($zip['tmp_name'], $name ?: pathinfo((string)$zip['name'], PATHINFO_FILENAME));
        } elseif ($loose && is_array($loose['name'])) {
            $paths = (array)($q->post['paths'] ?? []);
            $ups = [];
            foreach ($loose['name'] as $i => $n) {
                if ($loose['error'][$i] === UPLOAD_ERR_OK && is_uploaded_file($loose['tmp_name'][$i])) $ups[] = ['name' => $n, 'tmp_name' => $loose['tmp_name'][$i], 'path' => (string)($paths[$i] ?? '')];
            }
            if ($ups) $res = $this->imp->importFiles($ups, $name ?: 'site');
        }
        if (!$res) { $this->a->flash('اختر ملف zip أو ملفات HTML/CSS/JS.', 'err'); $this->k->redirect('/admin/site'); }
        if ($res['error']) { $this->a->flash($res['error'], 'err'); $this->k->redirect('/admin/site'); }
        $this->a->flash('تم رفع الموقع. عيّن كل صفحة لعرضها واربط عناصرها بالمحتوى.');
        $this->k->redirect('/admin/site/' . $res['id']);
    }

    private function activate(string $id): void
    {
        Themes::activate($this->k, $id) ? $this->a->flash('تم تفعيل الواجهة.') : $this->a->flash('غير موجود.', 'err');
        $this->k->redirect('/admin/site');
    }

    private function delete(string $id): void
    {
        Themes::delete($this->k, $id) ? $this->a->flash('تم حذف الموقع.') : $this->a->flash('لا يمكن حذف الواجهة الافتراضية أو المفعّلة.', 'err');
        $this->k->redirect('/admin/site');
    }

    private function files(string $id): void
    {
        $t = $this->theme($id);
        $site = $this->imp->loadSite($id);
        $rows = [];
        foreach ($this->imp->files($id) as $f) {
            $c = $site['files'][$f] ?? ['assign' => ['kind' => 'none'], 'bindings' => []];
            $rows[] = ['file' => $f, 'assign' => $c['assign'] ?? ['kind' => 'none'], 'bindings' => count($c['bindings'] ?? [])];
        }
        $this->a->render('site_files.twig', ['site' => $t, 'rows' => $rows]);
    }

    private function relOf(Request $q, string $id): string
    {
        $f = str_replace('\\', '/', $q->str('f'));
        if ($f === '' || str_contains($f, '..') || !in_array($f, $this->imp->files($id), true)) { $this->a->flash('الملف غير موجود.', 'err'); $this->k->redirect('/admin/site/' . $id); }
        return $f;
    }

    private function mapper(Request $q): void
    {
        $id = basename(dirname($q->path));
        $t = $this->theme($id);
        $rel = $this->relOf($q, $id);
        $cfg = $this->imp->loadSite($id)['files'][$rel] ?? [];
        $types = [];
        foreach ($this->a->content->types() as $ty) $types[] = ['slug' => $ty['slug'], 'name' => $ty['name'], 'plural' => $ty['plural'], 'kind' => $ty['kind'],
            'fields' => array_merge([['name' => 'title', 'label' => 'العنوان', 'type' => 'text'], ['name' => 'url', 'label' => 'رابط المدخل', 'type' => 'url'], ['name' => 'slug', 'label' => 'الرابط المختصر', 'type' => 'text'], ['name' => 'published_at', 'label' => 'تاريخ النشر', 'type' => 'date']], $ty['fields'])];
        $forms = array_map(fn($f) => ['slug' => $f['slug'], 'name' => $f['name'], 'fields' => array_column($f['fields'], 'name')], (new Forms($this->k))->all());
        // block mode: edit a saved site block visually (same preview + binding UI, limited to the block's element)
        $blockInfo = null; $tree = $this->imp->tree($id, $rel); $frameQs = '';
        if (($bid = (int)$q->get('block')) > 0 && ($br = $this->k->db->one("SELECT id, title, type, meta_json FROM designs WHERE id = ? AND kind = 'siteblock'", [$bid]))) {
            $bm = json_decode((string)$br['meta_json'], true) ?: [];
            if (($bm['site'] ?? '') === $id && ($bm['file'] ?? '') === $rel && (int)($bm['sp'] ?? 0) > 0) {
                $find = function (array $nodes, int $sp) use (&$find) { foreach ($nodes as $n) { if ($n['sp'] === $sp) return $n; if ($r = $find($n['kids'] ?? [], $sp)) return $r; } return null; };
                if ($sub = $find($tree, (int)$bm['sp'])) {
                    $blockInfo = ['id' => (int)$br['id'], 'name' => $br['title'], 'sp' => (int)$bm['sp'], 'fields' => $bm['fields'] ?? []];
                    $tree = [$sub]; $frameQs = '&only=' . (int)$bm['sp'];
                }
            }
        }        $boot = [
            'id' => $id, 'file' => $rel, 'assign' => $cfg['assign'] ?? ['kind' => 'none'], 'bindings' => $cfg['bindings'] ?? [], 'title' => $cfg['title'] ?? '', 'description' => $cfg['description'] ?? '', 'tree' => $tree, 'block' => $blockInfo,
            'types' => $types, 'forms' => $forms, 'csrf' => Security::csrfToken(), 'icons' => array_intersect_key(Icons::all(), array_flip(['back', 'save', 'close', 'trash', 'view', 'plus', 'repeat', 'menuitems', 'code', 'text', 'image', 'link', 'form', 'cursor', 'down', 'next', 'layers'])),
            'jsHeavy' => $this->imp->jsHeavy($id, $rel), 'pages' => array_filter(SiteImporter::routesOf($this->imp->loadSite($id)), fn($r) => $r !== null),
            'urls' => ['block' => $this->k->url('/admin/site/' . $id . '/block') . '?f=' . rawurlencode($rel), 'render' => $this->k->url('/admin/site/' . $id . '/render') . '?f=' . rawurlencode($rel), 'snapshot' => $this->k->url('/admin/site/' . $id . '/snapshot') . '?f=' . rawurlencode($rel), 'frame' => $this->k->url('/admin/site/' . $id . '/frame') . '?f=' . rawurlencode($rel) . $frameQs, 'save' => $this->k->url('/admin/site/' . $id . '/save') . '?f=' . rawurlencode($rel), 'back' => $blockInfo ? $this->k->url('/admin/blocks') : $this->k->url('/admin/site/' . $id)],
        ];
        header('Content-Type: text/html; charset=utf-8');
        echo $this->a->twig()->render('site_mapper.twig', [
            'site' => $t, 'file' => $rel, 'lang' => \Sahab\I18n::$lang, 'dir' => \Sahab\I18n::dir(), 'i18n' => \Sahab\I18n::json(), 'version' => Kernel::VERSION, 'av' => Kernel::assetVersion(),
            'boot' => json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'site_title' => $this->k->setting('site_title'),
        ]);
    }

    private function frame(Request $q, string $id): void
    {
        $this->theme($id);
        $rel = $this->relOf($q, $id);
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https: http:; font-src 'self' data: https:; media-src 'self'; script-src 'self'; frame-ancestors 'self'; base-uri 'none'");
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Type: text/html; charset=utf-8');
        echo $this->imp->previewHtml($id, $rel, $q->get('only') !== null ? (int)$q->get('only') : null);
    }

    /** Page with its own scripts, in a sandboxed (opaque-origin) frame: it can render, but cannot touch the admin. */
    private function render(Request $q, string $id): void
    {
        $this->theme($id);
        $rel = $this->relOf($q, $id);
        $o = preg_replace('#^(https?://[^/]+).*#', '$1', $this->k->siteUrl()); // sandboxed docs have an opaque origin, so name the origin explicitly
        if ($q->get('trusted') === '1') {
            // Explicit opt-in: the page runs on the admin origin (the site's JS could reach the admin). Only for sites you trust.
            header("Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https: http:; font-src 'self' data: https:; media-src 'self'; frame-ancestors 'self'; base-uri 'none'");
        } else {
            header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src $o 'unsafe-inline'; style-src $o 'unsafe-inline' https:; img-src $o data: https: http:; font-src $o data: https:; media-src $o; frame-ancestors 'self'; base-uri 'none'");
        }        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Type: text/html; charset=utf-8');
        echo $this->imp->renderHtml($id, $rel);
    }

    private function snapshot(Request $q, string $id): void
    {
        $this->theme($id);
        $rel = $this->relOf($q, $id);
        $in = $q->json();
        $html = (string)($in['html'] ?? '');
        if ($html === '' || strlen($html) > 6_000_000) $this->k->json(['ok' => false, 'error' => 'الالتقاط فارغ أو كبير جداً.'], 422);
        $this->imp->snapshot($id, $rel, $html, !empty($in['strip']));
        $this->k->json(['ok' => true]);
    }

    /** Save one element of an imported page (with its bindings) as a reusable "site block" for the designer. */
    private function block(Request $q, string $id): void
    {
        $this->theme($id);
        $rel = $this->relOf($q, $id);
        $in = $q->json();
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
        $sp = (int)($in['sp'] ?? 0);
        if ($name === '' || $sp < 1) $this->k->json(['ok' => false, 'error' => 'اسم الكتلة وعنصر مطلوبان.'], 422);
        $twig = $this->imp->compileElement($id, $rel, $sp);
        if (trim($twig) === '') $this->k->json(['ok' => false, 'error' => 'تعذر توليد الكتلة (العنصر محذوف أو غير موجود).'], 422);
        if (($upd = (int)($in['block'] ?? 0)) > 0) {   // block mode: regenerate an existing block from the (re)bound element
            if (!$this->k->db->val("SELECT COUNT(*) FROM designs WHERE id = ? AND kind = 'siteblock'", [$upd])) $this->k->json(['ok' => false, 'error' => 'الكتلة غير موجودة.'], 404);
            $old = json_decode((string)$this->k->db->val('SELECT meta_json FROM designs WHERE id = ?', [$upd]), true) ?: [];
            if (isset($in['fields'])) $old['fields'] = Designer::cleanFields($in['fields']);
            $this->k->db->update('designs', ['title' => $name, 'tree_json' => $twig, 'meta_json' => json_encode($old, JSON_UNESCAPED_UNICODE), 'updated_at' => $this->k->db->now()], 'id = ?', [$upd]);
            $this->k->json(['ok' => true, 'id' => $upd]);
        }        $cfg = $this->imp->loadSite($id)['files'][$rel] ?? [];
        $type = (string)($cfg['assign']['type'] ?? '');
        $now = $this->k->db->now();
        $bid = $this->k->db->insert('designs', ['kind' => 'siteblock', 'type' => $this->a->content->type($type) ? $type : null, 'entry_id' => null, 'title' => $name, 'status' => 'published', 'layout' => 'theme',
            'tree_json' => $twig, 'live_json' => null, 'meta_json' => json_encode(['site' => $id, 'file' => $rel, 'sp' => $sp]), 'created_at' => $now, 'updated_at' => $now]);
        $this->k->json(['ok' => true, 'id' => $bid]);
    }

    private function save(Request $q, string $id): void
    {
        $this->theme($id);
        $rel = $this->relOf($q, $id);
        $in = $q->json();
        $a = (array)($in['assign'] ?? []);
        $kind = in_array($a['kind'] ?? '', ['none', 'home', 'archive', 'single', 'static'], true) ? $a['kind'] : 'none';
        $assign = ['kind' => $kind];
        if (in_array($kind, ['archive', 'single'], true)) {
            $type = (string)($a['type'] ?? '');
            if (!$this->a->content->type($type)) $this->k->json(['ok' => false, 'error' => 'اختر نوع المحتوى.'], 422);
            $assign['type'] = $type;
        }
        if ($kind === 'static') {
            $slug = (string)($a['slug'] ?? '');
            if (!preg_match('/^[\p{L}\p{N}_-]{1,60}$/u', $slug) || in_array($slug, \Sahab\Content::RESERVED, true) || $this->a->content->type($slug)) $this->k->json(['ok' => false, 'error' => 'رابط الصفحة غير صالح أو محجوز.'], 422);
            $assign['slug'] = $slug;
        }
        $bindings = [];
        foreach ((array)($in['bindings'] ?? []) as $b) {
            if (!is_array($b) || !in_array($b['kind'] ?? '', ['text', 'html', 'attr', 'bgimage', 'form', 'menu', 'repeat', 'remove'], true)) continue;
            $bindings[] = array_intersect_key($b, array_flip(['sp', 'kind', 'src', 'attr', 'fmt', 'form', 'menu', 'rtype', 'limit', 'order', 'dedupe'])) + ['sp' => 0];
            if (count($bindings) > 400) break;
        }
        $site = $this->imp->loadSite($id);
        $site['files'][$rel] = ['assign' => $assign, 'bindings' => $bindings,
            'title' => mb_substr((string)($in['title'] ?? ''), 0, 60), 'description' => mb_substr((string)($in['description'] ?? ''), 0, 60)];
        $this->imp->saveSite($id, $site);
        $this->imp->generate($id);
        $this->k->json(['ok' => true]);
    }
}
