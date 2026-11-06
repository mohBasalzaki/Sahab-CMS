<?php
declare(strict_types=1);

namespace Sahab\Admin;

use Sahab\{Auth, Forms, Kernel, Media, Request, Router, Str};

/** Media, forms, users, roles, webhooks, settings, API tokens. */
final class Manage
{
    private Forms $forms;

    public function __construct(private Kernel $k, private Routes $a)
    {
        $this->forms = new Forms($k);
    }

    private function db() { return $this->k->db; }

    public function register(Router $r): void
    {
        $a = $this->a;
        // media
        $a->route($r, 'GET', '/admin/media', 'media', fn(Request $q) => $this->media($q));
        $a->route($r, 'POST', '/admin/media/upload', 'media', fn(Request $q) => $this->upload($q));
        $a->route($r, 'POST', '/admin/media/{id}/update', 'media', fn(Request $q, array $p) => $this->mediaUpdate($q, (int)$p['id']));
        $a->route($r, 'POST', '/admin/media/{id}/delete', 'media', fn(Request $q, array $p) => $this->mediaDelete((int)$p['id']));
        // forms
        $a->route($r, 'GET', '/admin/forms', 'forms.view', fn() => $this->formsList());
        $a->route($r, 'GET', '/admin/forms/new', 'manage', fn(Request $q) => $this->formEdit($q, null));
        $a->route($r, 'POST', '/admin/forms/new', 'manage', fn(Request $q) => $this->formEdit($q, null));
        $a->route($r, 'GET', '/admin/forms/{id}', 'manage', fn(Request $q, array $p) => $this->formEdit($q, (int)$p['id']));
        $a->route($r, 'POST', '/admin/forms/{id}', 'manage', fn(Request $q, array $p) => $this->formEdit($q, (int)$p['id']));
        $a->route($r, 'POST', '/admin/forms/{id}/delete', 'manage', fn(Request $q, array $p) => $this->formDelete((int)$p['id']));
        $a->route($r, 'GET', '/admin/forms/{id}/submissions', 'forms.view', fn(Request $q, array $p) => $this->submissions($q, (int)$p['id']));
        $a->route($r, 'GET', '/admin/forms/{id}/export', 'forms.view', fn(Request $q, array $p) => $this->export((int)$p['id']));
        $a->route($r, 'POST', '/admin/submissions/{id}/delete', 'manage', fn(Request $q, array $p) => $this->submissionDelete((int)$p['id']));
        // users
        $a->route($r, 'GET', '/admin/users', 'manage', fn() => $a->render('users.twig', ['users' => $this->db()->all('SELECT id,name,email,role,created_at,last_login FROM users ORDER BY id')]));
        $a->route($r, 'GET', '/admin/users/new', 'manage', fn(Request $q) => $this->userEdit($q, null));
        $a->route($r, 'POST', '/admin/users/new', 'manage', fn(Request $q) => $this->userEdit($q, null));
        $a->route($r, 'GET', '/admin/users/{id}', 'manage', fn(Request $q, array $p) => $this->userEdit($q, (int)$p['id']));
        $a->route($r, 'POST', '/admin/users/{id}', 'manage', fn(Request $q, array $p) => $this->userEdit($q, (int)$p['id']));
        $a->route($r, 'POST', '/admin/users/{id}/delete', 'manage', fn(Request $q, array $p) => $this->userDelete((int)$p['id']));
        $a->route($r, 'GET', '/admin/profile', 'view', fn(Request $q) => $this->profile($q));
        $a->route($r, 'POST', '/admin/profile', 'view', fn(Request $q) => $this->profile($q));
        // settings / api
        $a->route($r, 'GET', '/admin/settings', 'manage', fn(Request $q) => $this->settings($q));
        $a->route($r, 'POST', '/admin/settings', 'manage', fn(Request $q) => $this->settings($q));
        $a->route($r, 'POST', '/admin/settings/reset', 'manage', fn(Request $q) => $this->factoryReset($q));
        $a->route($r, 'GET', '/admin/api', 'manage', fn() => $this->api());
        $a->route($r, 'POST', '/admin/api/tokens', 'manage', fn(Request $q) => $this->tokenCreate($q));
        $a->route($r, 'POST', '/admin/api/tokens/{id}/delete', 'manage', fn(Request $q, array $p) => $this->tokenDelete((int)$p['id']));
        // roles & permissions
        $a->route($r, 'GET', '/admin/roles', 'manage', fn() => $this->rolesList());
        $a->route($r, 'POST', '/admin/roles/new', 'manage', fn(Request $q) => $this->roleCreate($q));
        $a->route($r, 'GET', '/admin/roles/{slug}', 'manage', fn(Request $q, array $p) => $this->roleEdit($q, $p['slug']));
        $a->route($r, 'POST', '/admin/roles/{slug}', 'manage', fn(Request $q, array $p) => $this->roleEdit($q, $p['slug']));
        $a->route($r, 'POST', '/admin/roles/{slug}/delete', 'manage', fn(Request $q, array $p) => $this->roleDelete($p['slug']));
        // webhooks
        $a->route($r, 'GET', '/admin/webhooks', 'manage', fn() => $a->render('webhooks.twig', ['hooks' => $this->db()->all('SELECT * FROM webhooks ORDER BY id'), 'events' => \Sahab\Webhooks::EVENTS]));
        $a->route($r, 'POST', '/admin/webhooks', 'manage', fn(Request $q) => $this->webhookSave($q));
        $a->route($r, 'POST', '/admin/webhooks/{id}/delete', 'manage', fn(Request $q, array $p) => $this->webhookDelete((int)$p['id']));
        $a->route($r, 'POST', '/admin/webhooks/{id}/toggle', 'manage', fn(Request $q, array $p) => $this->webhookToggle((int)$p['id']));
    }

