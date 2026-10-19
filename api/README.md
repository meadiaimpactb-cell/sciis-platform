<div dir="rtl">

# واجهة برمجة التطبيقات لمنصة المجلس السعودي للمستثمرين الاجتماعيين (SCIIS API)

تطبيق **Laravel 12 (PHP 8.3) — API فقط** يخدم موقع Nuxt وتطبيق Flutter عبر `/api/v1`. مرجع البناء الملزم هو `docs/SPEC.md` (المواصفة المشتركة v3.0)، وأكواد المتطلبات (ACC-01، OPP-03، …) مذكورة في تعليقات الكود والاختبارات.

> **تذكير حدود النطاق:** المنصة أداة ربط فقط. المدفوعات — إن فُعّلت — تقتصر على رسوم العضوية والخدمات المدفوعة والتدريب والرعاية. لا تجمع المنصة أموال المستثمرين، ولا تدير حسابات ضمان، ولا تُصدر أوراقًا مالية (SPEC §0).

---

## 1. المتطلبات

| العنصر | الإصدار |
|---|---|
| PHP | 8.3+ مع الامتدادات: `pdo_pgsql`, `redis`, `gd`, `intl`, `zip`, `bcmath`, `mbstring`, `openssl` |
| Composer | 2.x |
| PostgreSQL | 16 |
| Redis | 7 |
| تخزين كائنات متوافق مع S3 | MinIO محليًا — حاوية داخل المملكة في الإنتاج |
| Docker (اختياري) | Compose v2 |

---

## 2. التشغيل محليًا

```bash
cd api
cp .env.example .env
composer install
php artisan key:generate

# قاعدة البيانات + البيانات الأساسية (الأدوار، التصنيفات، معايير التقييم، الإعدادات، الصفحات القانونية)
php artisan migrate --seed

# بيانات تجريبية افتراضية (SPEC §11) — للبيئات غير الإنتاجية فقط
php artisan db:seed --class=DemoSeeder

# خادم التطوير
php artisan serve            # http://localhost:8000/api/v1/health
php artisan horizon          # معالجة الطوابير (الإشعارات، فحص الملفات، التصدير)
php artisan schedule:work    # تذكيرات SLA اليومية + تنظيف البيانات الأسبوعي
```

### عبر Docker

```bash
cp docker/docker-compose.example.yml docker-compose.yml   # ثم عدّل المسارات إن لزم
docker compose up -d --build
docker compose exec api php artisan migrate --seed
```

تتضمن الحزمة: `postgres` و`redis` و`minio` و`mailpit` (معاينة البريد على المنفذ 8025) و`api` (php-fpm) و`nginx` (المنفذ 8000) و`horizon` و`scheduler`.

---

## 3. حسابات العرض التجريبي

بعد `DemoSeeder`، كلمة المرور لجميع الحسابات: `Password123!`

| الدور | البريد |
|---|---|
| مدير النظام | `admin@sciis.test` |
| مسؤول العضوية | `membership@sciis.test` |
| المراجع | `reviewer@sciis.test` |
| المقيّمان | `evaluator1@sciis.test` / `evaluator2@sciis.test` |
| مسؤول الربط | `matching@sciis.test` |
| مستثمر فرد (عضوية معتمدة) | `abdullah@investor.test` |
| مكتب عائلي (عضوية معتمدة) | `office@investor.test` |
| صندوق (طلب عضوية قيد المراجعة) | `fund@investor.test` |
| أصحاب مشاريع | `sara@owner.test` / `khalid@owner.test` / `noura@owner.test` |

> حسابات فريق المجلس تتطلب تفعيل المصادقة الثنائية (ACC-05) قبل الوصول إلى `/admin/*`. للتجربة المحلية يمكن تعطيل الإلزام عبر `SCIIS_2FA_ENFORCE_STAFF=false`.

---

## 4. مفاتيح البيئة (`.env`)

