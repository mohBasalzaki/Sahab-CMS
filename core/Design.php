<?php
declare(strict_types=1);

namespace Sahab;

/**
 * Visual page builder renderer (Webflow / Ycode style).
 * A design is a JSON tree of elements; every element has props (content) and style[state][css-prop] (values are
 * validated against a whitelist before being emitted, so stored designs can never inject script or arbitrary CSS).
 * States: desktop (base), tablet (<=991px), mobile (<=767px), hover.
 */
final class Design
{
    public const BREAKPOINTS = ['tablet' => '(max-width: 991px)', 'mobile' => '(max-width: 767px)'];
    public const TAGS = ['section', 'header', 'footer', 'nav', 'main', 'div', 'aside', 'article'];
    private const MAX_NODES = 1500;
    private const MAX_DEPTH = 24;

    private const LEN = '-?\d+(?:\.\d+)?(?:px|%|rem|em|vh|vw|fr|ch)?|auto|0|var\(--[a-z0-9_-]{1,40}\)';
    private const COLOR = '#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*(?:,\s*\d{1,3}\s*){2}(?:,\s*(?:0|1|0?\.\d+)\s*)?\)|transparent|currentColor|var\(--[a-z0-9_-]{1,40}\)|[a-zA-Z]{3,20}';

    private const ENUMS = [
        'display' => ['block', 'flex', 'grid', 'inline-block', 'inline-flex', 'none'],
        'flex-direction' => ['row', 'column', 'row-reverse', 'column-reverse'],
        'flex-wrap' => ['nowrap', 'wrap'],
        'justify-content' => ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'],
        'align-items' => ['stretch', 'flex-start', 'center', 'flex-end', 'baseline'],
        'text-align' => ['start', 'center', 'end', 'left', 'right', 'justify'],
        'text-transform' => ['none', 'uppercase', 'lowercase', 'capitalize'],
        'text-decoration' => ['none', 'underline', 'line-through'],
        'font-style' => ['normal', 'italic'],
        'border-style' => ['none', 'solid', 'dashed', 'dotted'],
        'background-size' => ['cover', 'contain', 'auto'],
        'background-position' => ['center', 'top', 'bottom', 'left', 'right', 'center top', 'center bottom'],
        'background-repeat' => ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'],
        'overflow' => ['visible', 'hidden', 'auto'],
        'position' => ['static', 'relative', 'sticky'],
        'object-fit' => ['cover', 'contain', 'fill'],
        'cursor' => ['auto', 'pointer'],
    ];
    private const BOXES = ['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'border-radius'];
    private const LENS = ['gap', 'row-gap', 'column-gap', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height', 'font-size', 'letter-spacing', 'border-width', 'top', 'bottom', 'left', 'right', 'z-index'];
    private const COLORS = ['color', 'background-color', 'border-color'];
    private const SHADOWS = ['sm' => '0 1px 2px rgba(0,0,0,.12)', 'md' => '0 4px 12px rgba(0,0,0,.14)', 'lg' => '0 12px 32px rgba(0,0,0,.18)'];

    private int $count = 0;
    /** @var array<string,string> css rules by element class (property so nested closures share it) */
    private array $css = [];

    /** @var callable|null renders a site block's Twig source: fn(string $twig, array $ctx): string */
    private $twigBlock = null;
    /** @var array<int,array|null> */
    private array $blocks = [];
    private array $stack = [];

    public function __construct(private Kernel $k, private Content $content) {}

    public function withTwig(callable $fn): self { $this->twigBlock = $fn; return $this; }

    private function block(int $id): ?array
    {
        if (!array_key_exists($id, $this->blocks)) {
            $this->blocks[$id] = $id > 0 ? ($this->k->db->one("SELECT id, kind, type, title, tree_json, meta_json FROM designs WHERE id = ? AND kind IN ('block', 'siteblock')", [$id]) ?: null) : null;
        }
        return $this->blocks[$id];
    }

    // ------------------------------------------------------------------ defaults
    public static function id(): string { return 'e' . substr(bin2hex(random_bytes(4)), 0, 6); }

    public static function defaultTree(string $kind, ?array $type = null): array
    {
        $kids = [
            ['id' => self::id(), 'type' => 'heading', 'props' => ['text' => $kind === 'page' ? 'Title' : '{{title}}', 'level' => 1],
                'style' => ['desktop' => ['font-size' => '40px', 'font-weight' => '700', 'line-height' => '1.4']], 'children' => []],
            ['id' => self::id(), 'type' => 'text', 'props' => ['text' => '{{published_at}}'],
                'style' => ['desktop' => ['color' => '#4a4870', 'margin-top' => '12px']], 'children' => []],
        ];
        foreach ((array)($type['fields'] ?? []) as $f) {
            if ($f['type'] === 'media') $kids[] = ['id' => self::id(), 'type' => 'image', 'props' => ['bind' => $f['name']], 'style' => ['desktop' => ['margin-top' => '24px']], 'children' => []];
        }
        foreach ((array)($type['fields'] ?? []) as $f) {
            if ($f['type'] === 'richtext') { $kids[] = ['id' => self::id(), 'type' => 'richtext', 'props' => ['bind' => $f['name']], 'style' => ['desktop' => ['margin-top' => '24px']], 'children' => []]; break; }
        }
        $container = ['id' => self::id(), 'type' => 'container', 'props' => [],
            'style' => ['desktop' => ['max-width' => '1100px', 'margin-left' => 'auto', 'margin-right' => 'auto', 'padding-left' => '16px', 'padding-right' => '16px']], 'children' => $kids];
        $section = ['id' => self::id(), 'type' => 'section', 'props' => ['tag' => 'section'],
            'style' => ['desktop' => ['padding-top' => '64px', 'padding-bottom' => '64px'], 'mobile' => ['padding-top' => '32px', 'padding-bottom' => '32px']], 'children' => [$container]];
        return ['id' => 'root', 'type' => 'body', 'props' => [], 'style' => ['desktop' => []], 'children' => [$section]];
    }
    /** Decode + loosely validate a stored/posted tree. */
    public static function normalize(mixed $tree): array
    {
        if (is_string($tree)) $tree = json_decode($tree, true);
        if (!is_array($tree) || !isset($tree['children'])) return self::defaultTree('page');
        return $tree + ['id' => 'root', 'type' => 'body', 'props' => [], 'style' => []];
    }

    // ------------------------------------------------------------------ render
    /**
     * @param array $ctx ['entry' => array, 'type' => string]
     * @return array{html:string,css:string}
     */
    public function render(array $tree, array $ctx = [], bool $editor = false): array
    {
        $this->count = 0;
        $this->css = [];
        $html = '';
        foreach ((array)($tree['children'] ?? []) as $n) $html .= $this->node($n, $ctx, $editor, 1);
        $rootCss = $this->rules('.dz', (array)($tree['style'] ?? []));
        return ['html' => '<div class="dz">' . $html . '</div>', 'css' => self::BASE_CSS . $rootCss . implode('', $this->css)];
    }

    private const BASE_CSS = '.dz{display:block}.dz *,.dz *::before,.dz *::after{box-sizing:border-box}.dz h1,.dz h2,.dz h3,.dz h4,.dz h5,.dz h6,.dz p{margin:0}.dz img{max-width:100%;display:block}.dz a{color:inherit}'
        . '.dz .dz-btn{display:inline-block;text-decoration:none;cursor:pointer}.dz .dz-block{display:contents}';

    private function node(array $n, array $ctx, bool $editor, int $depth): string
    {
        if (++$this->count > self::MAX_NODES || $depth > self::MAX_DEPTH) return '';
        $type = (string)($n['type'] ?? 'div');
        $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($n['id'] ?? ''))) ?: self::id();
        $p = (array)($n['props'] ?? []);
        $cls = 'e-' . $id;
        if (!isset($this->css[$cls])) $this->css[$cls] = $this->rules('.dz .' . $cls, (array)($n['style'] ?? []));
        $extra = preg_match_all('/[A-Za-z_][A-Za-z0-9_-]{0,40}/', (string)($p['class'] ?? ''), $cm) ? ' ' . implode(' ', array_slice($cm[0], 0, 8)) : '';
        $attr = ' class="' . $cls . ($type === 'button' ? ' dz-btn' : '') . ($type === 'block' ? ' dz-block' : '') . $extra . '"' . ($editor ? ' data-eid="' . $id . '" data-etype="' . htmlspecialchars($type, ENT_QUOTES) . '"' : '');
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $kids = fn(array $c) => implode('', array_map(fn($ch) => $this->node((array)$ch, $c, $editor, $depth + 1), (array)($n['children'] ?? [])));

        switch ($type) {
            case 'section': case 'container': case 'div':
                $tag = in_array($p['tag'] ?? '', self::TAGS, true) ? $p['tag'] : 'div';
                return "<$tag$attr>" . $kids($ctx) . "</$tag>";
            case 'heading':
                $lv = max(1, min(6, (int)($p['level'] ?? 2)));
                return "<h$lv$attr>" . $this->tokens((string)($p['text'] ?? ''), $ctx) . "</h$lv>";
            case 'text':
                return "<p$attr>" . nl2br($this->tokens((string)($p['text'] ?? ''), $ctx)) . '</p>';
            case 'richtext':
                $html = '';
                $bv = !empty($p['bind']) ? $this->lookup((string)$p['bind'], $ctx) : null;
                if ($bv !== null && !is_array($bv)) $html = Html::clean((string)$bv);
                elseif (!empty($p['html'])) $html = Html::clean((string)$p['html']);
                return "<div$attr>$html</div>";
            case 'image':
                $src = $this->imageSrc($p, $ctx);
                if ($src === '') { if (!$editor) return ''; $src = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360"><rect width="100%" height="100%" fill="#f1eeee"/><text x="50%" y="50%" fill="#8d8ab8" font-family="monospace" font-size="20" text-anchor="middle">[ image ]</text></svg>'); }
                return '<img' . $attr . ' src="' . $e($src) . '" alt="' . $this->tokens((string)($p['alt'] ?? ''), $ctx) . '" loading="lazy">';
            case 'button': case 'link':
                $href = $this->href($this->tokens((string)($p['href'] ?? '#'), $ctx, false));
                $tgt = !empty($p['newtab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
                return '<a' . $attr . ' href="' . $e($href) . '"' . $tgt . '>' . $this->tokens((string)($p['text'] ?? ''), $ctx) . $kids($ctx) . '</a>';
            case 'form':
                if ($editor) return "<div$attr style=\"padding:16px;border:1px dashed #8d8ab8\">[ form: " . $e($p['form'] ?? '?') . ' ]</div>';
                $forms = new Forms($this->k);
                $f = $forms->get((string)($p['form'] ?? ''));
                return "<div$attr id=\"form-" . $e($p['form'] ?? '') . '">' . ($f ? $forms->render($f) : '') . '</div>';
            case 'spacer':
                return "<div$attr></div>";
            case 'list':
                return $this->listNode($n, $p, $attr, $ctx, $editor, $depth);
            case 'block':
                return $this->blockNode($p, $attr, $ctx, $editor, $depth);
        }
        return '';
    }

    /** A reusable block: a design subtree (rendered with the current data context) or site HTML/Twig compiled by the importer. */
    private function blockNode(array $p, string $attr, array $ctx, bool $editor, int $depth): string
    {
        $id = (int)($p['ref'] ?? 0);
        $row = $this->block($id);
        if (!$row) return $editor ? "<div$attr>[ block #$id ]</div>" : '';
        if (in_array($id, $this->stack, true) || count($this->stack) >= 6) return '';
        $inner = '';
        $this->stack[] = $id;
        if ($row['kind'] === 'block') {
            $tree = self::normalize($row['tree_json']);
            $c2 = $ctx; $c2['block'] = $this->blockValues($row, $p, $ctx);
            foreach ((array)($tree['children'] ?? []) as $ch) $inner .= $this->node((array)$ch, $c2, $editor, $depth + 1);
        } elseif ($this->twigBlock) {
            $vals = $this->blockValues($row, $p, $ctx);   // the block's own fields: {{ data.key }} (alias {{ block.key }})
            try { $inner = (string)($this->twigBlock)((string)$row['tree_json'], ['entry' => $ctx['entry'] ?? [], 'type' => $ctx['type'] ?? '', 'data' => $vals, 'block' => $vals, 'section' => ['id' => $id]]); } catch (\Throwable $e) { $inner = $editor ? '<!-- block error -->' : ''; }
            $css = trim((string)((json_decode((string)($row['meta_json'] ?? ''), true) ?: [])['css'] ?? ''));
            if ($css !== '') {
                $this->css['sbk' . $id] = '.dz .dz-block.sbk-' . $id . '{display:block;' . self::safeCss($css) . '}';
                $attr = preg_replace('/class="/', 'class="sbk-' . $id . ' ', $attr, 1);
            }
        }
        array_pop($this->stack);
        if ($editor && trim(strip_tags($inner, '<img><svg><video><iframe><input><button>')) === '') $inner .= '<div class="dz-empty-block">[ كتلة: ' . htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') . ' ]</div>';
        return "<div$attr>$inner</div>";
    }

    /** Field values of one block instance: static value, or "@field" = taken from the current entry / list item. */
    private function blockValues(array $row, array $p, array $ctx): array
    {
        $meta = json_decode((string)($row['meta_json'] ?? ''), true) ?: [];
        $vals = [];
        foreach ((array)($meta['fields'] ?? []) as $f) {
            $n = (string)($f['name'] ?? '');
            if ($n === '') continue;
            $raw = $p['values'][$n] ?? ($f['default'] ?? '');
            if (is_string($raw) && str_starts_with($raw, '@')) $raw = $ctx['entry'][substr($raw, 1)] ?? '';
            $type = (string)($f['type'] ?? 'text');
            if ($type === 'image' && is_scalar($raw) && ctype_digit((string)$raw)) { $m = (new Media($this->k))->find((int)$raw); $raw = $m ?: ''; }
            elseif ($type === 'richtext') $raw = Html::clean((string)(is_array($raw) ? '' : $raw));
            elseif ($type === 'boolean') $raw = !empty($raw) && $raw !== '0';
            elseif ($type === 'repeater') {
                $rows = is_array($raw) ? $raw : [];
                $raw = [];
                foreach (array_slice($rows, 0, 50) as $r) {
                    if (!is_array($r)) continue;
                    $o = [];
                    foreach ((array)($f['fields'] ?? []) as $sf) {
                        $sn = (string)($sf['name'] ?? '');
                        if ($sn === '') continue;
                        $sv = $r[$sn] ?? '';
                        $o[$sn] = ($sf['type'] ?? '') === 'image' && is_scalar($sv) && ctype_digit((string)$sv) ? ((new Media($this->k))->find((int)$sv) ?: '') : (($sf['type'] ?? '') === 'richtext' ? Html::clean((string)$sv) : (is_scalar($sv) ? $sv : ''));
                    }
                    $raw[] = $o;
                }
            }
            elseif (is_array($raw) && $type !== 'image') $raw = (string)($raw['url'] ?? ($raw['title'] ?? ''));
            $vals[$n] = $raw;
        }
        return $vals;
    }

    /** Author CSS for a section block (nested under its scope class): drop things that load or execute. */
    public static function safeCss(string $css): string
    {
        $css = preg_replace('~</?style[^>]*>|@import[^;]*;?|expression\s*\(|javascript\s*:|behavio?r\s*:|-moz-binding~i', '', $css) ?? '';
        return str_replace(['<', '>'], '', mb_substr($css, 0, 60000));
    }

    /** Block field values for previews inside the block's own editor (defaults). */
    public function valuesFor(array $fields, array $values, array $ctx): array
    {
        return $this->blockValues(['meta_json' => json_encode(['fields' => $fields])], ['values' => $values], $ctx);
    }

    private function listNode(array $n, array $p, string $attr, array $ctx, bool $editor, int $depth): string
    {
        $t = $this->content->type((string)($p['type'] ?? ''));
        $out = '';
        $items = [];
        if ($t) {
            $o = ['limit' => max(1, min(50, (int)($p['limit'] ?? 6)))];
            if (!empty($p['order'])) $o['order'] = (string)$p['order'];
            $items = $this->content->list($t, $o)['items'];
        }
        $sets = $items ? array_map(fn($it) => ['entry' => $it, 'type' => $t['slug']], $items) : ($editor ? [['entry' => [], 'type' => $t['slug'] ?? '']] : []);
        foreach ($sets as $c) {
            foreach ((array)($n['children'] ?? []) as $ch) $out .= $this->node((array)$ch, $c, $editor, $depth + 1);
        }
        if (!$items && !$editor && !empty($p['empty'])) $out = '<p>' . htmlspecialchars((string)$p['empty'], ENT_QUOTES, 'UTF-8') . '</p>';
        return "<div$attr>$out</div>";
    }

    // ------------------------------------------------------------------ helpers
    /** Replace {{field}} tokens with HTML-escaped values from the entry context. */
    private function tokens(string $s, array $ctx, bool $escape = true): string
    {
        $out = preg_replace_callback('/\{\{\s*([a-z0-9_]+(?:\.[a-z0-9_]+)?)\s*\}\}/i', function ($m) use ($ctx) {
            $v = $this->lookup($m[1], $ctx) ?? '';
            if (is_array($v)) $v = $v['url'] ?? ($v['title'] ?? '');
            elseif (is_bool($v)) $v = $v ? 'نعم' : 'لا';
            elseif ($v instanceof \Stringable) $v = Str::excerpt((string)$v, 400);
            elseif (in_array($m[1], ['created_at', 'updated_at', 'published_at'], true) && $v) $v = Str::arDate((string)$v);
            elseif (is_string($v) && str_contains($v, '<')) $v = Str::excerpt($v, 400);
            return (string)$v;
        }, $s);
        return $escape ? htmlspecialchars((string)$out, ENT_QUOTES, 'UTF-8') : (string)$out;
    }

    /** Value of "field" (entry field) or "block.field" (the current block instance's own fields). */
    private function lookup(string $name, array $ctx): mixed
    {
        return str_starts_with($name, 'block.') ? ($ctx['block'][substr($name, 6)] ?? null) : ($ctx['entry'][$name] ?? null);
    }

    private function href(string $u): string
    {
        $u = trim(preg_replace('/[\x00-\x20]+/', '', $u));
        if ($u === '') return '#';
        if (preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $u)) return str_starts_with($u, '/') && !str_starts_with($u, '//') ? $this->k->url($u) : $u;
        return preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u) ? '#' : $this->k->url($u);
    }

