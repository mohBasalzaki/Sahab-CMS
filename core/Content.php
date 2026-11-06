<?php
declare(strict_types=1);

namespace Sahab;

use Twig\Markup;

/** Content types (schema) + entries (data). One real table per type: ct_<slug>. */
final class Content
{
    public const FIELD_TYPES = [
        'text' => ['نص قصير', 'str'], 'textarea' => ['نص طويل', 'text'], 'richtext' => ['محرر منسق', 'text'],
        'number' => ['رقم', 'real'], 'boolean' => ['نعم / لا', 'bool'], 'date' => ['تاريخ', 'str'],
        'datetime' => ['تاريخ ووقت', 'str'], 'select' => ['قائمة اختيار', 'str'], 'email' => ['بريد إلكتروني', 'str'],
        'url' => ['رابط', 'str'], 'uid' => ['UID (معرّف فريد)', 'str'], 'media' => ['وسائط', 'text'], 'relation' => ['علاقة بمحتوى', 'text'], 'json' => ['JSON', 'text'],
    ];
    public const RESERVED = ['admin', 'api', 'themes', 'storage', 'install', 'uploads', 'plugins', 'core', 'vendor', 'forms', 'search', 'lang', 'assets'];
    private const BUILTIN = ['id', 'title', 'slug', 'status', 'author_id', 'created_at', 'updated_at', 'published_at'];

    private ?array $types = null;

    public function __construct(private Kernel $k) {}

    private function db(): Db { return $this->k->db; }

