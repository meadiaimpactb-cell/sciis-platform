# منصة المجلس السعودي للمستثمرين الاجتماعيين — واجهة الويب

واجهة الويب لمنصة SCIIS مبنية بـ **Nuxt 3 + Vue 3 + TypeScript + Tailwind CSS**، عربية RTL افتراضيًا مع تبديل إلى الإنجليزية (`/en/...`). المرجع الملزم للتنفيذ هو `../docs/SPEC.md` (المكدس §0، التعدادات §2، دورة الحياة §3، مصفوفة الحساسية §5، واجهة REST §6، الإعدادات §7، رموز الهوية §10، البيانات الافتراضية §11).

---

## 1. التشغيل السريع

المتطلبات: **Node 22** و npm.

```bash
cd web
cp .env.example .env     # وضع المحاكاة مفعّل افتراضيًا
npm install
npm run dev              # http://localhost:3000
```

ثم افتح **`/prototype`** — صفحة «خريطة الشاشات» التي تجمع كل الشاشات بروابط مباشرة وأزرار «الدخول بصفة …».

### أوامر أخرى

| الأمر | الوظيفة |
|---|---|
| `npm run build` | بناء إنتاجي (SSR, Nitro node-server) |
| `npm run preview` | معاينة البناء الإنتاجي |
| `npm run generate` | توليد ثابت للصفحات العامة |
| `npm run typecheck` | فحص الأنواع (`nuxi typecheck`) |
| `npm run i18n:check` | التأكد من تطابق مفاتيح `ar.json` و`en.json` وأن كل مفتاح مستخدم في الكود معرّف |
| `npm run lint` | ESLint (اختياري) |

---

## 2. وضع المحاكاة (Mock mode) — للاختبار بلا خادم خلفي

عند `NUXT_PUBLIC_API_MOCK=true` (الافتراضي في `.env.example`):

* تُخدم واجهة `/api/v1/**` من مسارات Nitro داخل التطبيق (`server/api/v1/[...path].ts` → `server/mock/handlers.ts`) ببيانات اصطناعية مطابقة لـ SPEC §11 وأشكال الاستجابة في §6.
* البيانات تعيش في ذاكرة خادم التطوير وتُعاد إلى حالتها الأولية عند إعادة تشغيله. كل الأسماء والأرقام موسومة بـ **«(افتراضي)»**.
* `GET /sanctum/csrf-cookie` محاكى أيضًا (`server/routes/sanctum/csrf-cookie.get.ts`).

### الحسابات التجريبية (كلمة المرور لجميعها: `password`)

| الدور | البريد | ملاحظة |
|---|---|---|
| مستثمر فرد (عضوية معتمدة) | `investor@demo.sciis.sa` | |
| مكتب عائلي (مستثمر مؤسسي، مرتبط بفرصة رفق) | `familyoffice@demo.sciis.sa` | 2FA |
| صندوق (عضوية قيد المراجعة) | `fund@demo.sciis.sa` | |
| مستثمرة (عضوية تحتاج استكمالًا) | `noura@demo.sciis.sa` | |
| صاحب مشروع (منشأة) — سُلّم / نماء / بصيرة | `entrepreneur@demo.sciis.sa` | |
| صاحب مشروع — رفق / شكّن | `khalid@demo.sciis.sa` | |
| صاحبة مشروع (فرد) — مهارة / حصاد / دوّر | `reem@demo.sciis.sa` | |
| مدير النظام | `admin@demo.sciis.sa` | 2FA |
| مسؤول العضوية | `membership@demo.sciis.sa` | 2FA |
| مراجع | `reviewer@demo.sciis.sa` | 2FA |
| مقيّم 1 / مقيّم 2 | `evaluator1@…` / `evaluator2@…` | 2FA |
| مسؤول الربط | `matching@demo.sciis.sa` | 2FA |
| الاتفاقيات / الأثر / الاستشاري / التقارير | `agreements@…` `impact@…` `advisory@…` `reports@…` | 2FA |

في وضع المحاكاة **أي رمز من 6 أرقام** يُقبل في التحقق بخطوتين (2FA) ورموز OTP للبريد والجوال.

### كيف يستخدم مختبر المنتج صفحة `/prototype`

