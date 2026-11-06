<?php
declare(strict_types=1);

namespace Sahab;

final class Forms
{
    public const TYPES = ['text' => 'نص قصير', 'email' => 'بريد إلكتروني', 'tel' => 'هاتف', 'number' => 'رقم', 'textarea' => 'نص طويل', 'select' => 'قائمة اختيار', 'checkbox' => 'مربع اختيار'];

    public function __construct(private Kernel $k) {}

    public function get(string $slug): ?array
    {
        $f = $this->k->db->one('SELECT * FROM forms WHERE slug = ?', [$slug]);
        return $f ? $this->decode($f) : null;
    }

    public function decode(array $f): array
    {
        $f['fields'] = json_decode((string)$f['fields_json'], true) ?: [];
        return $f;
    }

    public function all(): array
    {
        return array_map([$this, 'decode'], $this->k->db->all('SELECT * FROM forms ORDER BY id'));
    }

    /** Public, template-facing representation. */
    public function describe(array $form): array
    {
        return [
            'slug' => $form['slug'], 'name' => $form['name'], 'fields' => $form['fields'],
            'action' => $this->k->url('/forms/' . $form['slug']), 'submit_label' => I18n::t($form['submit_label'] ?: 'إرسال'),
            'csrf' => Security::csrfToken(), 'honeypot' => 'website_url',
            'message' => $this->k->flash('form_' . $form['slug']), 'errors' => $this->k->flash('form_err_' . $form['slug']) ?: [],
            'old' => $this->k->flash('form_old_' . $form['slug']) ?: [],
        ];
    }

    /** Default HTML rendering (escaped). Themes may build their own markup from describe(). */
    public function render(array $form): string
    {
        $d = $this->describe($form);
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $h = '<form class="sh-form" method="post" action="' . $e($d['action']) . '">';
        if ($d['message']) $h .= '<div class="sh-form-msg ' . ($d['message']['ok'] ? 'ok' : 'err') . '" role="status">' . $e($d['message']['text']) . '</div>';
        foreach ($d['errors'] as $er) $h .= '<div class="sh-form-msg err">' . $e($er) . '</div>';
        $h .= '<input type="hidden" name="_csrf" value="' . $e($d['csrf']) . '">';
        $h .= '<div style="position:absolute;inset-inline-start:-9999px" aria-hidden="true"><input type="text" name="website_url" tabindex="-1" autocomplete="off"></div>';
        foreach ($d['fields'] as $f) {
            $n = $e($f['name']); $req = !empty($f['required']) ? ' required' : ''; $val = $e($d['old'][$f['name']] ?? '');
            $h .= '<div class="sh-field"><label for="ff_' . $n . '">' . $e($f['label']) . (!empty($f['required']) ? ' *' : '') . '</label>';
            $h .= match ($f['type']) {
                'textarea' => '<textarea id="ff_' . $n . '" name="' . $n . '" rows="5"' . $req . '>' . $val . '</textarea>',
                'select' => '<select id="ff_' . $n . '" name="' . $n . '"' . $req . '><option value="">—</option>' . implode('', array_map(fn($o) => '<option' . ($val === $e($o) ? ' selected' : '') . '>' . $e($o) . '</option>', $f['options'] ?? [])) . '</select>',
                'checkbox' => '<input type="checkbox" id="ff_' . $n . '" name="' . $n . '" value="1"' . ($val ? ' checked' : '') . $req . '>',
                default => '<input type="' . $e(in_array($f['type'], ['email', 'tel', 'number'], true) ? $f['type'] : 'text') . '" id="ff_' . $n . '" name="' . $n . '" value="' . $val . '"' . $req . '>',
            };
            $h .= '</div>';
        }
        return $h . '<button type="submit" class="sh-btn">' . $e($d['submit_label']) . '</button></form>';
    }

