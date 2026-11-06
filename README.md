<div align="center">

# سحاب CMS · Sahab CMS

**نظام إدارة محتوى عربي خفيف، مفتوح المصدر، بـ PHP نقي + Twig — يجمع نمذجة المحتوى على طريقة Strapi مع التصميم البصري على طريقة Ycode.**

**A light, open-source, Arabic-first CMS in plain PHP + Twig — Strapi-style content modeling meets Ycode-style visual design.**

PHP 8.1+ · SQLite / MySQL · MIT · RTL & LTR · Arabic / English admin

</div>

---

## العربية

### الفكرة
تثبيت **نظيف تماماً**: لا أنواع محتوى ولا صفحات جاهزة. تبني نظامك بنفسك:

1. **منشئ الأنواع (Content-Type Builder)** — نوع مجموعة أو نوع مفرد، بحقول (نص، نص طويل، منسّق، رقم، تاريخ، قائمة، وسائط، علاقة، UID، JSON…). كل نوع = جدول حقيقي + REST API تلقائي. مع **نماذج جاهزة** (مقالات، خدمات، مشاريع، منتجات، فعاليات، فريق، أسئلة شائعة، آراء، وظائف، صفحات…).
2. **مدير المحتوى** — مسودة/نشر، بحث وتصفية، وداخل كل مدخل زر **«فتح المصمم»** لتصميم صفحته بالسحب والإفلات.
3. **المصمم المرئي** — طبقات، Flex/Grid، نقاط استجابة (سطح مكتب/لوحي/جوال)، Hover، ربط العناصر بحقول المدخل، قوائم CMS، قائمة بالزر الأيمن، وقالب لكل نوع. يرث تصميم الموقع المستورد (CSS والخطوط والفئات).
4. **الواجهة (استيراد موقع جاهز)** — ارفع zip أو ملفات HTML/CSS/JS، عيّن كل صفحة لعرض (رئيسية، أرشيف، مفردة، ثابتة)، ثم **انقر العناصر في المعاينة واربطها** بالحقول: نص، HTML، خصائص، صورة خلفية، **تكرار لكل مدخل**، قوائم، نماذج. يُولَّد قالب Twig تلقائياً. صفحات تُبنى بـ JavaScript؟ زر «التقاط الصفحة».
5. **الكتل (Blocks)** — مكوّنات قابلة لإعادة الاستخدام بحقولها الخاصة (نص، صورة، رابط، لون…)، من المصمم أو من عنصر في الموقع المستورد، تُضاف في أي تصميم وتتحدّث في كل مكان.
6. **الأدوار والصلاحيات** — مصفوفة (دور × نوع × عرض/إنشاء/تعديل/حذف/نشر) + دور Public للـ API، ومفاتيح API، وWebhooks (HMAC-SHA256)، ومستخدمون، ووسائط، ونماذج تواصل مع رسائلها.
7. **لغتان** — واجهة اللوحة بالعربية أو الإنجليزية، و«لغة الموقع» مستقلة؛ تُحقن `lang` و`dir` في كل صفحات الموقع.

### التثبيت
```bash
git clone https://github.com/mohBasalzaki/Sahab-CMS.git
cd Sahab-CMS
php -S 127.0.0.1:8080 index.php      # للتجربة المحلية
```
ثم افتح `http://127.0.0.1:8080` ← معالج التثبيت (SQLite أو MySQL) ← `/admin`.
على استضافة عادية: ارفع المجلد كاملاً (`vendor/` مرفق، لا حاجة لـ Composer) وافتح الموقع.

**المتطلبات:** PHP 8.1+ مع `pdo_sqlite` أو `pdo_mysql`، `mbstring`، `fileinfo`، `dom`؛ اختيارياً `zip` (استيراد المواقع) و`gd` (المصغّرات). اجعل `storage/` و`uploads/` و`themes/` قابلة للكتابة.

**Apache:** يعمل `.htaccess` المرفق. **Nginx:** امنع الوصول إلى `core/ vendor/ storage/ lang/ config.php` و`themes/*/source` و`themes/*/templates` و`themes/*/site.json`، ومرّر الباقي إلى `index.php`.

### REST API (بنمط Strapi)
```
GET    /api/{type}?filters[title][$contains]=كلمة&sort=published_at:desc&pagination[page]=1&pagination[pageSize]=25
GET    /api/{type}/{id|slug}
POST   /api/{type}        {"data": {...}}   Authorization: Bearer TOKEN
PUT    /api/{type}/{id}   {"data": {...}}
DELETE /api/{type}/{id}
GET    /api/content-types
```
الأنواع المفردة: `GET|PUT|DELETE /api/{type}`. الوصول العام يحدده دور **Public**. أحداث Webhooks: `entry.create` `entry.update` `entry.delete` `entry.publish` `entry.unpublish` `media.create`.

### الأمان
CSRF على كل نموذج، تحديد معدل المحاولات، كلمات مرور `password_hash`، **استعادة كلمة المرور** برابط لمرة واحدة صالح ساعة (دون كشف وجود الحساب)، قوالب Twig في **Sandbox** بقائمة بيضاء، تنقية HTML، رؤوس CSP لصفحات اللوحة، فحص الملفات المرفوعة (MIME/الأبعاد)، ومنع الوصول المباشر للمجلدات الداخلية. لإبلاغ عن ثغرة افتح Issue خاصاً أو راسل المشرفين قبل النشر.