1. افتح `/prototype` واضغط «الدخول بصفة» واختر الدور.
2. تصفّح الشاشات من القوائم (الموقع العام، الحسابات، بوابة المستثمر، بوابة صاحب المشروع، بوابة الإدارة). بجانب كل شاشة مرجع الفقرة في الوثيقة التطويرية (مثل §7.3).
3. الأزرار والنماذج تعمل فعليًا وتغيّر البيانات في الذاكرة (إبداء اهتمام، موافقة مالك، تقييم، ترشيح، تغيير حالة…). لإعادة البيانات الأولية أعد تشغيل `npm run dev`.
4. دوّن الملاحظات بمرجع اسم الشاشة والرابط الظاهر تحتها.

سيناريوهات جاهزة للاختبار:

* **مستثمر** → `/app/opportunities` → فرصة «مهارة» → «أبدِ اهتمامك» → ثم **صاحب مشروع (reem)** → طلبات الوصول → موافقة → ثم المستثمر يقبل اتفاقية عدم الإفصاح فتُفتح المستندات.
* **مدير النظام** → ملف «شكّن» → تبويب التقييم (تقييمان متكاملان جنبًا إلى جنب) → قرار الأهلية → تغيير الحالة إلى «مؤهّل».
* **مدير النظام** → ملف «دوّر» (جديد) → إسناد مقيّمَين → أو طلب استكمال.
* **مسؤول العضوية** → طلب عضوية «صندوق نماء» → اعتماد.

---

## 3. الربط بالـ API الحقيقي (Laravel)

```env
NUXT_PUBLIC_API_MOCK=false
NUXT_PUBLIC_API_BASE=https://api.example.sa/api/v1
NUXT_PUBLIC_SITE_URL=https://platform.example.sa
```

* العميل في `composables/useApi.ts`: `$fetch` بـ `credentials: 'include'`، تهيئة Sanctum CSRF (`GET {origin}/sanctum/csrf-cookie` قبل أول طلب تعديلي) وترويسة `X-XSRF-TOKEN`، وترويسة `Accept-Language` من اللغة الحالية، وتطبيع الأخطاء إلى `{ message, errors{field: [...]}, code, status }` وربط أخطاء الحقول بالنماذج (`useZodForm.applyApiError`).
* يجب على الـ API السماح بـ CORS مع `credentials` من نطاق الواجهة، وإعداد `SANCTUM_STATEFUL_DOMAINS`.
* شكل الاستجابات متوقع كما في SPEC §6 (غلاف `{ data }` و`{ data, meta }` للقوائم). الأشكال موثقة في `types/api.ts`.

---

## 4. البنية

```
web/
├─ nuxt.config.ts            # الوحدات، i18n (prefix_except_default)، routeRules (البوابات client-only)
├─ tailwind.config.ts        # رموز الهوية (SPEC §10) مطابقة لـ assets/css/tokens.css
├─ i18n.config.ts
├─ assets/css/tokens.css     # متغيرات CSS للهوية وألوان الحالات
├─ assets/css/main.css       # طبقات Tailwind + أصناف مشتركة (input, card, table-base…)
├─ locales/ar.json, en.json  # كل نصوص الواجهة (1146 مفتاحًا، متطابقة)
├─ types/enums.ts            # تعدادات SPEC §2 + خرائط الانتقالات المسموحة
├─ types/api.ts              # أنواع الموارد (OpportunityCard/Detail/Full, Interest, Nomination, …)
├─ composables/
│  ├─ useApi.ts              # عميل الـ API (Sanctum, Accept-Language, أخطاء موحّدة)
│  ├─ useZodForm.ts          # نماذج مع zod + أخطاء الـ API
│  ├─ useFormat.ts           # أرقام لاتينية (ar-SA-u-nu-latn)، تواريخ، عملة، أحجام
│  ├─ useEnumLabel.ts        # ترجمة التعدادات والتصنيفات
│  ├─ useStatusColor.ts, useToast.ts, useAsyncResource.ts
├─ stores/auth.ts            # me / login / 2FA / register / verify / logout
├─ stores/taxonomies.ts      # التصنيفات + الإعدادات العامة
├─ middleware/               # auth, guest, member (عضوية معتمدة), role (أدوار الفريق), investor, entrepreneur
├─ layouts/                  # default (عام)، portal (شريط جانبي حسب الدور)، auth
├─ components/               # مكتبة المكونات (انظر أدناه)
├─ pages/                    # الصفحات (انظر خريطة المسارات)
├─ server/api/v1/[...path].ts  # موجّه المحاكاة
├─ server/mock/              # db.ts (البيانات)، serializers.ts (مصفوفة الحساسية §5)، handlers.ts (§6)
├─ server/routes/sanctum/    # محاكاة csrf-cookie
└─ scripts/check-i18n.mjs
```

