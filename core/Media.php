<?php
declare(strict_types=1);

namespace Sahab;

final class Media
{
    /** mime => extension. Extension always derives from the detected MIME, never from the client. */
    private const ALLOWED = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'audio/mpeg' => 'mp3',
        'text/plain' => 'txt', 'text/csv' => 'csv',
    ];

    public function __construct(private Kernel $k) {}

    public function dir(): string { return $this->k->root . '/uploads'; }

    public function url(array $m): string { return $this->k->url('/uploads/' . $m['path']); }

    public function thumbUrl(array $m): string
    {
        $t = preg_replace('/(\.\w+)$/', '-thumb$1', $m['path']);
        return is_file($this->dir() . '/' . $t) ? $this->k->url('/uploads/' . $t) : $this->url($m);
    }

    private function decorate(array $m): array
    {
        $m['id'] = (int)$m['id'];
        $m['url'] = $this->url($m);
        $m['thumb'] = $this->thumbUrl($m);
        $m['is_image'] = str_starts_with((string)$m['mime'], 'image/');
        return $m;
    }

    public function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $out = [];
        foreach ($this->k->db->all('SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $m) {
            $out[(int)$m['id']] = $this->decorate($m);
        }
        return $out;
    }

    public function find(int $id): ?array { return $this->byIds([$id])[$id] ?? null; }

    public function list(int $page = 1, int $per = 24, string $q = '', ?string $kind = null): array
    {
        $w = []; $p = [];
        if ($q !== '') { $w[] = 'original LIKE ?'; $p[] = '%' . $q . '%'; }
        if ($kind === 'image') $w[] = "mime LIKE 'image/%'";
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $total = (int)$this->k->db->val("SELECT COUNT(*) FROM media $where", $p);
        $rows = $this->k->db->all("SELECT * FROM media $where ORDER BY id DESC LIMIT " . (int)$per . ' OFFSET ' . (max(0, $page - 1) * $per), $p);
        return ['items' => array_map([$this, 'decorate'], $rows), 'total' => $total, 'pages' => (int)max(1, ceil($total / $per))];
    }

    /** @return array{media:?array,error:?string} */
    public function upload(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['media' => null, 'error' => 'فشل رفع الملف (رمز ' . ($file['error'] ?? '?') . ').'];
        $max = (int)$this->k->setting('upload_max_mb', 8) * 1048576;
        if ($file['size'] > $max) return ['media' => null, 'error' => 'حجم الملف أكبر من المسموح.'];
        if (!is_uploaded_file($file['tmp_name'])) return ['media' => null, 'error' => 'ملف غير صالح.'];

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) return ['media' => null, 'error' => 'نوع الملف غير مسموح (' . $mime . ').'];
        $w = $h = null;
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($file['tmp_name']);
            if (!$info) return ['media' => null, 'error' => 'الصورة تالفة أو غير صالحة.'];
            [$w, $h] = $info;
            if ($w * $h > 60_000_000) return ['media' => null, 'error' => 'أبعاد الصورة كبيرة جداً.'];
        }
        $sub = date('Y/m');
        $dir = $this->dir() . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['media' => null, 'error' => 'تعذر إنشاء مجلد الرفع.'];
        $name = bin2hex(random_bytes(8)) . '.' . self::ALLOWED[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return ['media' => null, 'error' => 'تعذر حفظ الملف.'];
        @chmod($dir . '/' . $name, 0644);
        $path = $sub . '/' . $name;
        if ($w) $this->thumb($dir . '/' . $name, $mime, 480);

        $orig = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u', '', (string)$file['name']), 0, 200);
        $id = $this->k->db->insert('media', [
            'path' => $path, 'original' => $orig, 'mime' => $mime, 'size' => (int)$file['size'],
            'width' => $w, 'height' => $h, 'alt' => '', 'user_id' => $userId, 'created_at' => $this->k->db->now(),
        ]);
        return ['media' => $this->find($id), 'error' => null];
    }

    private function thumb(string $src, string $mime, int $maxW): void
    {
        if (!extension_loaded('gd') || $mime === 'image/gif') return;
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src), 'image/png' => @imagecreatefrompng($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false, default => false,
        };
        if (!$img) return;
        $w = imagesx($img); $h = imagesy($img);
        if ($w > $maxW) {
            $nh = (int)round($h * $maxW / $w);
            $dst = imagecreatetruecolor($maxW, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $maxW, $nh, $w, $h);
            $out = preg_replace('/(\.\w+)$/', '-thumb$1', $src);
            match ($mime) { 'image/jpeg' => imagejpeg($dst, $out, 82), 'image/png' => imagepng($dst, $out), default => imagewebp($dst, $out, 82) };
        }
    }

    public function delete(int $id): void
    {
        $m = $this->k->db->one('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$m) return;
        foreach ([$m['path'], preg_replace('/(\.\w+)$/', '-thumb$1', $m['path'])] as $rel) {
            $f = $this->dir() . '/' . $rel;
            if (is_file($f)) @unlink($f);
        }
        $this->k->db->exec('DELETE FROM media WHERE id = ?', [$id]);
    }
}