### استعادة الوضع الافتراضي
**الإعدادات ← استعادة النظام للوضع الافتراضي**: تحذف كل المحتوى والأنواع والتصاميم والوسائط والمواقع المستوردة والمستخدمين الآخرين، وتبقي حسابك. تتطلب كتابة `RESET` وكلمة المرور.

### التصميم
بنية **IBM Carbon** (أسطح بيضاء على رمادي فاتح، حدود 1px، بلا ظلال) بألوان العلامة: أزرق `#221cfc` وحبر كحلي `#0a0178`، وزوايا 4px/6px. الخط **IBM Plex Sans Arabic** (+ IBM Plex Sans/Mono) والأيقونات **Hugeicons**. الأرقام لاتينية دائماً، والوضع الداكن اختياري.

### الهيكل
```
core/      النواة (Db, Content, Design, SiteImporter, Auth, Front, Api, ...)
admin/     قوالب اللوحة (Twig) والأصول (JS/CSS/الخطوط/الأيقونات)
themes/    الواجهات (default + المواقع المستوردة تُنشأ هنا)
lang/      الترجمات (en-*.php؛ المفتاح هو النص العربي)
storage/   قاعدة SQLite والجلسات والسجلات والتخزين المؤقت (غير مُتتبَّع)
uploads/   ملفات الوسائط (غير مُتتبَّع)
```

### المساهمة والترخيص
المساهمات مرحّب بها (Issues / Pull Requests). الترخيص **MIT** للكود. الخطوط IBM Plex بترخيص SIL OFL، وأيقونات Hugeicons (الإصدار المجاني) بترخيصها المرفق في `admin/assets/HUGEICONS-LICENSE.md`.

---

## English

### What it is
A **clean-slate** CMS: no content types or pages ship with it — you build your own system.

1. **Content-Type Builder** — collection or single types with rich field types; every type is a real table and gets a REST API. Ready-made **presets** (articles, services, projects, products, events, team, FAQ, testimonials, jobs, pages…).
2. **Content Manager** — draft/publish, search/filter, and an **Open designer** button inside each entry.
3. **Visual designer** — layers, Flex/Grid, breakpoints, hover, field binding, CMS lists, right-click menu, per-type templates; it inherits the imported site's CSS, fonts and classes.
4. **Frontend importer** — upload a zip or HTML/CSS/JS, assign each page a view (home, archive, single, static), then **click elements in the preview to bind them** to fields (text, HTML, attributes, background image, repeat per entry, menus, forms). A Twig template is generated automatically. JS-rendered pages can be captured.
5. **Blocks** — reusable components with their own fields (text, image, URL, color…), created in the designer or from an element of the imported site.
6. **Roles & permissions** matrix, Public role for the API, API tokens, HMAC-signed webhooks, users, media library, contact forms.
7. **Two languages** — the admin panel in Arabic or English, plus an independent **site language**; `lang` and `dir` are injected into every site page.

### Install
```bash
git clone https://github.com/mohBasalzaki/Sahab-CMS.git
cd Sahab-CMS
php -S 127.0.0.1:8080 index.php
```
Open `http://127.0.0.1:8080`, run the installer (SQLite or MySQL), then go to `/admin`. On shared hosting upload the whole folder (`vendor/` is included; Composer is not required).

**Requirements:** PHP 8.1+, `pdo_sqlite` or `pdo_mysql`, `mbstring`, `fileinfo`, `dom`; optional `zip` and `gd`. `storage/`, `uploads/` and `themes/` must be writable. Apache works out of the box via `.htaccess`; on Nginx deny `core/ vendor/ storage/ lang/ config.php`, `themes/*/source`, `themes/*/templates`, `themes/*/site.json` and route everything else to `index.php`.

### REST API
Strapi-style filters, sorting and pagination; see the endpoint list in the Arabic section above. Public access is controlled by the **Public** role; writes require a Bearer API token.

### Security
CSRF protection, login/reset rate limiting, `password_hash`, one-time **password reset** links (1 hour, no account enumeration), sandboxed Twig with an allow-list, HTML sanitizing, CSP headers on admin pages, upload validation, and blocked direct access to internal folders.

### Factory reset
**Settings → Restore the system to its default state** deletes all content, types, designs, media, imported sites and other users while keeping your account. It requires typing `RESET` and your password.

### Design
IBM Carbon structure with brand colors — blue `#221cfc`, navy ink `#0a0178`, 4px/6px corners, hairlines, no shadows. **IBM Plex Sans Arabic** (+ IBM Plex Sans/Mono) and **Hugeicons**. Latin digits everywhere; dark mode is opt-in.

### Contributing & license
Issues and pull requests are welcome. Code is **MIT**; IBM Plex fonts are SIL OFL; Hugeicons (free) keep their own license in `admin/assets/HUGEICONS-LICENSE.md`.