    private function imageSrc(array $p, array $ctx): string
    {
        if (!empty($p['bind'])) {
            $bv = $this->lookup((string)$p['bind'], $ctx);
            if (is_array($bv)) return (string)($bv['url'] ?? '');
            if (is_scalar($bv) && ctype_digit((string)$bv)) { $m = (new Media($this->k))->find((int)$bv); if ($m) return $m['url']; }
            if (is_string($bv) && preg_match('#^(https?://|/)#i', $bv)) return $bv;
        }
        if (!empty($p['mediaId'])) { $m = (new Media($this->k))->find((int)$p['mediaId']); if ($m) return $m['url']; }
        $u = trim((string)($p['url'] ?? ''));
        return preg_match('#^(https?://|/)#i', $u) ? $u : '';
    }

    // ------------------------------------------------------------------ CSS
    /** @param array $style state => [prop => value] */
    private function rules(string $sel, array $style): string
    {
        $out = '';
        if ($d = $this->decls((array)($style['desktop'] ?? []))) $out .= "$sel{{$d}}";
        if ($d = $this->decls((array)($style['hover'] ?? []))) $out .= "$sel:hover{{$d}}";
        foreach (self::BREAKPOINTS as $bp => $q) {
            if ($d = $this->decls((array)($style[$bp] ?? []))) $out .= "@media $q{{$sel}{{$d}}}";
        }
        return $out;
    }