    // ---------------------------------------------------------------- roles & permissions
    private function rolesList(): void
    {
        $roles = $this->db()->all('SELECT * FROM roles ORDER BY id');
        foreach ($roles as &$r) $r['users'] = (int)$this->db()->val('SELECT COUNT(*) FROM users WHERE role = ?', [$r['slug']]);
        $this->a->render('roles.twig', ['roles' => $roles]);
    }

    private function roleCreate(Request $q): void
    {
        $name = $q->str('name');
        $slug = Str::slug($q->str('slug') ?: $name);
        if ($name === '' || !preg_match('/^[a-z0-9_\-]{2,30}$/', $slug) || $this->db()->val('SELECT COUNT(*) FROM roles WHERE slug = ?', [$slug])) {
            $this->a->flash('اسم الدور مطلوب ومعرّفه إنجليزي (أحرف وأرقام و - _) وغير مكرر.', 'err');
            $this->k->redirect('/admin/roles');
        }
        $this->db()->insert('roles', ['slug' => $slug, 'name' => $name, 'description' => $q->str('description'), 'is_system' => 0]);
        Auth::flush();
        $this->k->redirect('/admin/roles/' . $slug);
    }

    private function roleEdit(Request $q, string $slug): void
    {
        $role = $this->db()->one('SELECT * FROM roles WHERE slug = ?', [$slug]);
        if (!$role) $this->k->redirect('/admin/roles');
        if ($slug === 'admin') { $this->a->flash('دور المدير يملك كل الصلاحيات ولا يمكن تعديله.', 'err'); $this->k->redirect('/admin/roles'); }
        if ($q->isPost()) {
            $this->db()->exec('DELETE FROM role_permissions WHERE role = ?', [$slug]);
            $types = $this->a->content->types();
            foreach ((array)($q->post['perm'] ?? []) as $subject => $actions) {
                if ($subject !== 'system' && !isset($types[$subject])) continue;
                $allowed = $subject === 'system' ? array_keys(Auth::SYSTEM) : array_keys(Auth::ACTIONS);
                foreach ((array)$actions as $act => $on) {
                    if ($on && in_array($act, $allowed, true) && !($slug === 'public' && $subject !== 'system' && $act !== 'read') && !($slug === 'public' && $subject === 'system')) {
                        $this->db()->insert('role_permissions', ['role' => $slug, 'subject' => $subject, 'action' => $act]);
                    }
                }
            }
            if ($q->str('name') !== '' && !$role['is_system']) $this->db()->update('roles', ['name' => $q->str('name'), 'description' => $q->str('description')], 'slug = ?', [$slug]);
            Auth::flush();
            $this->a->flash('تم حفظ الصلاحيات.');
            $this->k->redirect('/admin/roles/' . $slug);
        }
        $has = [];
        foreach ($this->db()->all('SELECT subject, action FROM role_permissions WHERE role = ?', [$slug]) as $r) $has[$r['subject']][$r['action']] = true;
        $this->a->render('role_edit.twig', ['role' => $role, 'has' => $has, 'types' => array_values($this->a->content->types()), 'actions' => Auth::ACTIONS, 'system' => Auth::SYSTEM]);
    }

