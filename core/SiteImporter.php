<?php
declare(strict_types=1);

namespace Sahab;

use Masterminds\HTML5;

/**
 * Turns an uploaded static website (zip, or loose html/css/js/images) into a dynamic theme.
 * Layout: themes/<id>/{theme.json, site.json, source/*.html, assets/**, templates/*.twig (generated)}.
 * The admin maps elements of each page to CMS data ("bindings"); generate() rewrites the HTML into Twig templates.
 */
final class SiteImporter
{
    private const ASSET_EXT = ['css', 'js', 'mjs', 'map', 'json', 'txt', 'xml', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'ogg', 'pdf', 'webmanifest'];
    private const PAGE_EXT = ['html', 'htm'];
    private const URL_ATTRS = ['link' => ['href'], 'script' => ['src'], 'img' => ['src', 'data-src', 'srcset'], 'source' => ['src', 'srcset'], 'video' => ['src', 'poster'],
        'audio' => ['src'], 'a' => ['href'], 'use' => ['href', 'xlink:href'], 'image' => ['href'], 'input' => ['src']];

    public function __construct(private Kernel $k) {}

    public static function root(Kernel $k, string $id): string { return $k->root . '/themes/' . $id; }

    // ------------------------------------------------------------------ import
    /** @return array{id:?string,error:?string} */
    public function importZip(string $zip, string $name): array
    {
        if (!class_exists(\ZipArchive::class)) return ['id' => null, 'error' => 'إضافة zip غير مفعلة في PHP.'];
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) return ['id' => null, 'error' => 'ملف zip غير صالح.'];
        if ($z->numFiles > 4000) { $z->close(); return ['id' => null, 'error' => 'عدد الملفات كبير جداً.']; }
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = str_replace('\\', '/', (string)$z->getNameIndex($i));
            if ($n !== '' && !str_ends_with($n, '/') && !str_starts_with($n, '__MACOSX/')) $names[$i] = $n;
        }
        // strip a single common top-level folder
        $tops = array_unique(array_map(fn($n) => str_contains($n, '/') ? explode('/', $n)[0] : '', $names));
        $prefix = (count($tops) === 1 && $tops[array_key_first($tops)] !== '') ? reset($tops) . '/' : '';
        $files = [];
        $total = 0;
        foreach ($names as $i => $n) {
            $rel = $prefix !== '' ? substr($n, strlen($prefix)) : $n;
            $st = $z->statIndex($i);
            $total += (int)$st['size'];
            if ($total > 80 * 1048576) { $z->close(); return ['id' => null, 'error' => 'حجم الموقع بعد الفك كبير جداً (الحد 80MB).']; }
            $files[] = ['rel' => $rel, 'stream' => $i];
        }
        $res = $this->build($name, $files, fn($f) => $z->getStream($z->getNameIndex($f['stream'])));
        $z->close();
        return $res;
    }

    /** @param array<int,array{name:string,tmp_name:string,path?:string}> $uploads */
    public function importFiles(array $uploads, string $name): array
    {
        $files = [];
        foreach ($uploads as $u) {
            $rel = str_replace('\\', '/', (string)($u['path'] ?? '') !== '' ? $u['path'] : $u['name']);
            $files[] = ['rel' => $rel, 'tmp' => $u['tmp_name']];
        }
        // drop a shared top folder when a directory was picked
        $tops = array_unique(array_map(fn($f) => str_contains($f['rel'], '/') ? explode('/', $f['rel'])[0] : '', $files));
        if (count($tops) === 1 && reset($tops) !== '') foreach ($files as &$f) $f['rel'] = substr($f['rel'], strlen(reset($tops)) + 1);
        unset($f);
        return $this->build($name, $files, fn($f) => fopen($f['tmp'], 'rb'));
    }

    /** @param callable $open fn(array $file): resource */
    private function build(string $name, array $files, callable $open): array
    {
        $id = $this->newId($name);
        $dir = self::root($this->k, $id);
        $pages = 0;
        foreach ($files as $f) {
            $rel = $this->cleanRel($f['rel']);
            if ($rel === null) continue;
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $isPage = in_array($ext, self::PAGE_EXT, true);
            if (!$isPage && !in_array($ext, self::ASSET_EXT, true)) continue;   // php, exe, etc. are silently ignored
            $target = $dir . ($isPage ? '/source/' : '/assets/') . $rel;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            $in = $open($f);
            if (!$in) continue;
            $out = fopen($target, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in); fclose($out);
            if ($isPage) $pages++;
        }
        if ($pages === 0) { ZipInstaller::rrmdir($dir); return ['id' => null, 'error' => 'لم يتم العثور على ملفات HTML في الرفع.']; }
        @mkdir($dir . '/templates', 0755, true);
        @mkdir($dir . '/assets', 0755, true);
        file_put_contents($dir . '/theme.json', json_encode(['id' => $id, 'name' => $name, 'version' => '1.0', 'parent' => 'default', 'imported' => true, 'author' => '', 'description' => 'موقع مستورد'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $site = ['files' => []];
        foreach ($this->files($id) as $rel) $site['files'][$rel] = ['assign' => ['kind' => 'none'], 'bindings' => [], 'title' => '', 'description' => ''];
        // sensible default: index.html -> home
        foreach (['index.html', 'index.htm', 'home.html'] as $h) if (isset($site['files'][$h])) { $site['files'][$h]['assign'] = ['kind' => 'home']; break; }
        $this->saveSite($id, $site);
        $this->generate($id);
        return ['id' => $id, 'error' => null];
    }

    private function newId(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $base = trim($base, '-');
        $base = $base !== '' ? 'site-' . substr($base, 0, 24) : 'site';
        $id = $base;
        while (is_dir(self::root($this->k, $id))) $id = $base . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        return $id;
    }

    private function cleanRel(string $rel): ?string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, "\0")) return null;
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || str_starts_with($seg, '.') || !preg_match('/^[\p{L}\p{N}._\-@() +,~]+$/u', $seg)) return null;
        }
        return $rel;
    }

    // ------------------------------------------------------------------ site model
    public function loadSite(string $id): array
    {
        $f = self::root($this->k, $id) . '/site.json';
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) && isset($d['files']) ? $d : ['files' => []];
    }

    public function saveSite(string $id, array $site): void
    {
        file_put_contents(self::root($this->k, $id) . '/site.json', json_encode($site, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @return string[] html files (relative), index first */
    public function files(string $id): array
    {
        $dir = self::root($this->k, $id) . '/source';
        $out = [];
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if ($f->isFile()) $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        }
        usort($out, fn($a, $b) => [str_contains($a, '/'), $a !== 'index.html', $a] <=> [str_contains($b, '/'), $b !== 'index.html', $b]);
        return $out;
    }

    // ------------------------------------------------------------------ parsing helpers
    /** @return array{0:\DOMDocument,1:array<int,\DOMElement>} document + body elements keyed by data-sp number */
    private function parse(string $id, string $rel): array
    {
        $file = self::root($this->k, $id) . '/source/' . $rel;
        $src = is_file($file) ? (string)file_get_contents($file) : '';
        $doc = (new HTML5(['disable_html_ns' => true]))->loadHTML($src);
        $sp = [];
        $n = 0;
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $x = new \DOMXPath($doc);
            foreach ($x->query('//body//*') as $el) $sp[++$n] = $el;
        }
        return [$doc, $sp];
    }

    /** Resolve a relative url from a page in $dir. @return array{kind:string,path:string,suffix:string}|null */
    private function resolve(string $u, string $dir): ?array
    {
        $u = trim($u);
        if ($u === './' || $u === '.' || $u === '/') return ['kind' => 'page', 'path' => '@home', 'suffix' => ''];
        if ($u === '' || $u[0] === '#' || str_starts_with($u, '__TK') || preg_match('#^([a-z][a-z0-9+.\-]*:|//)#i', $u)) return null;
        $suffix = '';
        if (preg_match('/^([^?#]*)([?#].*)?$/s', $u, $m)) { $u = $m[1]; $suffix = $m[2] ?? ''; }
        $path = str_starts_with($u, '/') ? ltrim($u, '/') : ($dir !== '' ? $dir . '/' : '') . $u;
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        $path = implode('/', $parts);
        if ($path === '') return null;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return ['kind' => in_array($ext, self::PAGE_EXT, true) ? 'page' : 'asset', 'path' => $path, 'suffix' => $suffix];
    }

    private function rewriteUrls(\DOMDocument $doc, string $fileDir, callable $asset, callable $page): void
    {
        $fix = function (string $u) use ($fileDir, $asset, $page): string {
            $r = $this->resolve($u, $fileDir);
            if (!$r) return $u;
            return $r['kind'] === 'page' ? $page($r['path'], $r['suffix']) : $asset($r['path'], $r['suffix']);
        };
        $css = fn(string $c) => preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', fn($m) => 'url(' . $m[1] . $fix($m[2]) . $m[1] . ')', $c);
        foreach (self::URL_ATTRS as $tag => $attrs) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                foreach ($attrs as $a) {
                    if (!$el->hasAttribute($a)) continue;
                    $v = $el->getAttribute($a);
                    if ($a === 'srcset') {
                        $parts = array_map(function ($p) use ($fix) { $p = trim($p); $b = preg_split('/\s+/', $p, 2); return $fix($b[0]) . (isset($b[1]) ? ' ' . $b[1] : ''); }, explode(',', $v));
                        $el->setAttribute($a, implode(', ', $parts));
                    } else {
                        $el->setAttribute($a, $fix($v));
                    }
                }
            }
        }
        foreach ((new \DOMXPath($doc))->query('//*[@style]') as $el) $el->setAttribute('style', $css($el->getAttribute('style')));
        foreach ($doc->getElementsByTagName('style') as $st) $st->textContent = $css($st->textContent);
    }

    /** Element tree of the page body (numbered like data-sp) for the mapper's layers panel. */
    public function tree(string $id, string $rel): array
    {
        [$doc, $sp] = $this->parse($id, $rel);
        $ids = [];
        foreach ($sp as $n => $el) $ids[spl_object_id($el)] = $n;
        $keep = ['href', 'src', 'alt', 'title', 'value', 'placeholder', 'content', 'action', 'name', 'type', 'poster', 'data-src', 'srcset'];
        $count = 0;
        $walk = function (\DOMElement $el) use (&$walk, $ids, $keep, &$count): ?array {
            if (++$count > 4000) return null;
            $own = '';
            foreach ($el->childNodes as $c) if ($c->nodeType === XML_TEXT_NODE) $own .= $c->textContent;
            $attrs = [];
            foreach ($keep as $k) if ($el->hasAttribute($k)) $attrs[$k] = mb_substr($el->getAttribute($k), 0, 80);
            $kids = [];
            foreach ($el->childNodes as $c) {
                if ($c instanceof \DOMElement && !in_array(strtolower($c->nodeName), ['script', 'style', 'noscript'], true)) { $k = $walk($c); if ($k) $kids[] = $k; }
            }
            return ['sp' => $ids[spl_object_id($el)] ?? 0, 'tag' => strtolower($el->nodeName), 'cls' => mb_substr((string)$el->getAttribute('class'), 0, 60), 'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', $own)), 0, 60), 'attrs' => $attrs, 'kids' => $kids];
        };
        $out = [];
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) foreach ($body->childNodes as $c) if ($c instanceof \DOMElement && !in_array(strtolower($c->nodeName), ['script', 'style', 'noscript'], true)) { $k = $walk($c); if ($k) $out[] = $k; }
        return $out;
    }

    // ------------------------------------------------------------------ JS-rendered pages: run once, keep the resulting DOM
    /** True when the page body is nearly empty but loads scripts (content is built by JS). */
    public function jsHeavy(string $id, string $rel): bool
    {
        [$doc] = $this->parse($id, $rel);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return false;
        $clone = $body->cloneNode(true);
        foreach (iterator_to_array($clone->getElementsByTagName('script')) as $s) $s->parentNode?->removeChild($s);
        $text = trim(preg_replace('/\s+/u', ' ', $clone->textContent));
        return mb_strlen($text) < 400 && $doc->getElementsByTagName('script')->length > 0;
    }

    /** Original page with scripts kept, for the sandboxed capture frame (assets absolute, storage shim + snapshot scripts injected). */
    public function renderHtml(string $id, string $rel): string
    {
        [$doc] = $this->parse($id, $rel);
        $base = $this->k->base . '/themes/' . $id . '/assets/';
        $this->rewriteUrls($doc, trim(dirname($rel), './'), fn($p, $s) => $base . $p . $s, fn($p, $s) => '#');
        foreach (iterator_to_array($doc->getElementsByTagName('base')) as $b) $b->parentNode?->removeChild($b);
        $head = $doc->getElementsByTagName('head')->item(0);
        $body = $doc->getElementsByTagName('body')->item(0);
        $v = '?v=' . Kernel::assetVersion();
        if ($head) {
            $pre = $doc->createElement('script'); $pre->setAttribute('src', $this->k->url('/admin/assets/snapshot-pre.js') . $v);
            $head->insertBefore($pre, $head->firstChild);
        }
        if ($body) {
            $s = $doc->createElement('script'); $s->setAttribute('src', $this->k->url('/admin/assets/snapshot.js') . $v); $s->setAttribute('data-sahab-snapshot', '1');
            $body->appendChild($s);
        }
        return (new HTML5())->saveHTML($doc);
    }

    /** Replace the page source by the rendered DOM. The original is kept in original/. Bindings of the file are reset. */
    public function snapshot(string $id, string $rel, string $html, bool $strip): void
    {
        $root = self::root($this->k, $id);
        $orig = $root . '/original/' . $rel;
        if (!is_file($orig)) {
            @mkdir(dirname($orig), 0755, true);
            @copy($root . '/source/' . $rel, $orig);
        }
        $doc = (new HTML5(['disable_html_ns' => true]))->loadHTML($html);
        foreach (iterator_to_array($doc->getElementsByTagName('script')) as $s) {
            $src = (string)$s->getAttribute('src');
            $isOurs = $s->hasAttribute('data-sahab-snapshot') || str_contains($src, 'snapshot-pre.js') || str_contains($src, 'snapshot.js');
            $keep = !$strip && !$isOurs || strtolower((string)$s->getAttribute('type')) === 'application/ld+json';
            if (!$keep) $s->parentNode?->removeChild($s);
        }
        $prefix = $this->k->base . '/themes/' . $id . '/assets/';
        $fix = fn(string $u) => str_starts_with($u, $prefix) ? '/' . substr($u, strlen($prefix)) : $u;
        foreach (self::URL_ATTRS as $tag => $attrs) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                foreach ($attrs as $a) {
                    if (!$el->hasAttribute($a)) continue;
                    $v = $el->getAttribute($a);
                    $el->setAttribute($a, $a === 'srcset' ? implode(', ', array_map(function ($p) use ($fix) { $b = preg_split('/\s+/', trim($p), 2); return $fix($b[0]) . (isset($b[1]) ? ' ' . $b[1] : ''); }, explode(',', $v))) : $fix($v));
                }
            }
        }
        file_put_contents($root . '/source/' . $rel, (new HTML5())->saveHTML($doc), LOCK_EX);
        $site = $this->loadSite($id);
        if (isset($site['files'][$rel])) { $site['files'][$rel]['bindings'] = []; $this->saveSite($id, $site); }
        $this->generate($id);
    }

    // ------------------------------------------------------------------ preview (mapper iframe)
    public function previewHtml(string $id, string $rel, ?int $only = null): string
    {
        [$doc, $sp] = $this->parse($id, $rel);
        foreach ($sp as $n => $el) $el->setAttribute('data-sp', (string)$n);
        if ($only !== null && isset($sp[$only])) {   // block mode: show just this element, keeping its ancestors so the site's CSS still applies
            $node = $sp[$only];
            while (($p = $node->parentNode) && !($p instanceof \DOMDocument) && strtolower($p->nodeName) !== 'html') {
                foreach (iterator_to_array($p->childNodes) as $c) if ($c !== $node) $p->removeChild($c);
                $node = $p;
            }
        }
        foreach (['script', 'noscript', 'base'] as $tag) foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) $el->parentNode?->removeChild($el);
        foreach ((new \DOMXPath($doc))->query('//*[@*[starts-with(name(),"on")]]') as $el) {
            foreach (iterator_to_array($el->attributes) as $at) if (str_starts_with($at->name, 'on')) $el->removeAttribute($at->name);
        }
        $base = $this->k->base . '/themes/' . $id . '/assets/';
        $this->rewriteUrls($doc, trim(dirname($rel), './'), fn($p, $s) => $base . $p . $s, fn($p, $s) => '#');
        $body = $doc->getElementsByTagName('body')->item(0);
        $head = $doc->getElementsByTagName('head')->item(0);
        if ($head) {
            $l = $doc->createElement('link'); $l->setAttribute('rel', 'stylesheet'); $l->setAttribute('href', $this->k->url('/admin/assets/mapper-frame.css') . '?v=' . Kernel::assetVersion()); $head->appendChild($l);
        }
        if ($body) {
            $s = $doc->createElement('script'); $s->setAttribute('src', $this->k->url('/admin/assets/mapper-frame.js') . '?v=' . Kernel::assetVersion()); $body->appendChild($s);
        }
        return (new HTML5())->saveHTML($doc);
    }

    /** Twig source of one element (with the page's bindings applied) to reuse as a "site block" in the designer. */
    public function compileElement(string $id, string $rel, int $sp): string
    {
        $site = $this->loadSite($id);
        return $this->compile($id, $rel, $site['files'][$rel] ?? ['bindings' => []], $site, 'page', $sp);
    }

    // ------------------------------------------------------------------ design system (CSS links, classes, variables)
    /** @return array{css:string[],htmlAttrs:array,bodyClass:string,wrap:?array,classes:string[],vars:string[]} */
    public function designSystem(string $id): array
    {
        $site = $this->loadSite($id);
        $ds = $site['design'] ?? $this->computeDesign($id, $site);
        $ds['css'] = array_map(fn($h) => str_starts_with($h, 'asset:') ? $this->k->url('/themes/' . $id . '/assets/' . substr($h, 6)) : $h, $ds['css'] ?? []);
        return $ds;
    }

    public static function harvest(string $css): array
    {
        preg_match_all('/\.(-?[_a-zA-Z][\w-]{1,40})(?=[\s{,:.>+~\[)])/', $css, $c);
        preg_match_all('/(--[a-zA-Z0-9_-]{1,40})\s*:/', $css, $v);
        return ['classes' => array_slice(array_values(array_unique($c[1])), 0, 400), 'vars' => array_slice(array_values(array_unique($v[1])), 0, 150)];
    }

    private function computeDesign(string $id, array $site): array
    {
        $root = self::root($this->k, $id);
        $base = $this->k->base . '/themes/' . $id . '/assets/';
        $file = null;
        foreach ($site['files'] ?? [] as $rel => $c) if (($c['assign']['kind'] ?? '') === 'home') { $file = $rel; break; }
        $file ??= array_key_first($site['files'] ?? []);
        $out = ['css' => [], 'htmlAttrs' => [], 'bodyClass' => '', 'wrap' => null, 'classes' => [], 'vars' => []];
        if ($file === null) return $out;
        [$doc] = $this->parse($id, $file);
        $this->rewriteUrls($doc, trim(dirname($file), './'), fn($p, $s) => 'asset:' . $p . $s, fn($p, $s) => '#');   // base-less: resolved when used, so moving the site keeps working
        foreach ($doc->getElementsByTagName('link') as $l) {
            if (stripos((string)$l->getAttribute('rel'), 'stylesheet') !== false && ($h = $l->getAttribute('href')) !== '') $out['css'][] = $h;
        }
        $html = $doc->getElementsByTagName('html')->item(0);
        if ($html) foreach ($html->attributes as $at) if (in_array($at->name, ['lang', 'dir'], true) || str_starts_with($at->name, 'data-')) $out['htmlAttrs'][$at->name] = mb_substr($at->value, 0, 60);
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $out['bodyClass'] = mb_substr((string)$body->getAttribute('class'), 0, 120);
            foreach ($body->childNodes as $c) {
                if (!($c instanceof \DOMElement) || in_array(strtolower($c->nodeName), ['script', 'noscript', 'header', 'main', 'footer', 'style'], true)) continue;
                if ($c->getElementsByTagName('main')->length) { $out['wrap'] = ['tag' => in_array(strtolower($c->nodeName), ['div', 'section', 'article'], true) ? strtolower($c->nodeName) : 'div', 'class' => mb_substr((string)$c->getAttribute('class'), 0, 120)]; break; }
            }
        }
        $bytes = 0;
        foreach ($out['css'] as $href) {
            if (!str_starts_with($href, 'asset:')) continue;
            $f = $root . '/assets/' . preg_replace('/[?#].*$/', '', substr($href, 6));
            if (!is_file($f) || ($bytes += filesize($f)) > 800000) continue;
            $h = self::harvest((string)file_get_contents($f));
            $out['classes'] = array_values(array_unique(array_merge($out['classes'], $h['classes'])));
            $out['vars'] = array_values(array_unique(array_merge($out['vars'], $h['vars'])));
        }
        $out['classes'] = array_slice($out['classes'], 0, 400);
        return $out;
    }

    // ------------------------------------------------------------------ generation
    public function generate(string $id): void
    {
        $root = self::root($this->k, $id);
        $tpl = $root . '/templates';
        if (is_dir($tpl)) foreach (glob($tpl . '/*.twig') ?: [] as $f) @unlink($f);
        @mkdir($tpl, 0755, true);
        $site = $this->loadSite($id);
        $slugs = array_keys($this->types());
        foreach ($site['files'] as $rel => $cfg) {
            $a = $cfg['assign'] ?? ['kind' => 'none'];
            $name = match ($a['kind'] ?? 'none') {
                'home' => 'home',
                'archive' => in_array($a['type'] ?? '', $slugs, true) ? 'archive-' . $a['type'] : null,
                'single' => in_array($a['type'] ?? '', $slugs, true) ? 'single-' . $a['type'] : null,
                'static' => preg_match('/^[\p{L}\p{N}_-]{1,60}$/u', (string)($a['slug'] ?? '')) ? 'static-' . $a['slug'] : null,
                default => null,
            };
            if (!$name) continue;
            try { file_put_contents("$tpl/$name.twig", $this->compile($id, (string)$rel, $cfg, $site)); } catch (\Throwable $e) {
                @file_put_contents($this->k->root . '/storage/logs/error.log', '[site ' . $id . '] ' . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
        // The designer renders inside the site's own layout (head/CSS/header/footer), so designs look like the site.
        $site['design'] = $this->computeDesign($id, $site);
        $this->saveSite($id, $site);
        $dz = null;
        $order = array_keys($site['files']);
        usort($order, fn($x, $y) => (($site['files'][$y]['assign']['kind'] ?? '') === 'home') <=> (($site['files'][$x]['assign']['kind'] ?? '') === 'home'));
        foreach ($order as $rel) {
            try { $dz = $this->compile($id, (string)$rel, $site['files'][$rel], $site, 'design'); } catch (\Throwable) { $dz = null; }
            if ($dz) break;
        }
        file_put_contents("$tpl/design.twig", $dz ?: '<!doctype html><html lang="{{ site.lang }}" dir="{{ site.dir }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ title }}</title><meta name="description" content="{{ description }}">{{ design.css }}{{ hook(\'head\') }}</head><body>{{ design.html }}{{ hook(\'footer\') }}</body></html>');
    }

    private function types(): array { return (new Content($this->k))->types(); }

    /** file => public route for the pages that have one (home, archive, static). */
    public static function routesOf(array $site): array
    {
        $routes = [];
        foreach ($site['files'] ?? [] as $f => $c) {
            $a = $c['assign'] ?? [];
            $routes[$f] = match ($a['kind'] ?? '') { 'home' => '/', 'archive' => '/' . ($a['type'] ?? ''), 'static' => '/' . ($a['slug'] ?? ''), default => null };
        }
        return $routes;
    }

    /** Optional output format for text bindings. */
    private static function fmt(string $f): string
    {
        return match ($f) { 'excerpt' => '|excerpt(140)', 'excerpt_long' => '|excerpt(320)', 'date' => '|ar_date', 'nl2br' => '|nl2br', default => '' };
    }

    private bool $allowBlock = false;

    private function expr(string $src): ?string
    {
        if ($this->allowBlock && preg_match('/^block(\.[a-z][a-z0-9_]{0,30}){1,2}$/', $src)) return $src;   // a block's own field (block mode only)
        if ($src === 'year') return "'now'|date('Y')";
        if (preg_match('/^(entry|it)(\.[a-z][a-z0-9_]{0,40}){1,2}$/', $src) || preg_match('/^site\.(title|description|url|lang)$/', $src)) return $src;
        return null;
    }

    /** @param string $mode 'page' (normal template) | 'design' (layout with the designer's output in <main>) */
    private function compile(string $id, string $rel, array $cfg, array $site, string $mode = 'page', ?int $onlySp = null): string
    {
        $this->allowBlock = $onlySp !== null;
        [$doc, $sp] = $this->parse($id, $rel);
        $types = $this->types();
        $tokens = [];       // token => twig code
        $mk = function (string $code) use (&$tokens): string { $t = '__TK' . count($tokens) . '__'; $tokens[$t] = $code; return $t; };
        $htmlEl = $doc->getElementsByTagName('html')->item(0);   // the document follows the site language: <html lang dir>
        if ($htmlEl instanceof \DOMElement) { $htmlEl->setAttribute('lang', $mk('{{ site.lang }}')); $htmlEl->setAttribute('dir', $mk('{{ site.dir }}')); }
        $routes = self::routesOf($site);
        $bindings = array_values(array_filter((array)($cfg['bindings'] ?? []), 'is_array'));
        $order = ['text' => 1, 'html' => 1, 'attr' => 1, 'bgimage' => 1, 'form' => 1, 'menu' => 2, 'repeat' => 3, 'remove' => 4];
        usort($bindings, fn($a, $b) => ($order[$a['kind'] ?? ''] ?? 9) <=> ($order[$b['kind'] ?? ''] ?? 9));

        foreach ($bindings as $b) {
            $el = $sp[(int)($b['sp'] ?? 0)] ?? null;
            if (!$el || !$el->parentNode) continue;
            $kind = (string)($b['kind'] ?? '');
            $e = $this->expr((string)($b['src'] ?? ''));
            switch ($kind) {
                case 'text':
                    if (!$e) break;
                    $this->setText($doc, $el, $mk('{{ ' . $e . self::fmt($b['fmt'] ?? '') . ' }}'));
                    break;
                case 'html':
                    if (!$e) break;
                    while ($el->firstChild) $el->removeChild($el->firstChild);
                    $el->appendChild($doc->createTextNode($mk('{{ ' . $e . self::fmt($b['fmt'] ?? '') . ' }}')));
                    break;
                case 'attr':
                    $attr = strtolower((string)($b['attr'] ?? ''));
                    if (preg_match('/^page:(.+\.html?)$/', (string)($b['src'] ?? ''), $pm) && preg_match('/^[a-z][a-z0-9\-:]{0,30}$/', $attr) && !str_starts_with($attr, 'on') && ($route = $routes[$pm[1]] ?? null) !== null) {
                        $el->setAttribute($attr, $mk("{{ url('" . addslashes($route) . "') }}"));
                        break;
                    }
                    if (!$e || !preg_match('/^[a-z][a-z0-9\-:]{0,30}$/', $attr) || str_starts_with($attr, 'on') || $attr === 'style') break;
                    $isUrl = in_array($attr, ['href', 'src', 'action', 'formaction', 'poster', 'data-src', 'srcset'], true);
                    if (str_starts_with($e, 'block.')) {   // a block field: keep the original value until the field is filled in
                        $orig = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$el->getAttribute($attr));
                        $el->setAttribute($attr, $mk("{{ ($e ?: '$orig')" . ($isUrl ? '|safe_url' : '') . ' }}'));
                        break;
                    }
                    $el->setAttribute($attr, $mk('{{ ' . $e . ($isUrl ? '|safe_url' : '') . ' }}'));
                    if ($attr === 'src' && strtolower($el->nodeName) === 'img' && $el->parentNode) {   // no image in the entry: don't output a broken <img>
                        $el->parentNode->insertBefore($el->ownerDocument->createTextNode($mk('{% if ' . $e . ' %}')), $el);
                        $el->parentNode->insertBefore($el->ownerDocument->createTextNode($mk('{% endif %}')), $el->nextSibling);
                    }
                    break;
                case 'bgimage':
                    if (!$e) break;
                    $style = preg_replace('/background(-image)?\s*:[^;]*;?/i', '', (string)$el->getAttribute('style'));
                    $el->setAttribute('style', trim($style . ";background-image:url('" . $mk('{{ ' . $e . '|safe_url }}') . "');background-size:cover;", ';'));
                    break;
                case 'form':
                    if (strtolower($el->nodeName) !== 'form' || !preg_match('/^[a-z0-9][a-z0-9_\-]{0,40}$/', (string)($b['form'] ?? ''))) break;
                    $el->setAttribute('method', 'post');
                    $el->setAttribute('action', $mk("{{ url('/forms/" . $b['form'] . "') }}"));
                    $msg = $doc->createTextNode($mk("{% set _f = form_data('" . $b['form'] . "') %}{% if _f and _f.message %}<div class=\"sahab-form-msg\" role=\"status\" style=\"padding:.7rem 1rem;margin-bottom:1rem;border:1px solid currentColor\">{{ _f.message.text }}</div>{% endif %}{% for _e in (_f ? _f.errors : []) %}<div class=\"sahab-form-msg err\" role=\"alert\" style=\"padding:.7rem 1rem;margin-bottom:1rem;border:1px solid #b91c1c;color:#b91c1c\">{{ _e }}</div>{% endfor %}"));
                    $el->parentNode->insertBefore($msg, $el);
                    $h = $doc->createElement('input'); $h->setAttribute('type', 'hidden'); $h->setAttribute('name', '_csrf'); $h->setAttribute('value', $mk('{{ csrf_token() }}')); $el->appendChild($h);
                    $hp = $doc->createElement('input'); $hp->setAttribute('type', 'text'); $hp->setAttribute('name', 'website_url'); $hp->setAttribute('tabindex', '-1'); $hp->setAttribute('autocomplete', 'off'); $hp->setAttribute('style', 'position:absolute;left:-9999px'); $el->appendChild($hp);
                    break;
                case 'menu':
                    $this->menu($doc, $el, (string)($b['menu'] ?? 'main'), $mk);
                    break;
                case 'repeat':
                    $this->repeat($doc, $el, $b, $types, $mk);
                    break;
                case 'remove':
                    $el->parentNode->removeChild($el);
                    break;
            }
        }
        // <title> and meta description
        foreach (['title' => 'title', 'description' => 'description'] as $key => $_) {
            $ex = $this->expr((string)($cfg[$key] ?? ''));
            if (!$ex) continue;
            if ($key === 'title' && ($t = $doc->getElementsByTagName('title')->item(0))) $t->textContent = $mk('{{ ' . $ex . ' }}');
            if ($key === 'description' && ($head = $doc->getElementsByTagName('head')->item(0))) {
                $m = null;
                foreach ($doc->getElementsByTagName('meta') as $mt) if (strtolower($mt->getAttribute('name')) === 'description') $m = $mt;
                if (!$m) { $m = $doc->createElement('meta'); $m->setAttribute('name', 'description'); $head->appendChild($m); }
                $m->setAttribute('content', $mk('{{ ' . $ex . ' }}'));
            }
        }
        // urls -> asset()/url() tokens
        $this->rewriteUrls($doc, trim(dirname($rel), './'),
            fn($p, $s) => $mk("{{ asset('" . addslashes($p) . "') }}") . $s,
            function ($p, $s) use ($routes, $mk) { $r = $p === '@home' ? '/' : ($routes[$p] ?? null); return $r === null ? '#' : $mk("{{ url('" . addslashes($r) . "') }}") . $s; });

        if ($mode === 'design') {
            $x = new \DOMXPath($doc);
            $main = $x->query('//main')->item(0) ?? $x->query('//*[@role="main"]')->item(0);
            if (!$main) return '';   // no <main>: this page cannot serve as the design layout
            while ($main->firstChild) $main->removeChild($main->firstChild);
            $main->appendChild($doc->createTextNode($mk('{{ design.html }}')));
            if ($t = $doc->getElementsByTagName('title')->item(0)) $t->textContent = $mk('{{ title }}');
            if ($head = $doc->getElementsByTagName('head')->item(0)) {
                $m = null;
                foreach ($doc->getElementsByTagName('meta') as $mt) if (strtolower($mt->getAttribute('name')) === 'description') $m = $mt;
                if (!$m) { $m = $doc->createElement('meta'); $m->setAttribute('name', 'description'); $head->appendChild($m); }
                $m->setAttribute('content', $mk('{{ description }}'));
                $head->appendChild($doc->createTextNode($mk("{{ design.css }}{{ hook('head') }}")));
            }
            if ($body = $doc->getElementsByTagName('body')->item(0)) $body->appendChild($doc->createTextNode($mk("{{ hook('footer') }}")));
        }
        if ($onlySp !== null) {
            $el = $sp[$onlySp] ?? null;
            if (!$el || !$el->parentNode) return '';
            $html = (new HTML5())->saveHTML($el);
        } else {
            $html = (new HTML5())->saveHTML($doc);
        }
        // neutralise any Twig syntax that existed in the source, then inject ours
        $html = str_replace(['{{', '{%', '{#'], ["{{ '{{' }}", "{{ '{%' }}", "{{ '{#' }}"], $html);
        return strtr($html, $tokens);
    }

    private function setText(\DOMDocument $doc, \DOMElement $el, string $tok): void
    {
        $done = false;
        foreach (iterator_to_array($el->childNodes) as $n) {
            if ($n->nodeType !== XML_TEXT_NODE) continue;
            if (!$done && trim($n->textContent) !== '') { $n->textContent = $tok; $done = true; }
            elseif (trim($n->textContent) !== '' || $done) $el->removeChild($n);
        }
        if (!$done) $el->appendChild($doc->createTextNode($tok));
    }

    private function menu(\DOMDocument $doc, \DOMElement $el, string $name, callable $mk): void
    {
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $name)) return;
        $tpl = null;
        if (in_array(strtolower($el->nodeName), ['li', 'a'], true)) $tpl = $el;
        else { $x = new \DOMXPath($doc); $r = $x->query('.//li', $el); $tpl = $r->length ? $r->item(0) : ($x->query('.//a', $el)->item(0)); }
        if (!$tpl || !$tpl->parentNode) return;
        $a = strtolower($tpl->nodeName) === 'a' ? $tpl : (new \DOMXPath($doc))->query('.//a', $tpl)->item(0);
        if ($a) { $a->setAttribute('href', $mk('{{ m.url }}')); $this->setText($doc, $a, $mk('{{ m.label }}')); }
        $p = $tpl->parentNode;
        for ($n = $tpl->nextSibling; $n;) { $nx = $n->nextSibling; if ($n->nodeType === XML_ELEMENT_NODE) $p->removeChild($n); $n = $nx; }
        $p->insertBefore($doc->createTextNode($mk("{% for m in menu('$name') %}")), $tpl);
        $p->appendChild($doc->createTextNode($mk('{% endfor %}')));
    }

    private function repeat(\DOMDocument $doc, \DOMElement $el, array $b, array $types, callable $mk): void
    {
        $src = (string)($b['src'] ?? '');
        if ($src === 'items') $loop = '{% for it in items %}';
        else {
            $t = (string)($b['rtype'] ?? '');
            if (!isset($types[$t])) return;
            $limit = max(1, min(50, (int)($b['limit'] ?? 6)));
            $order = (string)($b['order'] ?? '');
            $o = preg_match('/^[a-z_][a-z0-9_]*(:(asc|desc))?$/', $order) ? ", order: '$order'" : '';
            $loop = "{% for it in entries('$t', {limit: $limit$o}) %}";
        }
        $p = $el->parentNode;
        if (!empty($b['dedupe'])) {
            $cls = $el->getAttribute('class');
            for ($n = $el->nextSibling; $n;) {
                $nx = $n->nextSibling;
                if ($n->nodeType === XML_ELEMENT_NODE) {
                    if ($n->nodeName === $el->nodeName && $n->getAttribute('class') === $cls) $p->removeChild($n); else break;
                }
                $n = $nx;
            }
        }
        $p->insertBefore($doc->createTextNode($mk($loop)), $el);
        $el->nextSibling ? $p->insertBefore($doc->createTextNode($mk('{% endfor %}')), $el->nextSibling) : $p->appendChild($doc->createTextNode($mk('{% endfor %}')));
    }
}