    /** Handle POST /forms/{slug}. */
    public function submit(string $slug, Request $r): void
    {
        $k = $this->k;
        if (I18n::siteLang() !== I18n::$lang) I18n::load(I18n::siteLang());   // visitor-facing messages follow the site language
        $form = $this->get($slug);
        if (!$form) { http_response_code(404); echo 'Not found'; return; }
        $json = $r->wantsJson();
        $back = $this->safeBack($r);
        $fail = function (array $errors) use ($k, $slug, $json, $back, $r) {
            $errors = array_map(fn($e) => I18n::t((string)$e), $errors);
            if ($json) $k->json(['ok' => false, 'errors' => $errors], 422);
            $k->flash('form_err_' . $slug, $errors);
            $old = [];
            foreach ($r->post as $n => $v) if (is_scalar($v) && $n !== '_csrf') $old[$n] = (string)$v;
            $k->flash('form_old_' . $slug, $old);
            $k->redirect($back . '#form-' . $slug);
        };

        if (!Security::csrfOk($r)) $fail(['انتهت صلاحية الجلسة، أعد تحميل الصفحة وحاول مجدداً.']);
        if (!empty($r->post['website_url'])) { // honeypot: pretend success
            if ($json) $k->json(['ok' => true]);
            $k->redirect($back);
        }
        if (!Security::rateLimit('form:' . $slug . ':' . $r->ip(), 5, 600)) $fail(['أرسلت رسائل كثيرة، حاول لاحقاً.']);

        $data = []; $errors = [];
        foreach ($form['fields'] as $f) {
            $v = $r->post[$f['name']] ?? '';
            $v = is_scalar($v) ? trim((string)$v) : '';
            if ($f['type'] === 'checkbox') $v = $v !== '' ? 'نعم' : 'لا';
            if (!empty($f['required']) && ($v === '' || ($f['type'] === 'checkbox' && $v === 'لا'))) { $errors[] = "الحقل «{$f['label']}» مطلوب."; continue; }
            if ($v !== '') {
                if ($f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[] = "البريد في «{$f['label']}» غير صالح."; continue; }
                if ($f['type'] === 'number' && !is_numeric($v)) { $errors[] = "«{$f['label']}» يجب أن يكون رقماً."; continue; }
                if ($f['type'] === 'select' && !in_array($v, $f['options'] ?? [], true)) { $errors[] = "قيمة «{$f['label']}» غير مسموحة."; continue; }
            }
            $data[$f['name']] = mb_substr($v, 0, $f['type'] === 'textarea' ? 5000 : 500);
        }
        if ($errors) $fail($errors);

        $k->db->insert('submissions', [
            'form_id' => (int)$form['id'], 'data_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ip' => $r->ip(), 'created_at' => $k->db->now(), 'is_read' => 0,
        ]);
        Hooks::emit('form.submitted', $slug, $data);

        if (!empty($form['email_to'])) {
            $body = "رسالة جديدة من نموذج «{$form['name']}»\n\n";
            foreach ($form['fields'] as $f) $body .= $f['label'] . ': ' . ($data[$f['name']] ?? '') . "\n";
            $reply = null;
            foreach ($form['fields'] as $f) if ($f['type'] === 'email' && !empty($data[$f['name']])) { $reply = $data[$f['name']]; break; }
            Mailer::send($form['email_to'], '[' . $k->setting('site_title', '') . '] ' . $form['name'], $body, $reply);
        }
        $msg = I18n::t($form['success_msg'] ?: 'تم الإرسال بنجاح.');
        if ($json) $k->json(['ok' => true, 'message' => $msg]);
        $k->flash('form_' . $slug, ['ok' => true, 'text' => $msg]);
        $k->redirect($back . '#form-' . $slug);
    }

    private function safeBack(Request $r): string
    {
        $ref = $r->header('Referer');
        $p = parse_url($ref);
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($ref && ($p['host'] ?? '') !== '' && ($p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')) === $host) {
            $path = $p['path'] ?? '/';
            $base = $this->k->base;
            if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
            return '/' . ltrim($path, '/');
        }
        return '/';
    }
}