| المفتاح | الوصف | الافتراضي |
|---|---|---|
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | اللغة الأساسية / الاحتياطية | `ar` / `en` |
| `FRONTEND_URL` | عنوان تطبيق Nuxt (الروابط في الإشعارات) | `http://localhost:3000` |
| `SANCTUM_STATEFUL_DOMAINS` | نطاقات الجلسة بالكوكيز (SPA) | `localhost:3000,...` |
| `CORS_ALLOWED_ORIGINS` | الأصول المسموح لها | `http://localhost:3000` |
| `DB_*` | اتصال PostgreSQL | — |
| `REDIS_*` | اتصال Redis (الطوابير، الكاش، الجلسات) | — |
| `DOCUMENTS_DISK` | قرص المستندات السرية (`s3` أو `local`) | `s3` |
| `AWS_*` | بيانات S3/MinIO (`AWS_ENDPOINT` لـ MinIO) | — |
| `FEATURE_PAYMENTS` | تفعيل وحدة المدفوعات (المسارات تُرجع 404 عند الإيقاف) | `false` |
| `FEATURE_ENGLISH` | قبول `Accept-Language: en` | `false` |
| `FEATURE_BROWSE_ALL` / `FEATURE_WATERMARK` / `FEATURE_DARK_MODE` | أعلام ميزات | `false` |
| `SCIIS_2FA_ENFORCE_STAFF` | إلزام TOTP لفريق المجلس | `true` |
| `SCIIS_SMS_DRIVER` | `log` أو `unifonic` | `log` |
| `UNIFONIC_APP_SID` / `UNIFONIC_SENDER_ID` | بيانات مزوّد الرسائل | — |
| `SCIIS_SCAN_DRIVER` | فحص الفيروسات: `none` أو `clamav` | `none` |
| `CLAMAV_HOST` / `CLAMAV_PORT` | خادم clamd | `127.0.0.1:3310` |
| `SCIIS_SIGNED_URL_MINUTES` | صلاحية روابط التنزيل الموقّعة | `10` |
| `PAYMENT_GATEWAY` | `moyasar` أو `fake` | `moyasar` |
| `MOYASAR_SECRET_KEY` / `MOYASAR_PUBLISHABLE_KEY` / `MOYASAR_WEBHOOK_SECRET` | بيانات بوابة الدفع | — |
| `HORIZON_PATH` | مسار لوحة Horizon (لمدير النظام فقط) | `horizon` |

الإعدادات التشغيلية القابلة للتعديل بدون كود (مدد SLA، سياسة الوصول، طريقة عرض مبلغ التمويل، حدود الملفات، مدد الاحتفاظ، …) تُدار من جدول `settings` عبر `PUT /admin/settings` (SPEC §7).

---

## 5. الأوامر المهمة

| الأمر | الوظيفة |
|---|---|
| `php artisan migrate --seed` | إنشاء الجداول + البيانات الأساسية |
| `php artisan db:seed --class=DemoSeeder` | بيانات تجريبية افتراضية |
| `php artisan horizon` | تشغيل معالج الطوابير |
| `php artisan schedule:work` | تشغيل المجدول محليًا |
| `php artisan sciis:sla-reminders [--dry-run]` | إرسال تذكيرات تجاوز SLA للفريق (ADM-03) |
| `php artisan privacy:purge [--dry-run]` | إخفاء هوية البيانات بعد مدد الاحتفاظ (PRV-04) |
| `php artisan sciis:import-legacy legacy.csv [--dry-run] [--invite] [--require-consent]` | ترحيل بيانات نموذج «انضم للمجلس» القديم (WordPress) كحسابات غير مفعّلة تنتظر تحقق أصحابها وموافقتهم (القرار أ-7) |
| `php artisan test` أو `./vendor/bin/pest` | تشغيل الاختبارات (SQLite في الذاكرة) |
| `./vendor/bin/pint` | تنسيق الكود (PSR-12 + strict types) |
| `composer audit` | فحص الثغرات في الحزم (SEC-02) |

---

## 6. بنية المشروع

