<?php
declare(strict_types=1);

namespace Sahab;

/** System tables. Content-type tables (ct_*) are managed by Content. */
final class Schema
{
    public static function install(Db $db): void
    {
        $pk = $db->pk(); $str = $db->type('str'); $text = $db->type('text'); $int = $db->type('int');
        $k191 = $db->driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';

        $db->createTable('users', "id $pk, name $str NOT NULL, email $k191 NOT NULL, password $str NOT NULL, role $k191 NOT NULL DEFAULT 'author', created_at $str, last_login $str, UNIQUE (email)");
        $db->createTable('settings', "k $k191 NOT NULL PRIMARY KEY, v $text");
        $db->createTable('content_types', "id $pk, slug $k191 NOT NULL, name $str NOT NULL, plural $str NOT NULL, kind $k191 NOT NULL DEFAULT 'collection', designer $int DEFAULT 0, schema_json $text, created_at $str, UNIQUE (slug)");
        $db->createTable('roles', "id $pk, slug $k191 NOT NULL, name $str NOT NULL, description $str, is_system $int DEFAULT 0, UNIQUE (slug)");
        $db->createTable('role_permissions', "id $pk, role $k191 NOT NULL, subject $k191 NOT NULL, action $k191 NOT NULL");
        $db->pdo->exec('CREATE INDEX idx_roleperm ON ' . $db->id('role_permissions') . ' (' . $db->id('role') . ')');
        $db->createTable('webhooks', "id $pk, name $str NOT NULL, url $str NOT NULL, events $str, secret $str, enabled $int DEFAULT 1, created_at $str");
        $db->createTable('designs', "id $pk, kind $k191 NOT NULL DEFAULT 'entry', type $k191, entry_id $int, title $str NOT NULL, status $k191 NOT NULL DEFAULT 'draft', layout $k191 NOT NULL DEFAULT 'theme', tree_json $text, live_json $text, meta_json $text, created_at $str, updated_at $str");
        $db->createTable('media', "id $pk, path $str NOT NULL, original $str, mime $str, size $int, width $int, height $int, alt $str, user_id $int, created_at $str");
        $db->createTable('forms', "id $pk, slug $k191 NOT NULL, name $str NOT NULL, fields_json $text, email_to $str, success_msg $str, submit_label $str, created_at $str, UNIQUE (slug)");
        $db->createTable('submissions', "id $pk, form_id $int NOT NULL, data_json $text, ip $str, created_at $str, is_read $int DEFAULT 0");
        $db->createTable('api_tokens', "id $pk, name $str NOT NULL, token_hash $k191 NOT NULL, can_write $int DEFAULT 0, created_at $str, last_used $str, UNIQUE (token_hash)");
        $db->createTable('rate_limits', "id $pk, k $k191 NOT NULL, t $int NOT NULL");
        $db->pdo->exec('CREATE INDEX idx_rate ON ' . $db->id('rate_limits') . ' (' . $db->id('k') . ')');
        self::ensureResets($db);
    }

    /** Password-reset tokens (created lazily so existing installs get the table too). */
    public static function ensureResets(Db $db): void
    {
        $pk = $db->pk(); $str = $db->type('str'); $int = $db->type('int');
        $k191 = $db->driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';
        $db->createTable('password_resets', "id $pk, user_id $int NOT NULL, token_hash $k191 NOT NULL, expires_at $int NOT NULL, used $int DEFAULT 0, created_at $str, UNIQUE (token_hash)");
    }

    public static function seedRoles(Db $db): void
    {
        foreach ([
            ['admin', 'مدير', 'صلاحيات كاملة (Super Admin)', 1], ['editor', 'محرر', 'يدير كل المحتوى والوسائط', 1],
            ['author', 'كاتب', 'يدير محتواه فقط', 1], ['viewer', 'مشاهد', 'عرض فقط', 1], ['public', 'عام (Public)', 'الزوار غير المسجلين عبر الـ API', 1],
        ] as [$s, $n, $d, $sys]) $db->insert('roles', ['slug' => $s, 'name' => $n, 'description' => $d, 'is_system' => $sys]);
    }

    public static function defaultSettings(string $title, string $lang): array
    {
        return [
            'site_title' => $title, 'site_description' => '', 'lang' => $lang, 'site_lang' => '', 'active_theme' => 'default', 'home_page' => '',
            'upload_max_mb' => '8', 'posts_per_page' => '10', 'menu_main' => 'الرئيسية|/', 'menu_footer' => '', 'installed_version' => Kernel::VERSION,
        ];
    }

    public static function seed(Kernel $k, array $site, array $admin): void
    {
        $db = $k->db;
        $now = $db->now();
        $db->insert('users', [
            'name' => $admin['name'], 'email' => mb_strtolower($admin['email']),
            'password' => Auth::hash($admin['password']), 'role' => 'admin', 'created_at' => $now,
        ]);
        self::seedRoles($db);
        foreach (self::defaultSettings($site['title'], $site['lang']) as $key => $v) {
            $k->setSetting($key, (string)$v);
        }

        // Clean slate: no content types, forms or entries. The owner builds the system in the Content-Type Builder.
        Auth::flush();
    }
}