    /** Ready-made content types shown in the Content-Type Builder (fields can be edited before saving). */
    public static function presets(): array
    {
        $f = static fn(string $n, string $l, string $t, array $x = []) => ['name' => $n, 'label' => $l, 'type' => $t] + $x;
        return [
            ['key' => 'article', 'icon' => 'richtext', 'label' => 'مقالات', 'desc' => 'مدونة، أخبار، تدوينات', 'slug' => 'article', 'name' => 'مقال', 'plural' => 'المقالات', 'kind' => 'collection', 'designer' => true, 'fields' => [
                $f('summary', 'الملخص', 'textarea'), $f('content', 'المحتوى', 'richtext'), $f('cover', 'صورة الغلاف', 'media'),
                $f('author', 'الكاتب', 'text'), $f('category', 'التصنيف', 'text'), $f('read_time', 'مدة القراءة (دقائق)', 'number'), $f('tags', 'الوسوم', 'text')]],
            ['key' => 'service', 'icon' => 'settings', 'label' => 'خدمات', 'desc' => 'خدمات الشركة أو الفريق', 'slug' => 'service', 'name' => 'خدمة', 'plural' => 'الخدمات', 'kind' => 'collection', 'designer' => true, 'fields' => [
                $f('summary', 'وصف مختصر', 'textarea', ['required' => true]), $f('description', 'الوصف التفصيلي', 'richtext'), $f('image', 'الصورة', 'media'),
                $f('icon', 'اسم الأيقونة', 'text'), $f('price', 'السعر / يبدأ من', 'text'), $f('features', 'المميزات (سطر لكل ميزة)', 'textarea'), $f('sort_order', 'الترتيب', 'number')]],
            ['key' => 'project', 'icon' => 'layout', 'label' => 'مشاريع', 'desc' => 'معرض أعمال ودراسات حالة', 'slug' => 'project', 'name' => 'مشروع', 'plural' => 'المشاريع', 'kind' => 'collection', 'designer' => true, 'fields' => [
                $f('summary', 'نبذة', 'textarea'), $f('description', 'تفاصيل المشروع', 'richtext'), $f('cover', 'الصورة الرئيسية', 'media'),
                $f('gallery', 'معرض الصور', 'media', ['multiple' => true]), $f('client', 'العميل', 'text'), $f('year', 'السنة', 'number'),
                $f('category', 'التصنيف', 'text'), $f('project_url', 'رابط المشروع', 'url')]],
            ['key' => 'product', 'icon' => 'package', 'label' => 'منتجات', 'desc' => 'متجر أو كتالوج', 'slug' => 'product', 'name' => 'منتج', 'plural' => 'المنتجات', 'kind' => 'collection', 'designer' => true, 'fields' => [
                $f('description', 'الوصف', 'richtext'), $f('price', 'السعر', 'number', ['required' => true]), $f('sale_price', 'سعر الخصم', 'number'), $f('sku', 'رمز المنتج SKU', 'text'),
                $f('image', 'الصورة', 'media'), $f('gallery', 'معرض الصور', 'media', ['multiple' => true]), $f('in_stock', 'متوفر', 'boolean'), $f('category', 'التصنيف', 'text')]],
            ['key' => 'event', 'icon' => 'view', 'label' => 'فعاليات', 'desc' => 'مؤتمرات ودورات وورش', 'slug' => 'event', 'name' => 'فعالية', 'plural' => 'الفعاليات', 'kind' => 'collection', 'designer' => true, 'fields' => [
                $f('description', 'الوصف', 'richtext'), $f('starts_at', 'تبدأ في', 'datetime', ['required' => true]), $f('ends_at', 'تنتهي في', 'datetime'),
                $f('location', 'المكان', 'text'), $f('cover', 'الصورة', 'media'), $f('register_url', 'رابط التسجيل', 'url')]],
            ['key' => 'team', 'icon' => 'users', 'label' => 'الفريق', 'desc' => 'أعضاء الفريق', 'slug' => 'team_member', 'name' => 'عضو فريق', 'plural' => 'الفريق', 'kind' => 'collection', 'designer' => false, 'fields' => [
                $f('role', 'المسمى الوظيفي', 'text', ['required' => true]), $f('bio', 'نبذة', 'textarea'), $f('photo', 'الصورة الشخصية', 'media'),
                $f('email', 'البريد', 'email'), $f('linkedin', 'LinkedIn', 'url'), $f('sort_order', 'الترتيب', 'number')]],
            ['key' => 'faq', 'icon' => 'text', 'label' => 'أسئلة شائعة', 'desc' => 'سؤال وجواب', 'slug' => 'faq', 'name' => 'سؤال', 'plural' => 'الأسئلة الشائعة', 'kind' => 'collection', 'designer' => false, 'fields' => [
                $f('answer', 'الإجابة', 'richtext', ['required' => true]), $f('category', 'التصنيف', 'text'), $f('sort_order', 'الترتيب', 'number')]],
            ['key' => 'testimonial', 'icon' => 'user', 'label' => 'آراء العملاء', 'desc' => 'شهادات وتقييمات', 'slug' => 'testimonial', 'name' => 'رأي عميل', 'plural' => 'آراء العملاء', 'kind' => 'collection', 'designer' => false, 'fields' => [
                $f('quote', 'الرأي', 'textarea', ['required' => true]), $f('author', 'الاسم', 'text'), $f('role', 'المسمى', 'text'), $f('company', 'الشركة', 'text'),
                $f('photo', 'الصورة', 'media'), $f('rating', 'التقييم (1-5)', 'number')]],
            ['key' => 'job', 'icon' => 'folder', 'label' => 'وظائف', 'desc' => 'فرص التوظيف', 'slug' => 'job', 'name' => 'وظيفة', 'plural' => 'الوظائف', 'kind' => 'collection', 'designer' => true, 'fields' => [
                $f('description', 'الوصف والمتطلبات', 'richtext'), $f('department', 'القسم', 'text'), $f('location', 'الموقع', 'text'),
                $f('job_type', 'نوع الدوام', 'select', ['options' => ['دوام كامل', 'دوام جزئي', 'عن بعد', 'عقد']]), $f('apply_url', 'رابط التقديم', 'url'), $f('deadline', 'آخر موعد', 'date')]],
            ['key' => 'page', 'icon' => 'single', 'label' => 'صفحات', 'desc' => 'صفحات ثابتة (من نحن، سياسة…)', 'slug' => 'page', 'name' => 'صفحة', 'plural' => 'الصفحات', 'kind' => 'collection', 'designer' => true, 'fields' => [
                $f('content', 'المحتوى', 'richtext'), $f('cover', 'صورة', 'media')]],
            ['key' => 'homepage', 'icon' => 'home', 'label' => 'الصفحة الرئيسية', 'desc' => 'نوع مفرد: نصوص وصور الرئيسية', 'slug' => 'homepage', 'name' => 'الصفحة الرئيسية', 'plural' => 'الصفحة الرئيسية', 'kind' => 'single', 'designer' => true, 'fields' => [
                $f('hero_title', 'عنوان البطل', 'text'), $f('hero_text', 'نص البطل', 'textarea'), $f('hero_image', 'صورة البطل', 'media'),
                $f('cta_label', 'نص الزر', 'text'), $f('cta_url', 'رابط الزر', 'url')]],
            ['key' => 'settings', 'icon' => 'settings', 'label' => 'إعدادات الموقع', 'desc' => 'نوع مفرد: هاتف، بريد، تواصل', 'slug' => 'site_info', 'name' => 'معلومات الموقع', 'plural' => 'معلومات الموقع', 'kind' => 'single', 'designer' => false, 'fields' => [
                $f('tagline', 'الشعار النصي', 'text'), $f('phone', 'الهاتف', 'text'), $f('email', 'البريد', 'email'), $f('address', 'العنوان', 'textarea'),
                $f('twitter', 'X / تويتر', 'url'), $f('instagram', 'Instagram', 'url'), $f('linkedin', 'LinkedIn', 'url')]],
        ];
    }