    private function roleDelete(string $slug): void
    {
        $role = $this->db()->one('SELECT * FROM roles WHERE slug = ?', [$slug]);
        if ($role && !$role['is_system'] && !$this->db()->val('SELECT COUNT(*) FROM users WHERE role = ?', [$slug])) {
            $this->db()->exec('DELETE FROM role_permissions WHERE role = ?', [$slug]);
            $this->db()->exec('DELETE FROM roles WHERE slug = ?', [$slug]);
            Auth::flush();
            $this->a->flash('تم حذف الدور.');
        } else {
            $this->a->flash('لا يمكن حذف دور نظامي أو مرتبط بمستخدمين.', 'err');
        }
        $this->k->redirect('/admin/roles');
    }

    // ---------------------------------------------------------------- webhooks
    private function webhookSave(Request $q): void
    {
        $url = $q->str('url');
        $ev = array_values(array_intersect((array)($q->post['events'] ?? []), \Sahab\Webhooks::EVENTS));
        if ($q->str('name') === '' || !\Sahab\Webhooks::urlAllowed($url, $this->k) || !$ev) {
            $this->a->flash('الاسم والأحداث مطلوبة، والرابط يجب أن يكون http(s) عاماً (العناوين المحلية محجوبة).', 'err');
        } else {
            $this->db()->insert('webhooks', ['name' => mb_substr($q->str('name'), 0, 100), 'url' => $url, 'events' => implode(',', $ev), 'secret' => $q->str('secret'), 'enabled' => 1, 'created_at' => $this->db()->now()]);
            $this->a->flash('تم إنشاء الـ Webhook.');
        }
        $this->k->redirect('/admin/webhooks');
    }

    private function webhookDelete(int $id): void
    {
        $this->db()->exec('DELETE FROM webhooks WHERE id = ?', [$id]);
        $this->k->redirect('/admin/webhooks');
    }

    private function webhookToggle(int $id): void
    {
        $this->db()->exec('UPDATE webhooks SET enabled = 1 - enabled WHERE id = ?', [$id]);
        $this->k->redirect('/admin/webhooks');
    }

    // ---------------------------------------------------------------- media
    private function media(Request $q): void
    {
        $res = (new Media($this->k))->list((int)$q->get('page', 1), 24, $q->str('q'), $q->get('kind') === 'image' ? 'image' : null);
        if ($q->get('json') !== null) $this->k->json($res);
        $this->a->render('media.twig', ['res' => $res, 'q' => $q->str('q')]);
    }

    private function upload(Request $q): void
    {
        $m = new Media($this->k);
        $files = [];
        $up = $q->files['files'] ?? null;
        if ($up && is_array($up['name'])) {
            foreach ($up['name'] as $i => $n) $files[] = ['name' => $n, 'type' => $up['type'][$i], 'tmp_name' => $up['tmp_name'][$i], 'error' => $up['error'][$i], 'size' => $up['size'][$i]];
        } elseif ($up) {
            $files[] = $up;
        }
        $done = []; $errs = [];
        foreach ($files as $f) {
            if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
            $res = $m->upload($f, (int)Auth::user()['id']);
            $res['media'] ? $done[] = $res['media'] : $errs[] = $f['name'] . ': ' . $res['error'];
        }
        if ($q->wantsJson()) $this->k->json(['uploaded' => $done, 'errors' => $errs], $errs && !$done ? 422 : 200);
        if ($done) $this->a->flash('تم رفع ' . count($done) . ' ملف.');
        if ($errs) $this->a->flash(implode(' | ', $errs), 'err');
        $this->k->redirect('/admin/media');
    }

