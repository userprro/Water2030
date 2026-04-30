# سجل التغييرات - النسخة 2.0
## نظام إدارة توزيع المياه - تقرير التحسينات الشاملة

---

## ✅ الإصلاحات الأمنية العاجلة

### 1. إصلاح Session Fixation (AuthController.php)
- إضافة `session_regenerate_id(true)` فور نجاح تسجيل الدخول لمنع هجمات تثبيت الجلسة.
- تسجيل وقت الدخول `login_time` للتحقق من انتهاء صلاحية الجلسة (8 ساعات).
- تنظيف كامل لبيانات الجلسة عند تسجيل الخروج مع حذف الكوكيز.

### 2. Rate Limiting لمنع Brute Force (AuthController.php)
- تحديد محاولات تسجيل الدخول بـ 10 محاولات كل 15 دقيقة لكل IP.
- إرجاع رسالة خطأ واضحة عند تجاوز الحد.

### 3. إصلاح ثغرة XSS في الجداول (app.js)
- إضافة دالة `escapeHtml()` لتعقيم جميع النصوص القادمة من المستخدمين.
- تطبيق `escapeHtml` في دالة `buildTable` على جميع القيم النصية.
- إضافة تنسيق بصري للصفوف الملغاة (خط وسطي + خلفية حمراء فاتحة).

### 4. تفعيل Period Middleware (api.php)
- ربط `PeriodMiddleware` بجميع مسارات الكتابة (POST/PUT) للرحلات والفواتير والتسويات والمصروفات.
- تصحيح خطأ SQL: تغيير `is_closed = 1` إلى `is_closed = true` للتوافق مع PostgreSQL.
- إضافة fallback لتاريخ اليوم عند غياب حقل التاريخ في الطلب.

### 5. تأمين إعدادات الجلسة (api.php)
- إضافة `httponly = true` و `samesite = Lax` لكوكيز الجلسة.

---

## ✅ إصلاحات المنطق المحاسبي

### 6. نظام الإلغاء الآمن للفواتير - Void Invoice (InvoiceController.php)
- إضافة دالة `void()` تُلغي الفاتورة وتعكس جميع التأثيرات المالية:
  - خصم `due_amount` من رصيد العميل.
  - تسجيل حركة "إلغاء فاتورة" في الصندوق لعكس النقد المحصّل.
  - تخفيض `total_lifetime_paid` للعميل.
- منع الحذف المباشر: يجب إلغاء الفاتورة أولاً قبل حذفها نهائياً.
- إضافة تحقق: المبلغ المدفوع لا يمكن أن يتجاوز صافي الفاتورة.

### 7. نظام الإلغاء الآمن للتسويات - Void Settlement (SettlementController.php)
- إضافة دالة `void()` تُلغي سند التصفية وتعكس جميع التأثيرات:
  - إرجاع المبالغ المخصومة لأرصدة العملاء.
  - تسجيل حركة "إلغاء تصفية" في الصندوق.
  - عكس `total_lifetime_paid` لجميع العملاء المرتبطين.

### 8. إصلاح Race Condition في الصندوق (FundTransactionModel.php)
- استخدام `pg_advisory_xact_lock` لضمان التسلسل في قراءة وكتابة رصيد الصندوق.
- استخدام `SELECT ... FOR UPDATE` لقفل الصف الأخير أثناء الحساب.

### 9. إضافة دوال Void في FundService (FundService.php)
- `onVoidInvoice()`: تسجيل حركة خروج لعكس فاتورة ملغاة.
- `onVoidSettlement()`: تسجيل حركة خروج لعكس تصفية ملغاة.

---

## ✅ تحسينات الواجهة الأمامية

### 10. رسوم بيانية في لوحة القيادة (pages.js)
- إضافة **مخطط شريطي** لمبيعات آخر 7 أيام (نقدي / آجل) باستخدام Chart.js.
- إضافة **مخطط دائري** لتوزيع مبيعات اليوم (نقدي مقابل آجل).
- تحميل Chart.js ديناميكياً عند الحاجة فقط (لا يؤثر على سرعة التحميل الأولي).

### 11. صفحة مفاضلة السائقين (pages.js)
- صفحة جديدة `leaderboard` تعرض ترتيب السائقين بناءً على:
  - عدد الرحلات (30%)
  - النقد المحصّل (40%)
  - نسبة التحصيل (30%)
- عرض شريط تقدم بصري لكل سائق مع ألوان تدل على مستوى الأداء.
- فلترة بالفترة الزمنية (من/إلى).

### 12. إضافة رابط مفاضلة السائقين في القائمة (dashboard.html)
- إضافة رابط "🏆 مفاضلة السائقين" في قسم التقارير بالقائمة الجانبية.

---

## ✅ ميزات جديدة

### 13. تصدير التقارير إلى Excel/CSV (ReportController.php)
- دالة `exportExcel()` تدعم تصدير:
  - ملخص المبيعات اليومي
  - كشف حساب العميل
- الملفات تحتوي على BOM لضمان ظهور العربية بشكل صحيح في Excel.

### 14. دالة exportExcel في الواجهة (pages.js)
- دالة `exportExcel(type, extraParams)` لتنزيل التقارير مباشرة.

---

## ✅ تحديثات قاعدة البيانات (db_migration_v2.sql)

### 15. أعمدة Void الجديدة
- إضافة `is_voided`, `voided_at`, `voided_by` لجدولي `Invoices` و `Driver_Settlements`.

### 16. قيود CHECK لمنع الأرقام السالبة
- إضافة CHECK constraints على جميع الحقول المالية في `Invoices`, `Settlement_Details`, `Expenses`, `Customers`.

### 17. فهارس لتحسين الأداء
- إضافة 13 فهرساً (Index) على الحقول الأكثر استخداماً في الاستعلامات لتسريع النظام عند زيادة البيانات.

---

## 📁 ملفات المشروع المعدّلة

| الملف | التغييرات |
|-------|-----------|
| `controllers/AuthController.php` | Session regeneration, Rate limiting, Secure logout |
| `controllers/InvoiceController.php` | Void function, paid > net validation |
| `controllers/SettlementController.php` | Void function with full reversal |
| `controllers/DriverController.php` | Leaderboard function |
| `controllers/ReportController.php` | exportExcel function |
| `services/FundService.php` | onVoidInvoice, onVoidSettlement |
| `models/FundTransactionModel.php` | Race condition fix with advisory lock |
| `middleware/period.php` | PostgreSQL boolean fix, today fallback |
| `public/api.php` | Period middleware activated, new routes |
| `public/js/app.js` | escapeHtml function, XSS protection in buildTable |
| `public/js/pages.js` | Chart.js dashboard, Leaderboard page, exportExcel |
| `public/dashboard.html` | Leaderboard nav link |
| `db_migration_v2.sql` | **NEW** - Run once to update database |

---

## 🚀 تعليمات التحديث

1. **نسخ احتياطية:** قم بعمل نسخة احتياطية من قاعدة البيانات والملفات الحالية.
2. **تطبيق Migration:** شغّل ملف `db_migration_v2.sql` على قاعدة البيانات:
   ```bash
   psql -U postgres -d waterdb -f db_migration_v2.sql
   ```
3. **رفع الملفات:** استبدل ملفات المشروع بالنسخة الجديدة.
4. **مسح الكاش:** امسح كاش المتصفح لضمان تحميل الملفات الجديدة.