```
api/
├── app/
│   ├── Enums/                 # تعدادات SPEC §2 (string-backed) مع label() مترجم
│   ├── Models/                # النماذج + العلاقات + HasUuid + النطاقات (مثل Opportunity::visibleTo)
│   ├── Services/              # منطق الأعمال:
│   │   ├── OpportunityStateMachine   # ADM-01 — الطريقة الوحيدة لتغيير حالة الفرصة
│   │   ├── MembershipService         # INV-02 / ADM-06
│   │   ├── InterestService           # الموافقة المزدوجة: اهتمام → قرار المالك → NDA → منح
│   │   ├── EvaluationService         # EVL-01..03 — مقيّمان، بوابة تعارض المصالح، مقارنة
│   │   ├── MatchingService           # MAT-02 — درجة التوافق = عدد المعايير المتطابقة
│   │   ├── TeaserService, DocumentService, NotificationService, OtpService
│   │   ├── SettingsService (مع كاش), ReportService (RPT-01), AuditLogger (AUD)
│   │   ├── PaymentService + Payments/Gateways/{PaymentGateway, MoyasarGateway, FakeGateway}
│   │   └── Sms/{SmsSender, LogSmsSender, UnifonicSmsSender}
│   ├── Http/
│   │   ├── Controllers/Api/V1/{Auth,Investor,Entrepreneur,Admin,Public,Payments,System}
│   │   ├── Requests/          # Form Requests مع رسائل عربية
│   │   ├── Resources/         # تطبيق مصفوفة الحساسية SPEC §5 (Card / Detail / Full)
│   │   └── Middleware/        # RequestId, SetLocale, verified.full, member.approved, 2fa.enforced, feature
│   ├── Policies/              # تحقق على مستوى السجل (ACC-04)
│   ├── Notifications/         # OTP، تغيّر الحالة، الاستكمال، الترشيح، قرار الاهتمام، الاجتماع (.ics)، …
│   ├── Jobs/ScanDocumentJob   # فحص الملفات (ClamAV خلف إعداد)
│   ├── Console/Commands/      # sciis:sla-reminders ، privacy:purge ، sciis:import-legacy
│   └── Support/               # Ics (RFC 5545)، helpers، معالج request_id للسجلات
├── bootstrap/app.php          # التهيئة بنمط Laravel 11/12 + عرض الأخطاء JSON
├── config/                    # app, auth, cache, cors, database, filesystems, logging(json), queue, sanctum, permission, horizon, sciis
├── database/
│   ├── migrations/            # كل جداول SPEC §4 + محفّز عدم قابلية تعديل audit_logs (PostgreSQL)
│   ├── factories/             # User, Opportunity, Document, Interest
│   └── seeders/               # RolePermission, Taxonomy, EvaluationCriteria, Settings, LegalPages, Demo
├── lang/{ar,en}/              # enums, messages, notifications, validation, reports (+ ar.json)
├── routes/api.php             # جميع مسارات SPEC §6 بأسماء ثابتة (v1.*)
├── tests/                     # Pest — آلة الحالات، تسرّب الحساسية، الموافقة المزدوجة، وصول المقيّم، التوافق، توقيع الويب هوك
├── docker/                    # nginx.conf + docker-compose.example.yml
└── Dockerfile                 # php-fpm 8.3 + pdo_pgsql, redis, gd, intl, zip
```

---

## 7. مبادئ التنفيذ

- **آلة الحالات** (`OpportunityStateMachine`): تتحقق من خريطة الانتقالات (SPEC §3)، والصلاحية، وشروط الحراسة (`eligible` يتطلب قرار تقييم + ملخص معتمد من الطرفين؛ `connected` يتطلب اهتمامًا بحالة `connected`)، وتسجّل الانتقال وسجل التدقيق، وتطلق حدث `OpportunityStatusChanged` للإشعارات.
- **الحساسية** (SPEC §5): ثلاثة Resources — `OpportunityCardResource` (البطاقة)، `OpportunityDetailResource` (حسب مستوى الوصول: `approved_member` / `granted` / `connected`)، `OpportunityFullResource` (المالك والفريق). اختبار `SensitivityLeakTest` يضمن عدم تسرّب أي حقل سري أو شخصي دون مستواه (MSG-01).
- **الموافقة المزدوجة** (ENT-06): لا تُفتح المستندات إلا بوجود `file_access_grants` نشط = موافقة المالك + موافقة NDA مسجّلة في `consents` (غير قابلة للتعديل، PRV-02). هوية المستثمر لا تُكشف للمالك قبل حالة `connected` (MSG-01).
- **التدقيق** (AUD-01..03): كل عملية تعديل تمر عبر `AuditLogger`؛ جدول `audit_logs` محمي بمحفّز PostgreSQL يمنع التعديل والحذف، والنموذج يرفضهما أيضًا.
- **المستندات** (DOC-01..03): رفع إلى القرص المحدد مع `sha256`، حدود الحجم/الأنواع من الإعدادات، فحص غير متزامن، روابط موقّعة مؤقتة، وسجل وصول لكل عرض/تنزيل (INV-04).
- **التقارير** (RPT-01): تُحسب من قاعدة البيانات بدون أهداف ثابتة، وتصدير xlsx مسجّل في التدقيق ومقيّد بالأدوار من الإعدادات.
- **الخصوصية** (PRV-04): `privacy:purge` يخفي الهوية ولا يحذف، ولا يمس سجلات التدقيق.

---

## 8. الاختبارات

```bash
php artisan test
# أو مع التغطية
./vendor/bin/pest --coverage
```

تغطي الاختبارات: الانتقالات المسموحة والممنوعة، كتابة سجل التدقيق عند الانتقال، عدم تسرّب الحقول السرية في الـ Resources، تدفّق الموافقة المزدوجة، وصول المقيّم على مستوى السجل وبوابة تعارض المصالح، حساب درجة التوافق واستبعاد تعارض المصالح، رفض الويب هوك ذي التوقيع الخاطئ، وتدفّق التسجيل والتقديم.

</div>