### مكتبة المكونات

`AppButton`, `AppBadge` (حالة → لون/تسمية), `OpportunityCard`, `StatusStrip` (9 مراحل + موقعك الحالي), `FilterSidebar` (مرشّحات بعدّادات، قابلة للطي على الجوال), `Stepper`, `DataTable`, `Pagination`, `Tabs` (أسهم/Home/End), `Modal` (Escape + حبس التركيز), `FormField` (input/textarea/select/checkbox/chips/radio-cards + a11y), `FileUpload`, `CompletionRing`, `EmptyState`, `Disclaimer`, `InlineAlert`, `LangSwitch`, `DemoBanner`, `PlaceholderImage` («صورة توضيحية» مع وصف المقصد), `BarChart` (أعمدة/قمع بدون مكتبة), `StatCard`, `SectionCard`, `PageHeader`, `KeyValue`, `Timeline`, `MeetingList`, `LegalPage`, `OpportunityForm` (نموذج المشروع متعدد الخطوات مع حفظ تلقائي وقائمة تحقق), `ToastHost`.

### خريطة المسارات

**عام (SSR):** `/`, `/about`, `/impact-investing`, `/for-investors`, `/for-entrepreneurs`, `/faq`, `/contact`, `/legal/privacy|terms|disclaimer`, `/prototype`.

**الحسابات:** `/auth/login` (+2FA), `/auth/register` (5 خطوات للمستثمر / 4 لصاحب المشروع), `/auth/verify`, `/auth/forgot`, `/auth/reset`.

**بوابة المستثمر:** `/app/investor/dashboard`, `/app/opportunities`, `/app/opportunities/:uuid`, `/app/investor/interests|nominations|info-requests|meetings|saved|profile`.

**بوابة صاحب المشروع:** `/app/entrepreneur/dashboard`, `/app/entrepreneur/opportunities/new`, `/app/entrepreneur/opportunities/:uuid/edit`, `/app/entrepreneur/opportunities/:uuid/preview`, `/app/entrepreneur/access-requests|info-requests|meetings`.

**بوابة الإدارة:** `/app/admin/dashboard`, `/app/admin/opportunities`, `/app/admin/opportunities/:uuid`, `/app/admin/memberships`, `/app/admin/memberships/:id`, `/app/admin/interests|info-requests|meetings|users|settings|evaluation-criteria|reports|audit-logs|content`.

كل مسار متاح بالإنجليزية بالبادئة `/en`.

---

## 5. قرارات تنفيذية مهمة

* **إقرار «المستثمر المؤهّل»** غير معروض عمدًا في التسجيل والملف الشخصي (الوثيقة §7.5 — بانتظار المراجعة القانونية). انظر التعليق في `pages/auth/register.vue`.
* **سياسة الوصول للفرص** (`opportunity.access_policy`) تُعرض في الواجهة كـ «معلّق — بانتظار الرأي القانوني»؛ في وضع المحاكاة تُعرض كل الفرص المؤهّلة لتسهيل الاختبار مع إظهار السياسة الحالية.
* **مصفوفة الحساسية (SPEC §5)** مطبّقة في `server/mock/serializers.ts` (`serializeCard`/`serializeDetail(accessLevel)`/`serializeFull`)؛ الـ API الحقيقي مسؤول عن التطبيق النهائي، والواجهة تعرض فقط ما يصلها.
* **الأرقام** دائمًا بأرقام لاتينية (`Intl.NumberFormat('ar-SA-u-nu-latn')`) والخط IBM Plex Sans Arabic للعربية والإنجليزية والأرقام.
* **الصفحات القانونية** نصوص مسودة موسومة «بانتظار الاعتماد القانوني».
* البوابات (`/app/**`) و`/auth/**` تعمل client-only (`routeRules`) كما في SPEC §0؛ الصفحات العامة SSR.
