/* First-run guided tour + help. Each page has a short tour shown once (stored locally); the "?" button replays it. */
(function () {
  'use strict';
  var base = (document.querySelector('link[href*="/admin/assets/admin.css"]') || {}).href || '';
  var path = location.pathname.replace(/^.*?\/admin/, '/admin').replace(/\/+$/, '') || '/admin';
  var $ = function (s) { return document.querySelector(s); };
  function visible(el) { if (!el) return false; var r = el.getBoundingClientRect(); return r.width > 4 && r.height > 4 && getComputedStyle(el).visibility !== 'hidden'; }

  var TOURS = {
    dashboard: { match: /^\/admin$/, steps: [
      { t: 'مرحباً بك في سحاب', x: 'نظام يجمع بين نمذجة المحتوى (مثل Strapi) وتصميم الصفحات البصري (مثل Ycode). سنمرّ على أهم الأماكن في دقيقة.' },
      { s: '.rail a[href$="/admin/types"]', t: '١. منشئ الأنواع', x: 'ابدأ هنا: أنشئ أنواع محتواك (مقالات، خدمات، منتجات…) واختر نموذجاً جاهزاً أو ابنِ حقولك بنفسك.' },
      { s: '.rail a[href$="/admin/content"]', t: '٢. مدير المحتوى', x: 'أضف المدخلات وانشرها. داخل كل مدخل زر «فتح المصمم» لتصميم صفحته بالسحب والإفلات.' },
      { s: '.rail a[href$="/admin/site"]', t: '٣. الواجهة', x: 'استورد موقعاً جاهزاً (zip أو HTML) ثم انقر عناصره واربطها بمحتواك.' },
      { s: '.rail a[href$="/admin/blocks"]', t: 'الكتل', x: 'مكوّنات قابلة لإعادة الاستخدام بحقولها الخاصة، تضيفها داخل المصمم في أي صفحة.' },
      { s: '.rail a[href$="/admin/settings"]', t: 'الإعدادات', x: 'الأدوار والصلاحيات، المستخدمون، مفاتيح API، وWebhooks. أنشئ ما تحتاجه بعد بناء الأنواع.' },
      { s: '[data-tour-start]', t: 'تحتاج مساعدة؟', x: 'اضغط هذا الزر في أي صفحة لإعادة الجولة الخاصة بها.' }
    ] },
    types: { match: /^\/admin\/types(\/new)?$/, steps: [
      { s: '.presets', t: 'ابدأ من نموذج جاهز', x: 'اختر مقالات أو خدمات أو مشاريع… فتُعبّأ الحقول تلقائياً، وتعدّل ما تريد قبل الحفظ.' },
      { s: '#type-form .kind-pick', t: 'مجموعة أم مفرد؟', x: 'المجموعة لها عدة مدخلات (مقالات). المفرد مدخل واحد (الصفحة الرئيسية، إعدادات الموقع).' },
      { s: '.designer-toggle', t: 'المصمم المرئي', x: 'فعّله لتظهر لكل مدخل صفحة تصمّمها بالسحب والإفلات، مرتبطة بحقول النوع.' },
      { s: '#field-rows', t: 'الحقول', x: 'كل حقل له نوع (نص، صورة، علاقة…). سمّه بالإنجليزية ليُستخدم في القوالب وواجهة API.' },
      { s: '#add-field', t: 'أضف حقلاً', x: 'اضغط لاختيار نوع الحقل من الشبكة. لا يمكن تغيير اسم الحقل أو نوعه بعد الحفظ.' }
    ] },
    typeedit: { match: /^\/admin\/types\/[a-z0-9_]+$/, steps: [
      { s: '#field-rows', t: 'حقول النوع', x: 'عدّل العناوين والخيارات وأضف حقولاً جديدة. حذف حقل يحذف بياناته.' },
      { s: '.designer-toggle', t: 'المصمم', x: 'فعّله لتصميم صفحات هذا النوع. «تصميم قالب النوع» يطبّق تصميماً واحداً على كل مدخلاته.' },
      { s: '#add-field', t: 'ثم احفظ', x: 'بعد التعديل اضغط حفظ. وتذكّر منح الصلاحيات للأدوار من «الأدوار والصلاحيات».' }
    ] },
    entry: { match: /^\/admin\/content\/[a-z0-9_]+\/(\d+|new)$/, steps: [
      { s: '#entry-form .col-main', t: 'محتوى المدخل', x: 'املأ الحقول. الصور من مكتبة الوسائط، والنص المنسّق له شريط أدوات.' },
      { s: 'button[value="save"]', t: 'حفظ ونشر', x: '«حفظ» يبقي المدخل مسودة. «نشر» يظهره للزوار، و«إلغاء النشر» يعيده مسودة.' },
      { s: 'a[href*="/admin/design/entry/"]', t: 'فتح المصمم', x: 'صمّم صفحة هذا المدخل بصرياً. يبدأ من قالب النوع إن وُجد.' },
      { s: '.col-side', t: 'الرابط والتاريخ', x: 'رابط المدخل (slug) يُولَّد من العنوان ويمكنك تعديله.' }
    ] },
    site: { match: /^\/admin\/site$/, steps: [
      { s: '.upload-card', t: 'استيراد موقع جاهز', x: 'ارفع zip أو ملفات HTML/CSS/JS/صور. سيتحول إلى واجهة ديناميكية تعرض محتواك.' },
      { s: '.upload-card .steps', t: 'الخطوات', x: 'عيّن كل صفحة لعرض، ثم اربط عناصرها بالمحتوى، ثم فعّل الواجهة.' },
      { s: '.theme-grid', t: 'الواجهات المتاحة', x: 'فعّل واجهة، أو افتح «الصفحات والربط» لموقع مستورد.' }
    ] },
    sitefiles: { match: /^\/admin\/site\/[^/]+$/, steps: [
      { s: '.table', t: 'صفحات الموقع', x: 'لكل ملف HTML: «يُعرض كـ» (رئيسية، أرشيف، مفردة، ثابتة). «معاينة وربط» يفتح محرر الربط.' },
      { s: '.table .btn.primary', t: 'ابدأ بالربط', x: 'ستنقر عناصر الصفحة وتربطها بحقول المحتوى أو بقوائم متكررة.' }
    ] },
    blocks: { match: /^\/admin\/blocks$/, steps: [
      { s: '.blocks-intro .card', t: 'ما هي الكتل؟', x: 'أجزاء جاهزة (بطاقة، قسم، تذييل). كتلة المصمم لها «حقول» تملؤها عند إضافتها، أو تربطها بحقول المحتوى.' },
      { s: '.table', t: 'مكتبتك', x: 'تظهر هنا كتلك. أضفها من تبويب «كتل» داخل المصمم.' }
    ] },
    editor: { match: /^\/admin\/design\/\d+$/, steps: [
      { s: '#ed-left', t: 'العناصر والكتل والطبقات', x: 'اسحب عنصراً إلى الصفحة أو اضغط لإضافته. تبويب «كتل» لمكوّناتك، و«الطبقات» لشجرة الصفحة.' },
      { s: '#frame', t: 'لوحة التصميم', x: 'اضغط عنصراً لتحديده. انقر بالزر الأيمن لقائمة سريعة: نسخ، لصق، تغليف، حفظ ككتلة…' },
      { s: '#ed-right', t: 'التنسيق والإعدادات', x: 'التنسيق لكل نقطة استجابة. «الإعدادات» لمحتوى العنصر، وفيها حقول ديناميكية من المدخل وفئات من تصميم موقعك.' },
      { s: '.ed-bp', t: 'الاستجابة', x: 'بدّل بين سطح المكتب والجهاز اللوحي والجوال؛ يُحفظ التنسيق لكل شاشة.' },
      { s: '#d-publish', t: 'احفظ وانشر', x: 'الحفظ مسودة، والنشر يعرض التصميم للزوار. Ctrl+Z للتراجع وCtrl+S للحفظ.' }
    ] },
    mapper: { match: /^\/admin\/site\/[^/]+\/map$/, steps: [
      { s: '#a-kind', t: 'كيف تُعرض هذه الصفحة؟', x: 'عيّنها: رئيسية، أرشيف نوع (قائمة)، صفحة مدخل (مفردة)، أو صفحة ثابتة.' },
      { s: '#p-layers', t: 'الطبقات', x: 'شجرة عناصر الصفحة. اضغط أي عنصر لتحديده، وانقر بالزر الأيمن لربطه سريعاً.' },
      { s: '#frame', t: 'المعاينة', x: 'اضغط عنصراً (نص، صورة، رابط، قائمة) ثم اربطه بحقل. المحاط بالأخضر مربوط، وبالبرتقالي تكرار.' },
      { s: '#ed-right', t: 'لوحة الربط', x: 'اختر نوع الربط والحقل: نص، خاصية، صورة خلفية، تكرار لكل مدخل، قائمة، نموذج، أو حفظ كتلة.' },
      { s: '#m-capture', t: 'صفحة فارغة؟', x: 'إن كان محتوى الصفحة يُبنى بـ JavaScript اضغط «التقاط الصفحة» لتشغيلها وحفظ ناتجها.' },
      { s: '#m-save', t: 'احفظ', x: 'الحفظ يولّد قالب الصفحة فوراً (Ctrl+S).' }
    ] },
    roles: { match: /^\/admin\/roles(\/[a-z0-9_-]+)?$/, steps: [
      { s: '.table, .matrix', t: 'الصلاحيات', x: 'لكل دور وكل نوع محتوى: عرض، إنشاء، تعديل، حذف، نشر. دور «عام» يتحكم بما يراه الزوار عبر الـ API.' }
    ] }
  };

  function pick() { var k, t; for (k in TOURS) { t = TOURS[k]; if (t.match.test(path)) return { key: k, tour: t }; } return null; }

  var state = null;
  function end(done) {
    if (!state) return;
    if (done) { try { localStorage.setItem('sahab-tour:' + state.key, '1'); } catch (e) {} }
    ['tour-hl', 'tour-tip', 'tour-veil'].forEach(function (id) { var e = document.getElementById(id); if (e) e.remove(); });
    window.removeEventListener('resize', place); state = null;
  }
  function el(tag, id, cls) { var e = document.getElementById(id) || document.createElement(tag); e.id = id; e.className = cls || id; if (!e.parentNode) document.body.appendChild(e); return e; }
  function place() {
    if (!state) return;
    var st = state.steps[state.i], target = st.s ? document.querySelector(st.s) : null, ok = target && visible(target);
    var hl = el('div', 'tour-hl'), tip = el('div', 'tour-tip'), veil = el('div', 'tour-veil');
    if (ok) { target.scrollIntoView({ block: 'center', inline: 'nearest' }); var r = target.getBoundingClientRect(); hl.hidden = false; hl.style.left = (r.left - 4) + 'px'; hl.style.top = (r.top - 4) + 'px'; hl.style.width = (r.width + 8) + 'px'; hl.style.height = (r.height + 8) + 'px';
      var x1 = r.left - 4, y1 = r.top - 4, x2 = r.right + 4, y2 = r.bottom + 4;
      veil.style.clipPath = 'polygon(evenodd, 0 0, 100% 0, 100% 100%, 0 100%, 0 0, ' + x1 + 'px ' + y1 + 'px, ' + x1 + 'px ' + y2 + 'px, ' + x2 + 'px ' + y2 + 'px, ' + x2 + 'px ' + y1 + 'px, ' + x1 + 'px ' + y1 + 'px)';
    } else { hl.hidden = true; veil.style.clipPath = 'none'; }
    tip.innerHTML = '';
    var h = document.createElement('strong'); h.textContent = st.t; var p = document.createElement('p'); p.textContent = st.x;
    var nav = document.createElement('div'); nav.className = 'tour-nav';
    var prog = document.createElement('span'); prog.className = 'muted small'; prog.textContent = (state.i + 1) + ' / ' + state.steps.length;
    function btn(txt, cls, fn) { var b = document.createElement('button'); b.type = 'button'; b.className = 'btn sm ' + cls; b.textContent = txt; b.addEventListener('click', fn); return b; }
    nav.appendChild(prog);
    nav.appendChild(btn('تخطي', '', function () { end(true); }));
    if (state.i > 0) nav.appendChild(btn('السابق', '', function () { state.i--; place(); }));
    nav.appendChild(btn(state.i === state.steps.length - 1 ? 'فهمت' : 'التالي', 'primary', function () { if (state.i === state.steps.length - 1) end(true); else { state.i++; place(); } }));
    tip.appendChild(h); tip.appendChild(p); tip.appendChild(nav);
    var w = tip.offsetWidth || 300, hh = tip.offsetHeight || 140, vw = innerWidth, vh = innerHeight, top, left;
    if (ok) {
      var r2 = target.getBoundingClientRect();
      top = r2.bottom + 12 + hh < vh ? r2.bottom + 12 : (r2.top - hh - 12 > 8 ? r2.top - hh - 12 : Math.max(8, vh - hh - 8));
      left = Math.max(8, Math.min(r2.left + r2.width / 2 - w / 2, vw - w - 8));
    } else { top = Math.max(8, (vh - hh) / 2); left = Math.max(8, (vw - w) / 2); }
    tip.style.top = top + 'px'; tip.style.left = left + 'px';
  }
  function start(force) {
    var found = pick(); if (!found) return;
    if (!force) { try { if (localStorage.getItem('sahab-tour:' + found.key)) return; } catch (e) {} }
    end(false);
    state = { key: found.key, steps: found.tour.steps, i: 0 };
    el('div', 'tour-veil'); window.addEventListener('resize', place); place();
  }
  document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-tour-start]'); if (b) { e.preventDefault(); start(true); } });
  document.addEventListener('keydown', function (e) { if (state && e.key === 'Escape') end(true); });
  window.sahabTour = { start: start };
  window.addEventListener('load', function () { setTimeout(function () { start(false); }, 900); });
})();