    private function mediaUpdate(Request $q, int $id): void
    {
        $this->db()->exec('UPDATE media SET alt = ? WHERE id = ?', [mb_substr($q->str('alt'), 0, 255), $id]);
        $this->a->flash('تم تحديث النص البديل.');
        $this->k->redirect('/admin/media');
    }

    private function mediaDelete(int $id): void
    {
        (new Media($this->k))->delete($id);
        $this->a->flash('تم حذف الملف.');
        $this->k->redirect('/admin/media');
    }

    // ---------------------------------------------------------------- forms
    private function formsList(): void
    {
        $forms = $this->forms->all();
        foreach ($forms as &$f) {
            $f['total'] = (int)$this->db()->val('SELECT COUNT(*) FROM submissions WHERE form_id = ?', [$f['id']]);
            $f['unread'] = (int)$this->db()->val('SELECT COUNT(*) FROM submissions WHERE form_id = ? AND is_read = 0', [$f['id']]);
        }
        $this->a->render('forms_list.twig', ['forms' => $forms]);
    }

    private function formEdit(Request $q, ?int $id): void
    {
        $row = $id ? $this->db()->one('SELECT * FROM forms WHERE id = ?', [$id]) : null;
        if ($id && !$row) $this->k->redirect('/admin/forms');
        $form = $row ? $this->forms->decode($row) : ['slug' => '', 'name' => '', 'fields' => [], 'email_to' => '', 'success_msg' => 'تم إرسال رسالتك بنجاح.', 'submit_label' => 'إرسال'];
        $errors = [];
        if ($q->isPost()) {
            $form = ['slug' => strtolower($q->str('slug')), 'name' => $q->str('name'), 'email_to' => $q->str('email_to'),
                'success_msg' => $q->str('success_msg'), 'submit_label' => $q->str('submit_label')] + $form;
            $fields = []; $seen = [];
            foreach ((array)($q->post['fields'] ?? []) as $f) {
                $n = strtolower(trim((string)($f['name'] ?? '')));
                if ($n === '' && trim((string)($f['label'] ?? '')) === '') continue;
                if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $n) || $n === 'website_url' || isset($seen[$n])) { $errors[] = "اسم الحقل «$n» غير صالح أو مكرر."; continue; }
                $seen[$n] = 1;
                $type = isset(Forms::TYPES[$f['type'] ?? '']) ? $f['type'] : 'text';
                $fd = ['name' => $n, 'label' => trim((string)$f['label']) ?: $n, 'type' => $type, 'required' => !empty($f['required'])];
                if ($type === 'select') $fd['options'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($f['options'] ?? ''))), 'strlen'));
                $fields[] = $fd;
            }
            $form['fields'] = $fields;
            if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,40}$/', $form['slug'])) $errors[] = 'المعرّف يجب أن يكون بالإنجليزية (أحرف وأرقام و - _).';
            if ($form['name'] === '') $errors[] = 'اسم النموذج مطلوب.';
            if (!$fields) $errors[] = 'أضف حقلاً واحداً على الأقل.';
            if ($form['email_to'] !== '' && !filter_var($form['email_to'], FILTER_VALIDATE_EMAIL)) $errors[] = 'بريد الإشعار غير صالح.';
            if ($this->db()->val('SELECT COUNT(*) FROM forms WHERE slug = ? AND id <> ?', [$form['slug'], $id ?? 0])) $errors[] = 'المعرّف مستخدم.';
            if (!$errors) {
                $row = ['slug' => $form['slug'], 'name' => $form['name'], 'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                    'email_to' => $form['email_to'], 'success_msg' => $form['success_msg'], 'submit_label' => $form['submit_label']];
                if ($id) $this->db()->update('forms', $row, 'id = ?', [$id]);
                else $id = $this->db()->insert('forms', $row + ['created_at' => $this->db()->now()]);
                $this->a->flash('تم حفظ النموذج.');
                $this->k->redirect('/admin/forms/' . $id);
            }
        }
        $this->a->render('form_edit.twig', ['form' => $form, 'id' => $id, 'errors' => $errors, 'field_types' => Forms::TYPES]);
    }

    private function formDelete(int $id): void
    {
        $this->db()->exec('DELETE FROM submissions WHERE form_id = ?', [$id]);
        $this->db()->exec('DELETE FROM forms WHERE id = ?', [$id]);
        $this->a->flash('تم حذف النموذج.');
        $this->k->redirect('/admin/forms');
    }

    private function submissions(Request $q, int $id): void
    {
        $row = $this->db()->one('SELECT * FROM forms WHERE id = ?', [$id]);
        if (!$row) $this->k->redirect('/admin/forms');
        $form = $this->forms->decode($row);
        $page = max(1, (int)$q->get('page', 1));
        $total = (int)$this->db()->val('SELECT COUNT(*) FROM submissions WHERE form_id = ?', [$id]);
        $items = $this->db()->all('SELECT * FROM submissions WHERE form_id = ? ORDER BY id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), [$id]);
        foreach ($items as &$i) $i['data'] = json_decode((string)$i['data_json'], true) ?: [];
        $this->db()->exec('UPDATE submissions SET is_read = 1 WHERE form_id = ?', [$id]);
        $this->a->render('submissions.twig', ['form' => $form, 'items' => $items, 'page' => $page, 'pages' => (int)max(1, ceil($total / 20)), 'total' => $total]);
    }

    private function export(int $id): void
    {
        $row = $this->db()->one('SELECT * FROM forms WHERE id = ?', [$id]);
        if (!$row) $this->k->redirect('/admin/forms');
        $form = $this->forms->decode($row);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $form['slug'] . '-submissions.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $safe = static fn($v) => (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
        fputcsv($out, array_merge(['#', 'التاريخ'], array_map(fn($f) => $f['label'], $form['fields'])));
        foreach ($this->db()->all('SELECT * FROM submissions WHERE form_id = ? ORDER BY id DESC', [$id]) as $s) {
            $d = json_decode((string)$s['data_json'], true) ?: [];
            fputcsv($out, array_merge([$s['id'], $s['created_at']], array_map(fn($f) => $safe($d[$f['name']] ?? ''), $form['fields'])));
        }
        exit;
    }

    private function submissionDelete(int $id): void
    {
        $s = $this->db()->one('SELECT form_id FROM submissions WHERE id = ?', [$id]);
        $this->db()->exec('DELETE FROM submissions WHERE id = ?', [$id]);
        $this->a->flash('تم حذف الرسالة.');
        $this->k->redirect('/admin/forms/' . ($s['form_id'] ?? '') . '/submissions');
    }

    // ---------------------------------------------------------------- users
    private function userEdit(Request $q, ?int $id): void
    {
        $u = $id ? $this->db()->one('SELECT id,name,email,role FROM users WHERE id = ?', [$id]) : ['name' => '', 'email' => '', 'role' => 'author'];
        if ($id && !$u) $this->k->redirect('/admin/users');
        $errors = [];
        if ($q->isPost()) {
            $u = ['name' => $q->str('name'), 'email' => mb_strtolower($q->str('email')), 'role' => $q->str('role')] + ($u ?? []);
            $pass = (string)($q->post['password'] ?? '');
            if ($u['name'] === '') $errors[] = 'الاسم مطلوب.';
            if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'البريد غير صالح.';
            if (!isset(Auth::roles()[$u['role']])) $errors[] = 'الدور غير صالح.';
            if ($this->db()->val('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$u['email'], $id ?? 0])) $errors[] = 'البريد مستخدم.';
            if (($id === null || $pass !== '') && mb_strlen($pass) < 8) $errors[] = 'كلمة المرور 8 أحرف على الأقل.';
            if ($id && $u['role'] !== 'admin' && !$this->db()->val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id <> ?", [$id])) $errors[] = 'يجب بقاء مدير واحد على الأقل.';
            if (!$errors) {
                $row = ['name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
                if ($pass !== '') $row['password'] = Auth::hash($pass);
                if ($id) $this->db()->update('users', $row, 'id = ?', [$id]);
                else $this->db()->insert('users', $row + ['created_at' => $this->db()->now()]);
                $this->a->flash('تم حفظ المستخدم.');
                $this->k->redirect('/admin/users');
            }
        }
        $this->a->render('user_edit.twig', ['u' => $u, 'id' => $id, 'errors' => $errors]);
    }

    private function userDelete(int $id): void
    {
        $self = (int)Auth::user()['id'];
        $u = $this->db()->one('SELECT role FROM users WHERE id = ?', [$id]);
        if ($id === $self) $this->a->flash('لا يمكنك حذف حسابك.', 'err');
        elseif ($u) {
            $this->db()->exec('DELETE FROM users WHERE id = ?', [$id]);
            $this->a->flash('تم حذف المستخدم.');
        }
        $this->k->redirect('/admin/users');
    }

    private function profile(Request $q): void
    {
        $me = Auth::user(); $errors = [];
        if ($q->isPost()) {
            $name = $q->str('name'); $pass = (string)($q->post['password'] ?? '');
            $cur = $this->db()->one('SELECT password FROM users WHERE id = ?', [$me['id']]);
            if ($name === '') $errors[] = 'الاسم مطلوب.';
            if ($pass !== '') {
                if (!password_verify((string)($q->post['current'] ?? ''), $cur['password'])) $errors[] = 'كلمة المرور الحالية غير صحيحة.';
                if (mb_strlen($pass) < 8) $errors[] = 'كلمة المرور الجديدة 8 أحرف على الأقل.';
            }
            if (!$errors) {
                $row = ['name' => $name];
                if ($pass !== '') $row['password'] = Auth::hash($pass);
                $this->db()->update('users', $row, 'id = ?', [$me['id']]);
                $this->a->flash('تم تحديث ملفك الشخصي.');
                $this->k->redirect('/admin/profile');
            }
        }
        $this->a->render('profile.twig', ['errors' => $errors]);
    }

    // ---------------------------------------------------------------- settings
    private function settings(Request $q): void
    {
        $keys = ['site_title', 'site_description', 'lang', 'site_lang', 'home_page', 'posts_per_page', 'upload_max_mb', 'mail_from', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'menu_main', 'menu_footer'];
        $errors = [];
        if ($q->isPost()) {
            $v = [];
            foreach ($keys as $key) $v[$key] = trim((string)($q->post[$key] ?? ''));
            if ($v['site_title'] === '') $errors[] = 'عنوان الموقع مطلوب.';
            if (!in_array($v['lang'], ['ar', 'en'], true)) $v['lang'] = 'ar';
            if (!in_array($v['site_lang'], ['', 'ar', 'en'], true)) $v['site_lang'] = '';
            $v['posts_per_page'] = (string)max(1, min(100, (int)$v['posts_per_page']));
            $v['upload_max_mb'] = (string)max(1, min(100, (int)$v['upload_max_mb']));
            if ($v['mail_from'] !== '' && !filter_var($v['mail_from'], FILTER_VALIDATE_EMAIL)) $errors[] = 'بريد المرسل غير صالح.';
            if (!in_array($v['smtp_secure'], ['tls', 'ssl', 'none'], true)) $v['smtp_secure'] = 'tls';
            if (!$errors) {
                foreach ($v as $key => $val) $this->k->setSetting($key, $val);
                if (($pw = (string)($q->post['smtp_pass'] ?? '')) !== '') $this->k->setSetting('smtp_pass', $pw);
                $active = (string)$this->k->setting('active_theme', 'default');   // imported sites: refresh templates so <html lang dir> follows the language
                if ($active !== 'default' && is_file(\Sahab\Themes::dir($this->k) . '/' . $active . '/site.json')) { try { (new \Sahab\SiteImporter($this->k))->generate($active); } catch (\Throwable) {} }
                $this->a->flash('تم حفظ الإعدادات.');
                $this->k->redirect('/admin/settings');
            }
        }
        $s = [];
        foreach ($keys as $key) $s[$key] = $q->isPost() ? ($q->post[$key] ?? '') : $this->k->setting($key, $key === 'smtp_port' ? '587' : '');
        $pt = $this->a->content->type('page');
        $pages = $pt ? array_map(fn($e) => ['slug' => $e['slug'], 'title' => $e['title']], $this->a->content->list($pt, ['limit' => 100])['items']) : [];
        $this->a->render('settings.twig', ['pages' => $pages, 's' => $s, 'errors' => $errors, 'smtp_pass_set' => (string)$this->k->setting('smtp_pass', '') !== '']);
    }

    /** "Restore factory state": removes every content type, entry, design, form, message, media file, imported site, extra user and setting. Keeps the signed-in administrator. */
    private function factoryReset(Request $q): void
    {
        $me = Auth::user();
        $row = $this->db()->one('SELECT password FROM users WHERE id = ?', [$me['id']]);
        if ($q->str('confirm') !== 'RESET' || !$row || !password_verify((string)($q->post['password'] ?? ''), $row['password'])) {
            $this->a->flash('لم تتم الاستعادة: اكتب RESET بالضبط وأدخل كلمة مرورك الصحيحة.', 'err');
            $this->k->redirect('/admin/settings#reset');
        }
        $db = $this->db();
        $k = $this->k;
        @set_time_limit(120);

        $k->setSetting('active_theme', 'default');
        foreach (array_keys(\Sahab\Themes::all($k)) as $tid) if ($tid !== 'default') \Sahab\Themes::delete($k, (string)$tid);
        foreach (array_keys($this->a->content->types()) as $slug) $this->a->content->deleteType((string)$slug);

        $media = new Media($k);
        foreach ($db->all('SELECT id FROM media') as $m) { try { $media->delete((int)$m['id']); } catch (\Throwable) {} }
        \Sahab\Schema::ensureResets($db);
        foreach (['designs', 'submissions', 'forms', 'webhooks', 'api_tokens', 'rate_limits', 'password_resets', 'role_permissions', 'roles', 'media'] as $tbl) $db->exec('DELETE FROM ' . $db->id($tbl));
        $db->exec('DELETE FROM users WHERE id <> ?', [$me['id']]);
        $db->exec('UPDATE users SET role = ? WHERE id = ?', ['admin', $me['id']]);
        \Sahab\Schema::seedRoles($db);

        $keep = [];
        foreach (['site_title', 'lang', 'admin_host'] as $key) $keep[$key] = (string)$k->setting($key, '');
        $db->exec('DELETE FROM ' . $db->id('settings'));
        foreach (\Sahab\Schema::defaultSettings($keep['site_title'] !== '' ? $keep['site_title'] : 'Sahab', $keep['lang'] ?: 'ar') as $key => $v) $k->setSetting($key, (string)$v);
        if ($keep['admin_host'] !== '') $k->setSetting('admin_host', $keep['admin_host']);

        foreach (['cache', 'logs'] as $sub) {
            $dir = $k->root . '/storage/' . $sub;
            if (is_dir($dir)) foreach (glob($dir . '/*') ?: [] as $p) { if (is_dir($p)) \Sahab\ZipInstaller::rrmdir($p); elseif (basename($p) !== '.htaccess') @unlink($p); }
        }
        foreach (glob($k->root . '/uploads/*') ?: [] as $p) { if (is_dir($p)) \Sahab\ZipInstaller::rrmdir($p); elseif (!in_array(basename($p), ['.htaccess', 'index.html'], true)) @unlink($p); }
        Auth::flush();
        $this->a->flash('تمت استعادة النظام إلى وضعه الافتراضي. حُذفت كل البيانات وبقي حسابك.');
        $this->k->redirect('/admin');
    }

    // ---------------------------------------------------------------- api tokens
    private function api(): void
    {
        $this->a->render('api.twig', [
            'tokens' => $this->db()->all('SELECT id,name,can_write,created_at,last_used FROM api_tokens ORDER BY id DESC'),
            'new_token' => $this->k->flash('new_token'), 'api_base' => $this->k->siteUrl() . '/api',
            'types' => array_values($this->a->content->types()),
        ]);
    }

    private function tokenCreate(Request $q): void
    {
        $name = $q->str('name') ?: 'token';
        $token = bin2hex(random_bytes(24));
        $this->db()->insert('api_tokens', ['name' => mb_substr($name, 0, 100), 'token_hash' => hash('sha256', $token), 'can_write' => $q->str('can_write') === '1' ? 1 : 0, 'created_at' => $this->db()->now()]);
        $this->k->flash('new_token', $token);
        $this->k->redirect('/admin/api');
    }

    private function tokenDelete(int $id): void
    {
        $this->db()->exec('DELETE FROM api_tokens WHERE id = ?', [$id]);
        $this->a->flash('تم حذف المفتاح.');
        $this->k->redirect('/admin/api');
    }
}
