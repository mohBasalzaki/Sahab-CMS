<?php
declare(strict_types=1);

namespace Sahab\Admin;

use Sahab\{Auth, Design, Forms, Front, Icons, Kernel, Request, Router, Themes};

/**
 * Visual designer. Enabled per content type; every entry can get its own design (opened from the entry editor),
 * and each type can have a shared template used by entries without a design of their own.
 */
final class Designer
{
    public function __construct(private Kernel $k, private Routes $a) {}

    private function db() { return $this->k->db; }

    public function register(Router $r): void
    {
        $a = $this->a;
        $a->route($r, 'GET', '/admin/design/entry/{type}/{id}', 'design', fn(Request $q, array $p) => $this->openEntry($p['type'], (int)$p['id']));
        $a->route($r, 'GET', '/admin/design/type/{type}', 'design', fn(Request $q, array $p) => $this->openTemplate($p['type']));
        $a->route($r, 'GET', '/admin/blocks', 'design', fn() => $this->blocksList());
        $a->route($r, 'POST', '/admin/blocks/new', 'design', fn(Request $q) => $this->blockNew($q));
        $a->route($r, 'POST', '/admin/blocks/{id}/delete', 'design', fn(Request $q, array $p) => $this->blockDelete((int)$p['id']));
        $a->route($r, 'POST', '/admin/design/to-block', 'design', fn(Request $q) => $this->toBlock($q));
        $a->route($r, 'POST', '/admin/design/preview', 'design', fn(Request $q) => $this->preview($q));
        $a->route($r, 'GET', '/admin/design/{id}', 'design', fn(Request $q, array $p) => $this->editor((int)$p['id']));
        $a->route($r, 'GET', '/admin/design/{id}/canvas', 'design', fn() => $this->canvas());
        $a->route($r, 'POST', '/admin/design/{id}/save', 'design', fn(Request $q, array $p) => $this->save($q, (int)$p['id'], false));
        $a->route($r, 'POST', '/admin/design/{id}/publish', 'design', fn(Request $q, array $p) => $this->save($q, (int)$p['id'], true));
        $a->route($r, 'POST', '/admin/design/{id}/unpublish', 'design', fn(Request $q, array $p) => $this->unpublish((int)$p['id']));
        $a->route($r, 'POST', '/admin/design/{id}/delete', 'design', fn(Request $q, array $p) => $this->delete((int)$p['id']));
    }

    private function fail(string $msg): never
    {
        $this->a->flash($msg, 'err');
        $this->k->redirect('/admin');
    }

    private function row(int $id): array
    {
        $d = $this->db()->one('SELECT * FROM designs WHERE id = ?', [$id]);
        return $d ?: $this->fail('التصميم غير موجود.');
    }

    private function enabledType(string $slug): array
    {
        $t = $this->a->content->type($slug);
        if (!$t || !$t['designer']) $this->fail('المصمم غير مفعّل لهذا النوع. فعّله من منشئ الأنواع.');
        return $t;
    }