    // ------------------------------------------------------------ types
    public function types(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach ($this->db()->all('SELECT * FROM content_types ORDER BY id') as $r) {
                $r['fields'] = json_decode((string)$r['schema_json'], true) ?: [];
                $this->types[$r['slug']] = $r;
            }
        }
        return $this->types;
    }

    public function type(string $slug): ?array { return $this->types()[$slug] ?? null; }

    public static function table(string $slug): string { return 'ct_' . $slug; }

    /** @return string[] validation errors (empty on success) */
    public function saveType(array $in, ?string $existing): array
    {
        $errors = [];
        $slug = strtolower(trim((string)($in['slug'] ?? '')));
        $name = trim((string)($in['name'] ?? ''));
        $plural = trim((string)($in['plural'] ?? '')) ?: $name;
        if ($existing === null) {
            if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $slug)) $errors[] = 'المعرّف يجب أن يكون بالإنجليزية (أحرف صغيرة وأرقام و _) من 2 إلى 31 حرفاً.';
            elseif (in_array($slug, self::RESERVED, true)) $errors[] = 'هذا المعرّف محجوز.';
            elseif ($this->type($slug)) $errors[] = 'المعرّف مستخدم مسبقاً.';
        } else {
            $slug = $existing;
        }
        if ($name === '') $errors[] = 'اسم النوع مطلوب.';

        $old = $existing ? ($this->type($existing)['fields'] ?? []) : [];
        $oldByName = array_column($old, null, 'name');
        $fields = []; $seen = [];
        foreach ((array)($in['fields'] ?? []) as $f) {
            $fn = strtolower(trim((string)($f['name'] ?? '')));
            if ($fn === '' && trim((string)($f['label'] ?? '')) === '') continue;
            if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $fn)) { $errors[] = "اسم الحقل «$fn» غير صالح (أحرف إنجليزية صغيرة وأرقام و _)."; continue; }
            if (in_array($fn, self::BUILTIN, true)) { $errors[] = "اسم الحقل «$fn» محجوز."; continue; }
            if (isset($seen[$fn])) { $errors[] = "الحقل «$fn» مكرر."; continue; }
            $seen[$fn] = 1;
            $type = (string)($f['type'] ?? 'text');
            if (isset($oldByName[$fn])) $type = $oldByName[$fn]['type']; // type is immutable
            if (!isset(self::FIELD_TYPES[$type])) { $errors[] = "نوع الحقل «$fn» غير معروف."; continue; }
            $def = ['name' => $fn, 'label' => trim((string)($f['label'] ?? '')) ?: $fn, 'type' => $type,
                'required' => !empty($f['required']), 'multiple' => !empty($f['multiple'])];
            if ($type === 'select') {
                $opts = $f['options'] ?? [];
                if (is_string($opts)) $opts = preg_split('/\R/u', $opts);
                $def['options'] = array_values(array_filter(array_map('trim', (array)$opts), 'strlen'));
                if (!$def['options']) $errors[] = "حقل القائمة «$fn» يحتاج خيارات.";
            }
            if ($type === 'relation') {
                $def['target'] = (string)($f['target'] ?? '');
                if ($def['target'] === '' || (!$this->type($def['target']) && $def['target'] !== $slug)) $errors[] = "حقل العلاقة «$fn» يحتاج نوع محتوى مستهدفاً.";
            }
            $fields[] = $def;
        }
        if ($errors) return $errors;

        $db = $this->db();
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE);
        if ($existing === null) {
            $this->createTable($slug, $fields);
            $kind = ($in['kind'] ?? 'collection') === 'single' ? 'single' : 'collection';
            $db->insert('content_types', ['slug' => $slug, 'name' => $name, 'plural' => $plural, 'kind' => $kind, 'designer' => !empty($in['designer']) ? 1 : 0, 'schema_json' => $json, 'created_at' => $db->now()]);
        } else {
            $t = self::table($slug);
            $newNames = array_column($fields, 'name');
            foreach ($fields as $f) {
                if (!isset($oldByName[$f['name']])) {
                    $db->pdo->exec('ALTER TABLE ' . $db->id($t) . ' ADD COLUMN ' . $db->id('f_' . $f['name']) . ' ' . $db->type(self::FIELD_TYPES[$f['type']][1]));
                }
            }
            foreach ($oldByName as $n => $_) {
                if (!in_array($n, $newNames, true)) {
                    try { $db->pdo->exec('ALTER TABLE ' . $db->id($t) . ' DROP COLUMN ' . $db->id('f_' . $n)); } catch (\Throwable) { /* old SQLite: keep orphan column */ }
                }
            }
            $db->update('content_types', ['name' => $name, 'plural' => $plural, 'designer' => !empty($in['designer']) ? 1 : 0, 'schema_json' => $json], 'slug = ?', [$slug]);
        }
        $this->types = null;
        return [];
    }

    private function createTable(string $slug, array $fields): void
    {
        $db = $this->db(); $pk = $db->pk(); $str = $db->type('str'); $int = $db->type('int');
        $k191 = $db->driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';
        $cols = "id $pk, title $str NOT NULL, slug $k191 NOT NULL, status $k191 NOT NULL DEFAULT 'draft', author_id $int, created_at $str, updated_at $str, published_at $str";
        foreach ($fields as $f) $cols .= ', ' . $db->id('f_' . $f['name']) . ' ' . $db->type(self::FIELD_TYPES[$f['type']][1]);
        $t = self::table($slug);
        $db->createTable($t, $cols);
        $db->pdo->exec('CREATE INDEX ' . $db->id("idx_{$t}_slug") . ' ON ' . $db->id($t) . ' (' . $db->id('slug') . ')');
        $db->pdo->exec('CREATE INDEX ' . $db->id("idx_{$t}_st") . ' ON ' . $db->id($t) . ' (' . $db->id('status') . ')');
    }

    public function deleteType(string $slug): void
    {
        if (!$this->type($slug)) return;
        $db = $this->db();
        $db->pdo->exec('DROP TABLE IF EXISTS ' . $db->id(self::table($slug)));
        $db->exec('DELETE FROM content_types WHERE slug = ?', [$slug]);
        $this->types = null;
    }

    // ------------------------------------------------------------ entries
    public function url(string $type, string $slug): string
    {
        if ($type === 'page') return $this->k->url('/' . rawurlencode($slug));
        if (($this->types()[$type]['kind'] ?? '') === 'single') return $this->k->url('/' . $type);
        return $this->k->url('/' . $type . '/' . rawurlencode($slug));
    }

    /**
     * @param array $o status|q|where|order|limit|page|author|markup
     * @return array{items:array,total:int,pages:int,page:int}
     */
    public function list(array $type, array $o = []): array
    {
        $db = $this->db(); $t = $db->id(self::table($type['slug']));
        $fields = array_column($type['fields'], null, 'name');
        $w = []; $p = [];
        $status = $o['status'] ?? 'published';
        if ($status !== 'any') { $w[] = 'status = ?'; $p[] = $status; }
        if (!empty($o['author'])) { $w[] = 'author_id = ?'; $p[] = (int)$o['author']; }
        if (!empty($o['q'])) {
            // '!' is the LIKE escape char: portable between MySQL and SQLite.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string)$o['q']) . '%';
            $parts = ["title LIKE ? ESCAPE '!'"]; $p[] = $like;
            foreach ($type['fields'] as $f) {
                if (in_array($f['type'], ['text', 'textarea', 'richtext'], true)) { $parts[] = $db->id('f_' . $f['name']) . " LIKE ? ESCAPE '!'"; $p[] = $like; }
            }
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
        foreach ((array)($o['where'] ?? []) as $key => $val) {
            $op = '='; $col = (string)$key;
            if (preg_match('/^(.+)__(like|gt|lt|ne|gte|lte|in|notin|null)$/', $col, $m)) {
                $col = $m[1];
                $op = ['like' => 'LIKE', 'gt' => '>', 'lt' => '<', 'ne' => '<>', 'gte' => '>=', 'lte' => '<=', 'in' => '=', 'notin' => '<>', 'null' => 'NULL'][$m[2]];
                if ($m[2] === 'notin' && is_array($val)) {
                    if (isset($fields[$col])) $c = $db->id('f_' . $col); elseif (in_array($col, self::BUILTIN, true)) $c = $db->id($col); else continue;
                    if ($val) { $w[] = "$c NOT IN (" . implode(',', array_fill(0, count($val), '?')) . ')'; array_push($p, ...array_values($val)); }
                    continue;
                }
                if ($m[2] === 'null') {
                    if (isset($fields[$col])) $c = $db->id('f_' . $col); elseif (in_array($col, self::BUILTIN, true)) $c = $db->id($col); else continue;
                    $w[] = $val ? "($c IS NULL OR $c = '')" : "($c IS NOT NULL AND $c <> '')";
                    continue;
                }
            }
            if (isset($fields[$col])) $c = $db->id('f_' . $col);
            elseif (in_array($col, self::BUILTIN, true)) $c = $db->id($col);
            else continue;
            if ($op === 'LIKE') $val = '%' . $val . '%';
            if (is_array($val)) { $w[] = "$c IN (" . implode(',', array_fill(0, count($val), '?')) . ')'; array_push($p, ...array_values($val)); continue; }
            $w[] = "$c $op ?"; $p[] = is_bool($val) ? (int)$val : $val;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $order = 'COALESCE(published_at, created_at) DESC, id DESC';
        if (!empty($o['order']) && preg_match('/^([a-z_][a-z0-9_]*)(?:[ :](asc|desc))?$/i', trim((string)$o['order']), $m)) {
            $col = strtolower($m[1]); $dir = strtolower($m[2] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
            if (isset($fields[$col])) $order = $db->id('f_' . $col) . " $dir, id DESC";
            elseif (in_array($col, self::BUILTIN, true)) $order = $db->id($col) . " $dir, id DESC";
        }
        $limit = max(1, min(200, (int)($o['limit'] ?? 10)));
        $page = max(1, (int)($o['page'] ?? 1));
        $total = (int)$db->val("SELECT COUNT(*) FROM $t $where", $p);
        $rows = $db->all("SELECT * FROM $t $where ORDER BY $order LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);
        return [
            'items' => $this->hydrate($type, $rows, !empty($o['markup'])),
            'total' => $total, 'pages' => (int)max(1, ceil($total / $limit)), 'page' => $page,
        ];
    }

    public function find(array $type, int $id, bool $hydrate = true, bool $markup = false): ?array
    {
        $r = $this->db()->one('SELECT * FROM ' . $this->db()->id(self::table($type['slug'])) . ' WHERE id = ?', [$id]);
        if (!$r) return null;
        return $hydrate ? $this->hydrate($type, [$r], $markup)[0] : $r;
    }

    public function findBySlug(array $type, string $slug, string $status = 'published', bool $markup = false): ?array
    {
        $t = $this->db()->id(self::table($type['slug']));
        $r = $status === 'any'
            ? $this->db()->one("SELECT * FROM $t WHERE slug = ?", [$slug])
            : $this->db()->one("SELECT * FROM $t WHERE slug = ? AND status = ?", [$slug, $status]);
        return $r ? $this->hydrate($type, [$r], $markup)[0] : null;
    }

    /** Convert raw DB rows to template/API friendly arrays (media + relations resolved). */
    public function hydrate(array $type, array $rows, bool $markup = false): array
    {
        if (!$rows) return [];
        $media = new Media($this->k);
        $ids = ['media' => [], 'rel' => []];
        foreach ($rows as $r) {
            foreach ($type['fields'] as $f) {
                $v = (string)($r['f_' . $f['name']] ?? '');
                if ($v === '') continue;
                if ($f['type'] === 'media') foreach (explode(',', $v) as $i) $ids['media'][(int)$i] = true;
                if ($f['type'] === 'relation') foreach (explode(',', $v) as $i) $ids['rel'][$f['target']][(int)$i] = true;
            }
        }
        $mediaMap = $media->byIds(array_keys($ids['media']));
        $relMap = [];
        foreach ($ids['rel'] as $target => $set) {
            $tt = $this->type($target);
            if (!$tt) continue;
            $in = array_keys($set);
            $q = $this->db()->all('SELECT id, title, slug, status FROM ' . $this->db()->id(self::table($target)) . ' WHERE id IN (' . implode(',', array_fill(0, count($in), '?')) . ')', $in);
            foreach ($q as $x) { $x['url'] = $this->url($target, $x['slug']); $x['type'] = $target; $relMap[$target][(int)$x['id']] = $x; }
        }
        $out = [];
        foreach ($rows as $r) {
            $e = [
                'id' => (int)$r['id'], 'type' => $type['slug'], 'title' => $r['title'], 'slug' => $r['slug'], 'status' => $r['status'],
                'author_id' => (int)$r['author_id'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
                'published_at' => $r['published_at'], 'url' => $this->url($type['slug'], $r['slug']),
            ];
            foreach ($type['fields'] as $f) {
                $raw = $r['f_' . $f['name']] ?? null;
                $e[$f['name']] = match ($f['type']) {
                    'boolean' => (bool)$raw,
                    'number' => $raw === null || $raw === '' ? null : $raw + 0,
                    'json' => $raw === null || $raw === '' ? null : json_decode((string)$raw, true),
                    'richtext' => $markup ? new Markup((string)$raw, 'UTF-8') : (string)$raw,
                    'media' => $this->pick((string)$raw, $mediaMap, !empty($f['multiple'])),
                    'relation' => $this->pick((string)$raw, $relMap[$f['target']] ?? [], !empty($f['multiple'])),
                    default => $raw,
                };
            }
            $out[] = $e;
        }
        return $out;
    }

    private function pick(string $csv, array $map, bool $multiple): mixed
    {
        $items = [];
        foreach (array_filter(explode(',', $csv), 'strlen') as $i) if (isset($map[(int)$i])) $items[] = $map[(int)$i];
        return $multiple ? $items : ($items[0] ?? null);
    }

    /**
     * Create/update an entry. $in is flat: title, slug, status, published_at + field names.
     * @param bool $partial only touch keys present in $in (API PATCH/PUT)
     * @return array{id:?int,errors:string[]}
     */
    public function saveEntry(array $type, ?int $id, array $in, int $userId, bool $partial = false): array
    {
        $db = $this->db(); $errors = []; $row = [];
        $t = self::table($type['slug']);
        $current = $id ? $db->one('SELECT * FROM ' . $db->id($t) . ' WHERE id = ?', [$id]) : null;
        if ($id && !$current) return ['id' => null, 'errors' => ['المحتوى غير موجود.']];

        if (!$partial || array_key_exists('title', $in)) {
            $title = trim((string)($in['title'] ?? ''));
            if ($title === '') $errors[] = 'العنوان مطلوب.';
            $row['title'] = mb_substr($title, 0, 255);
        }
        foreach ($type['fields'] as $f) {
            $n = $f['name'];
            if ($partial && !array_key_exists($n, $in)) continue;
            [$val, $err] = $this->coerce($f, $in[$n] ?? null);
            if ($err) $errors[] = $err;
            $row['f_' . $n] = $val;
        }
        $status = $in['status'] ?? ($current['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published'], true)) $status = 'draft';
        $row['status'] = $status;
        if ($errors) return ['id' => null, 'errors' => $errors];

        $now = $db->now();
        $pub = $current['published_at'] ?? null;
        if (!empty($in['published_at']) && strtotime((string)$in['published_at'])) $pub = date('Y-m-d H:i:s', strtotime((string)$in['published_at']));
        if ($status === 'published' && !$pub) $pub = $now;
        $row['published_at'] = $pub;
        $row['updated_at'] = $now;

        $slugIn = trim((string)($in['slug'] ?? ''));
        if ($slugIn !== '' || !$current) {
            $base = Str::slug($slugIn !== '' ? $slugIn : (string)($row['title'] ?? ''), $type['slug'] . '-' . date('ymdHis'));
            $row['slug'] = $this->uniqueSlug($t, $base, $id);
        }

        if ($current) {
            $db->update($t, $row, 'id = ?', [$id]);
        } else {
            $row['author_id'] = $userId; $row['created_at'] = $now;
            $id = $db->insert($t, $row);
        }
        Hooks::emit('entry.saved', $type['slug'], $id, $current === null, $current['status'] ?? null, $status);
        return ['id' => $id, 'errors' => []];
    }

    /** Single types hold exactly one row; create it on first access. */
    public function singleRow(array $type): array
    {
        $db = $this->db(); $t = $db->id(self::table($type['slug']));
        $row = $db->one("SELECT * FROM $t ORDER BY id LIMIT 1");
        if ($row) return $row;
        $now = $db->now();
        $id = $db->insert(self::table($type['slug']), ['title' => $type['name'], 'slug' => $type['slug'], 'status' => 'draft', 'author_id' => 0, 'created_at' => $now, 'updated_at' => $now]);
        return $db->one("SELECT * FROM $t WHERE id = ?", [$id]);
    }

    private function uniqueSlug(string $table, string $base, ?int $selfId): string
    {
        $db = $this->db(); $slug = $base; $n = 2;
        while ($db->val('SELECT COUNT(*) FROM ' . $db->id($table) . ' WHERE slug = ? AND id <> ?', [$slug, $selfId ?? 0])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    public function deleteEntry(array $type, int $id): void
    {
        $this->db()->exec('DELETE FROM ' . $this->db()->id(self::table($type['slug'])) . ' WHERE id = ?', [$id]);
        Hooks::emit('entry.deleted', $type['slug'], $id);
    }

    /** @return array{0:mixed,1:?string} [dbValue, error] */
    public function coerce(array $f, mixed $raw): array
    {
        $label = $f['label']; $req = !empty($f['required']);
        $empty = static fn($v) => $v === null || $v === '' || $v === [];
        if ($f['type'] === 'boolean') return [!empty($raw) && $raw !== '0' && $raw !== 'false' ? 1 : 0, null];
        if (is_array($raw) && !in_array($f['type'], ['media', 'relation', 'json'], true)) $raw = null;
        if ($empty($raw)) return [null, $req ? "الحقل «$label» مطلوب." : null];

        switch ($f['type']) {
            case 'text': case 'textarea':
                $s = trim((string)$raw);
                return [$f['type'] === 'text' ? mb_substr($s, 0, 255) : $s, null];
            case 'uid': return [Str::slug((string)$raw), null];
            case 'richtext': return [Html::clean((string)$raw), null];
            case 'number':
                return is_numeric($raw) ? [$raw + 0, null] : [null, "الحقل «$label» يجب أن يكون رقماً."];
            case 'email':
                return filter_var($raw, FILTER_VALIDATE_EMAIL) ? [mb_substr(trim((string)$raw), 0, 255), null] : [null, "البريد في «$label» غير صالح."];
            case 'url':
                $u = trim((string)$raw);
                return (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u)) ? [mb_substr($u, 0, 255), null] : [null, "الرابط في «$label» غير صالح."];
            case 'date':
                $d = \DateTime::createFromFormat('Y-m-d', (string)$raw);
                return ($d && $d->format('Y-m-d') === $raw) ? [$raw, null] : [null, "التاريخ في «$label» غير صالح."];
            case 'datetime':
                $ts = strtotime((string)$raw);
                return $ts ? [date('Y-m-d H:i', $ts), null] : [null, "التاريخ والوقت في «$label» غير صالح."];
            case 'select':
                return in_array((string)$raw, $f['options'] ?? [], true) ? [(string)$raw, null] : [null, "قيمة «$label» غير مسموحة."];
            case 'media': case 'relation':
                $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                if (empty($f['multiple'])) $ids = array_slice($ids, 0, 1);
                if (!$ids) return [null, $req ? "الحقل «$label» مطلوب." : null];
                return [implode(',', $ids), null];
            case 'json':
                $d = is_array($raw) ? $raw : json_decode((string)$raw, true);
                return ($d === null && trim((string)$raw) !== 'null') ? [null, "JSON غير صالح في «$label»."] : [json_encode($d, JSON_UNESCAPED_UNICODE), null];
        }
        return [null, null];
    }

    /** Counts per type for the dashboard. */
    public function counts(): array
    {
        $out = [];
        foreach ($this->types() as $slug => $t) {
            $out[$slug] = (int)$this->db()->val('SELECT COUNT(*) FROM ' . $this->db()->id(self::table($slug)));
        }
        return $out;
    }
}