    private function decls(array $props): string
    {
        $out = '';
        foreach ($props as $prop => $val) {
            $v = $this->value((string)$prop, $val);
            if ($v !== null) $out .= $v;
        }
        return $out;
    }

    /** Validate one declaration; returns "prop:value;" or null when rejected. */
    private function value(string $prop, mixed $val): ?string
    {
        if (!is_scalar($val)) return null;
        $v = trim((string)$val);
        if ($v === '' || strlen($v) > 120) return null;
        if (isset(self::ENUMS[$prop])) return in_array($v, self::ENUMS[$prop], true) ? "$prop:$v;" : null;
        if (in_array($prop, self::LENS, true)) return preg_match('/^(?:' . self::LEN . ')$/', $v) ? "$prop:$v;" : null;
        if (in_array($prop, self::BOXES, true)) return preg_match('/^(?:' . self::LEN . ')(?:\s+(?:' . self::LEN . ')){0,3}$/', $v) ? "$prop:$v;" : null;
        if (in_array($prop, self::COLORS, true)) return preg_match('/^(?:' . self::COLOR . ')$/', $v) ? "$prop:$v;" : null;
        switch ($prop) {
            case 'font-weight': return preg_match('/^[1-9]00$/', $v) ? "$prop:$v;" : null;
            case 'line-height': return preg_match('/^\d(?:\.\d+)?$|^\d+(?:px|%|rem|em)$/', $v) ? "$prop:$v;" : null;
            case 'opacity': return preg_match('/^(?:0|1|0?\.\d+)$/', $v) ? "$prop:$v;" : null;
            case 'aspect-ratio': return preg_match('/^\d{1,3}\s*\/\s*\d{1,3}$/', $v) ? "$prop:$v;" : null;
            case 'grid-template-columns':
                return preg_match('/^(?:repeat\(\s*\d{1,2}\s*,\s*(?:1fr|minmax\(\s*\d{1,4}px\s*,\s*1fr\s*\))\s*\)|(?:(?:' . self::LEN . ')\s*){1,12})$/', $v) ? "$prop:$v;" : null;
            case 'box-shadow': return isset(self::SHADOWS[$v]) ? "$prop:" . self::SHADOWS[$v] . ';' : ($v === 'none' ? "$prop:none;" : null);
            case 'background-image':
                if (ctype_digit($v)) { $m = (new Media($this->k))->find((int)$v); return $m ? 'background-image:url("' . str_replace(['"', '\\', "\n", ')'], '', $m['url']) . '");' : null; }
                if (preg_match('/^linear-gradient\(\s*\d{1,3}deg\s*,\s*(?:' . self::COLOR . ')\s*,\s*(?:' . self::COLOR . ')\s*\)$/', $v)) return "background-image:$v;";
                return null;
        }
        return null;
    }
}