    private function openEntry(string $slug, int $id): void
    {
        $t = $this->enabledType($slug);
        if (!Auth::canType($slug, 'update')) $this->fail('ليست لديك صلاحية تعديل هذا النوع.');
        $e = $this->a->content->find($t, $id, false);
        if (!$e) $this->fail('المدخل غير موجود.');
        $d = $this->db()->one("SELECT id FROM designs WHERE kind = 'entry' AND type = ? AND entry_id = ?", [$slug, $id]);
        if (!$d) {
            // start from the type template when there is one, otherwise a sensible default
            $tpl = $this->db()->one("SELECT tree_json FROM designs WHERE kind = 'template' AND type = ?", [$slug]);
            $tree = $tpl ? Design::normalize($tpl['tree_json']) : Design::defaultTree('entry', $t);
            $now = $this->db()->now();
            $did = $this->db()->insert('designs', ['kind' => 'entry', 'type' => $slug, 'entry_id' => $id, 'title' => mb_substr((string)$e['title'], 0, 200), 'status' => 'draft', 'layout' => 'theme',
                'tree_json' => json_encode($tree, JSON_UNESCAPED_UNICODE), 'live_json' => null, 'meta_json' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $did = (int)$d['id'];
        }
        $this->k->redirect('/admin/design/' . $did);
    }

    private function openTemplate(string $slug): void
    {
        $t = $this->enabledType($slug);
        $d = $this->db()->one("SELECT id FROM designs WHERE kind = 'template' AND type = ?", [$slug]);
        if (!$d) {
            $now = $this->db()->now();
            $did = $this->db()->insert('designs', ['kind' => 'template', 'type' => $slug, 'entry_id' => null, 'title' => 'قالب ' . $t['name'], 'status' => 'draft', 'layout' => 'theme',
                'tree_json' => json_encode(Design::defaultTree('template', $t), JSON_UNESCAPED_UNICODE), 'live_json' => null, 'meta_json' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $did = (int)$d['id'];
        }
        $this->k->redirect('/admin/design/' . $did);
    }

    // ---------------------------------------------------------------- editor
    private function ctxEntry(array $d): array
    {
        $t = $this->a->content->type((string)$d['type']);
        if (!$t) return [];
        if ($d['kind'] === 'block' && !$t) return ['entry' => [], 'type' => ''];
        if ($d['kind'] === 'entry' && $d['entry_id']) {
            $e = $this->a->content->find($t, (int)$d['entry_id']);
            return ['entry' => $e ?? [], 'type' => $t['slug']];
        }
        $first = $this->a->content->list($t, ['limit' => 1, 'status' => 'any'])['items'][0] ?? null;
        return ['entry' => $first ?? [], 'type' => $t['slug']];
    }

    private function editor(int $id): void
    {
        $d = $this->row($id);
        $types = [];
        foreach ($this->a->content->types() as $t) {
            $types[] = ['slug' => $t['slug'], 'name' => $t['name'], 'plural' => $t['plural'], 'kind' => $t['kind'],
                'fields' => array_merge([['name' => 'title', 'label' => 'العنوان', 'type' => 'text'], ['name' => 'slug', 'label' => 'الرابط', 'type' => 'text'], ['name' => 'published_at', 'label' => 'تاريخ النشر', 'type' => 'date']], $t['fields'])];
        }
        $forms = array_map(fn($f) => ['slug' => $f['slug'], 'name' => $f['name']], (new Forms($this->k))->all());
        $site = $this->k->url('/');
        $t = $this->a->content->type((string)$d['type']);
        if ($t && $d['kind'] === 'entry' && ($e = $this->a->content->find($t, (int)$d['entry_id']))) $site = $e['url'] . '?preview=1';
        $back = $d['kind'] === 'block' ? $this->k->url('/admin/blocks') : ($t && $d['kind'] === 'entry' ? $this->k->url('/admin/content/' . $t['slug'] . '/' . (int)$d['entry_id']) : $this->k->url('/admin/types/' . $d['type']));
        $icons = array_intersect_key(Icons::all(), array_flip(['section', 'container', 'flex', 'grid', 'heading', 'paragraph', 'richtext', 'image', 'button', 'link', 'form', 'cmslist', 'spacer', 'plus', 'close', 'undo', 'redo', 'desktop', 'tablet', 'mobile', 'save', 'publish', 'view', 'back', 'layers', 'brush', 'trash', 'copy', 'up', 'down', 'edit', 'code']));
        $ds = Themes::designSystem($this->k);
        $blocks = array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['title'], 'kind' => $b['kind'], 'type' => $b['type'], 'tree' => $b['kind'] === 'block' ? Design::normalize($b['tree_json']) : null,
                'edit' => $b['kind'] === 'siteblock' ? $this->mapUrl($b) : null, 'fields' => (json_decode((string)$b['meta_json'], true) ?: [])['fields'] ?? []],
            $this->db()->all("SELECT id, kind, type, title, tree_json, meta_json FROM designs WHERE kind IN ('block', 'siteblock') AND id <> ? ORDER BY id DESC", [$id]));
        $boot = [
            'design' => ['id' => (int)$d['id'], 'kind' => $d['kind'], 'type' => $d['type'], 'title' => $d['title'], 'status' => $d['status'], 'layout' => $d['layout'],
                'tree' => Design::normalize($d['tree_json']), 'meta' => json_decode((string)$d['meta_json'], true) ?: (object)[], 'hasLive' => $d['live_json'] !== null,
                'fields' => $d['kind'] === 'block' ? ((json_decode((string)$d['meta_json'], true) ?: [])['fields'] ?? []) : []],
            'types' => $types, 'forms' => $forms, 'csrf' => \Sahab\Security::csrfToken(), 'icons' => $icons, 'blocks' => $blocks, 'ds' => ['classes' => array_slice($ds['classes'], 0, 400), 'vars' => array_slice($ds['vars'], 0, 150)], 'dsCss' => $ds['css'],
            'urls' => ['base' => $this->k->url('/admin/design/' . $id), 'preview' => $this->k->url('/admin/design/preview'), 'media' => $this->k->url('/admin/media'), 'back' => $back, 'site' => $site],
        ];
        header('Content-Type: text/html; charset=utf-8');
        echo $this->a->twig()->render('design_editor.twig', [
            'd' => $d, 'back' => $back, 'boot' => json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'lang' => \Sahab\I18n::$lang, 'dir' => \Sahab\I18n::dir(), 'i18n' => \Sahab\I18n::json(), 'version' => Kernel::VERSION, 'av' => Kernel::assetVersion(), 'site_title' => $this->k->setting('site_title'),
        ]);
    }

    private function canvas(): void
    {
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https: http:; font-src 'self' data: https:; script-src 'self'; frame-ancestors 'self'; base-uri 'none'");
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Type: text/html; charset=utf-8');
        $v = Kernel::assetVersion();
        $ds = Themes::designSystem($this->k);
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $attrs = '';
        foreach (($ds['htmlAttrs'] ?? []) as $n => $val) if (preg_match('/^(data-[a-z0-9-]{1,30})$/', (string)$n)) $attrs .= ' ' . $n . '="' . $e($val) . '"';
        if (!str_contains($attrs, ' dir=')) $attrs .= ' dir="' . \Sahab\I18n::dir(\Sahab\I18n::siteLang()) . '"';
        if (!str_contains($attrs, ' lang=')) $attrs .= ' lang="' . \Sahab\I18n::siteLang() . '"';
        $links = '';
        foreach ($ds['css'] ?? [] as $href) if (preg_match('#^(https?://|/)#', (string)$href)) $links .= '<link rel="stylesheet" href="' . $e($href) . '">';
        $wrap = $ds['wrap'] ?? null;
        $open = $wrap ? '<' . $wrap['tag'] . ' class="' . $e($wrap['class']) . '">' : '';
        $close = $wrap ? '</' . $wrap['tag'] . '>' : '';
        echo '<!doctype html><html' . $attrs . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="' . $this->k->url('/admin/assets/canvas.css') . '?v=' . $v . '">' . $links . '<style id="dz-css"></style></head><body class="' . $e($ds['bodyClass'] ?? '') . '">'
            . $open . '<main class="dz-main"><div id="root"></div></main>' . $close
            . '<script src="' . $this->k->url('/admin/assets/canvas.js') . '?v=' . $v . '"></script></body></html>';
    }

    /** Twig renderer for "site blocks" (HTML compiled from an imported site). */
    private function twigRenderer(): callable
    {
        $front = new Front($this->k);
        return fn(string $code, array $ctx) => $front->twig()->createTemplate($code)->render($ctx);
    }

    /** POST JSON {tree, id} -> {html, css} rendered with editor markers. */
    private function preview(Request $q): void
    {
        $in = $q->json();
        $d = $this->row((int)($in['id'] ?? 0));
        $dz = (new Design($this->k, $this->a->content))->withTwig($this->twigRenderer());
        $ctx = $this->ctxEntry($d);
        if ($d['kind'] === 'block') $ctx['block'] = $dz->valuesFor(self::cleanFields($in['fields'] ?? []), [], $ctx);
        $r = $dz->render(Design::normalize($in['tree'] ?? null), $ctx, true);
        $this->k->json($r);
    }

    // ---------------------------------------------------------------- blocks library
    /** URL of the visual mapper for a site block (null when its source page no longer exists). */
    private function mapUrl(array $b): ?string
    {
        $m = json_decode((string)($b['meta_json'] ?? ''), true) ?: [];
        if (empty($m['site']) || empty($m['file']) || !is_file(\Sahab\SiteImporter::root($this->k, (string)$m['site']) . '/source/' . $m['file'])) return null;
        return $this->k->url('/admin/site/' . rawurlencode((string)$m['site']) . '/map') . '?f=' . rawurlencode((string)$m['file']) . '&block=' . (int)$b['id'];
    }

    private function blocksList(): void
    {
        $rows = $this->db()->all("SELECT id, kind, type, title, updated_at, meta_json FROM designs WHERE kind IN ('block', 'siteblock') ORDER BY id DESC");
        foreach ($rows as &$r) {
            $r['edit_url'] = $r['kind'] === 'siteblock' ? $this->mapUrl($r) : $this->k->url('/admin/design/' . $r['id']);
        }
        unset($r);
        $this->a->render('blocks.twig', ['blocks' => $rows, 'types' => array_values($this->a->content->types())]);
    }

    private function blockTree(string $heading): array
    {
        $t = Design::defaultTree('page');
        $t['children'][0]['children'][0]['children'] = [['id' => Design::id(), 'type' => 'heading', 'props' => ['text' => $heading, 'level' => 2], 'style' => ['desktop' => ['font-size' => '28px', 'font-weight' => '700']], 'children' => []]];
        return $t;
    }

    private function blockNew(Request $q): void
    {
        $name = mb_substr($q->str('name'), 0, 120);
        if ($name === '') { $this->a->flash('اسم الكتلة مطلوب.', 'err'); $this->k->redirect('/admin/blocks'); }
        $type = $this->a->content->type($q->str('type')) ? $q->str('type') : null;
        $now = $this->db()->now();
        $id = $this->db()->insert('designs', ['kind' => 'block', 'type' => $type, 'entry_id' => null, 'title' => $name, 'status' => 'published', 'layout' => 'theme',
            'tree_json' => json_encode($this->blockTree($name), JSON_UNESCAPED_UNICODE), 'live_json' => null, 'meta_json' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $this->k->redirect('/admin/design/' . $id);
    }

    private function blockDelete(int $id): void
    {
        $this->db()->exec("DELETE FROM designs WHERE id = ? AND kind IN ('block', 'siteblock')", [$id]);
        $this->a->flash('تم حذف الكتلة.');
        $this->k->redirect('/admin/blocks');
    }

    /** POST JSON {name, node, type?}: save a selected element (and its children) from the designer as a reusable block. */
    private function toBlock(Request $q): void
    {
        $in = $q->json();
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
        $node = $in['node'] ?? null;
        if ($name === '' || !is_array($node) || !isset($node['type'])) $this->k->json(['ok' => false, 'error' => 'بيانات غير صالحة.'], 422);
        $tree = ['id' => 'root', 'type' => 'body', 'props' => [], 'style' => ['desktop' => []], 'children' => [$node]];
        $raw = json_encode(Design::normalize($tree), JSON_UNESCAPED_UNICODE);
        if (strlen($raw) > 600000) $this->k->json(['ok' => false, 'error' => 'الكتلة كبيرة جداً.'], 413);
        $type = $this->a->content->type((string)($in['type'] ?? '')) ? (string)$in['type'] : null;
        $now = $this->db()->now();
        $id = $this->db()->insert('designs', ['kind' => 'block', 'type' => $type, 'entry_id' => null, 'title' => $name, 'status' => 'published', 'layout' => 'theme',
            'tree_json' => $raw, 'live_json' => null, 'meta_json' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $this->k->json(['ok' => true, 'block' => ['id' => $id, 'name' => $name, 'kind' => 'block', 'type' => $type, 'tree' => json_decode($raw, true)]]);
    }
    /** Validate the fields a block exposes (its "inputs"). */
    public static function cleanFields(mixed $fields, bool $nested = false): array
    {
        $out = []; $seen = [];
        $types = ['text', 'textarea', 'richtext', 'image', 'url', 'number', 'boolean', 'color', 'select'];
        if (!$nested) $types[] = 'repeater';
        foreach ((array)$fields as $f) {
            if (!is_array($f)) continue;
            $n = strtolower(trim((string)($f['name'] ?? '')));
            $type = (string)($f['type'] ?? 'text');
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $n) || isset($seen[$n]) || !in_array($type, $types, true)) continue;
            $seen[$n] = 1;
            $row = ['name' => $n, 'label' => mb_substr(trim((string)($f['label'] ?? '')) ?: $n, 0, 60), 'type' => $type];
            if ($type === 'repeater') {
                $row['fields'] = self::cleanFields($f['fields'] ?? [], true);
                $dr = [];
                foreach (array_slice((array)($f['default'] ?? []), 0, 20) as $r) if (is_array($r)) $dr[] = array_map(fn($v) => is_scalar($v) ? mb_substr((string)$v, 0, 500) : '', $r);
                $row['default'] = $dr;
            } else {
                $row['default'] = mb_substr(is_scalar($f['default'] ?? '') ? (string)($f['default'] ?? '') : '', 0, 500);
                if ($type === 'select') {
                    $opts = [];
                    foreach (array_slice((array)($f['options'] ?? []), 0, 40) as $o) {
                        if (!is_array($o)) continue;
                        $v = mb_substr(trim((string)($o['value'] ?? '')), 0, 60);
                        if ($v !== '') $opts[] = ['value' => $v, 'label' => mb_substr(trim((string)($o['label'] ?? '')) ?: $v, 0, 60)];
                    }
                    $row['options'] = $opts;
                }
            }
            $out[] = $row;
            if (count($out) >= 24) break;
        }
        return $out;
    }

    private function save(Request $q, int $id, bool $publish): void
    {
        $d = $this->row($id);
        $in = $q->json();
        $raw = json_encode(Design::normalize($in['tree'] ?? null), JSON_UNESCAPED_UNICODE);
        if (strlen($raw) > 1_500_000) $this->k->json(['ok' => false, 'error' => 'التصميم كبير جداً.'], 413);
        $meta = [];
        foreach (['title', 'description'] as $f) if (isset($in['meta'][$f]) && is_string($in['meta'][$f])) $meta[$f] = mb_substr($in['meta'][$f], 0, 300);
        if ($d['kind'] === 'block') $meta['fields'] = self::cleanFields($in['fields'] ?? ((json_decode((string)$d['meta_json'], true) ?: [])['fields'] ?? []));
        $row = ['tree_json' => $raw, 'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => $this->db()->now()];
        if (!empty($in['title']) && is_string($in['title'])) $row['title'] = mb_substr(trim($in['title']), 0, 200);
        if (in_array($in['layout'] ?? '', ['theme', 'blank'], true)) $row['layout'] = $in['layout'];
        if ($publish) { $row['live_json'] = $raw; $row['status'] = 'published'; }
        $this->db()->update('designs', $row, 'id = ?', [$id]);
        $this->k->json(['ok' => true, 'status' => $publish ? 'published' : $d['status']]);
    }

    private function unpublish(int $id): void
    {
        $this->row($id);
        $this->db()->update('designs', ['status' => 'draft'], 'id = ?', [$id]);
        $this->k->json(['ok' => true]);
    }

    private function delete(int $id): void
    {
        $d = $this->row($id);
        $this->db()->exec('DELETE FROM designs WHERE id = ?', [$id]);
        $this->k->json(['ok' => true, 'redirect' => $this->k->url($d['kind'] === 'entry' ? '/admin/content/' . $d['type'] . '/' . (int)$d['entry_id'] : '/admin/types/' . $d['type'])]);
    }
}
