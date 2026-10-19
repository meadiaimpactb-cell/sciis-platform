# تطبيق الجوال — منصة المجلس السعودي للمستثمرين الاجتماعيين (SCIIS)

تطبيق **Flutter 3** واحد لنظامي iOS و Android، يستهلك **نفس واجهة البرمجة `/api/v1`** التي يستهلكها الموقع (Nuxt)، ولا يحتوي أي منطق أعمال خاص به: الحالات، والصلاحيات، ومستويات الحساسية، والموافقة المزدوجة كلها تُنفَّذ في الـ API (Laravel) ويُعرض التطبيق ما يصله فقط.

> **قاعدة ثابتة: لا تغليف للموقع داخل WebView.**
> التطبيق واجهة أصلية (native) بالكامل. تغليف الموقع يُخالف شروط متجري Apple وGoogle (القاعدة 4.2 «الحد الأدنى من الوظائف» في App Store)، ويُفقد التطبيق إشعارات الدفع والتخزين الآمن والأداء، ويُضعف تجربة العربية RTL. أي شاشة تحتاج تحريرًا طويلًا (ملف الفرصة الكامل، رفع المستندات المتعددة) يُحال فيها المستخدم إلى بوابة الويب برابط عميق، لا بـ WebView.

---

## 1. المتطلبات

| الأداة | الإصدار |
|---|---|
| Flutter SDK | `^3.24` (قناة stable) |
| Dart SDK | `^3.5` |
| Xcode (لـ iOS) | 15+ مع CocoaPods |
| Android Studio / SDK | API 34، JDK 17 |
| خط IBM Plex Sans Arabic | ملفات OFL في `assets/fonts/` — انظر `assets/fonts/README.md` |

## 2. الإعداد الأول

```bash
cd mobile
flutter pub get
# توليد ملفات الترجمة من lib/l10n/*.arb (يُنفَّذ تلقائيًا مع pub get لأن generate: true)
flutter gen-l10n
# (اختياري) توليد json_serializable عند اعتماده لأي نموذج
dart run build_runner build --delete-conflicting-outputs
flutter analyze
flutter test
```

عنوان الـ API يُمرَّر وقت البناء، ولا يُكتب في الكود:

```bash
# محاكي Android → الخادم المحلي (docker compose) على المضيف
flutter run --dart-define=API_BASE=http://10.0.2.2:8000/api/v1
# محاكي iOS
flutter run --dart-define=API_BASE=http://localhost:8000/api/v1
# بيئة الاختبار
flutter run --dart-define=API_BASE=https://api-test.sciis.sa/api/v1
```

## 3. هيكل المشروع

```
mobile/
├── pubspec.yaml                 الحزم + الخط + تفعيل gen-l10n
├── l10n.yaml                    إعدادات توليد الترجمة (القالب: app_ar.arb)
├── analysis_options.yaml        قواعد flutter_lints + strict modes
├── assets/fonts/                IBM Plex Sans Arabic (OFL) — تُضاف يدويًا
└── lib/
    ├── main.dart                MaterialApp.router — ar افتراضيًا، en اختياري، الاتجاه يتبع اللغة
    ├── router.dart              go_router + إعادة التوجيه حسب حالة الجلسة ونوع الحساب
    ├── core/
    │   ├── api_client.dart      Dio + Accept-Language + Bearer + X-Request-Id + معالجة 401/423
    │   ├── auth_storage.dart    flutter_secure_storage (Keychain / EncryptedSharedPreferences)
    │   ├── enums.dart           مرآة SPEC §2 حرفيًا مع التسميات العربية والإنجليزية
    │   ├── theme.dart           رموز الهوية SPEC §10
    │   ├── models/              OpportunityCard، OpportunityDetail، Interest، Nomination، Me، Membership…
    │   └── widgets/             مكوّنات دلالية مشتركة (StatusChip، CompletionBar، LockedSection…)
    ├── features/
    │   ├── auth/                تسجيل الدخول، OTP، التحقق بخطوتين، إنشاء الحساب، استعادة كلمة المرور
    │   ├── opportunities/       قائمة الفرص + ورقة التصفية، التفاصيل بتبويباتها، المحفوظة
    │   ├── dashboard/           لوحة المستثمر (الاكتمال، العدادات، جدول الاهتمامات، الاجتماع القادم) + الحساب
    │   ├── entrepreneur/        لوحة صاحب المشروع (شريط الحالة + «المطلوب منك الآن» + طلبات الوصول)
    │   └── notifications/       مركز الإشعارات + تسجيل رمز الجهاز POST /me/device-tokens
    └── l10n/                    app_ar.arb + app_en.arb (كل النصوص)
```

## 4. كيف يستهلك التطبيق `/api/v1`

- **عقد واحد**: `docs/openapi.yaml` هو المرجع؛ أي حقل جديد يُضاف هناك أولًا ثم في `core/models/`.
- **اللغة**: كل طلب يحمل `Accept-Language: ar|en` حسب لغة التطبيق، والـ API يُعيد تسميات التصنيفات (`taxonomy_terms`) مترجمة؛ التطبيق لا يترجم بيانات الخادم.
- **التصفح والتصفية**: `GET /opportunities?page=&per_page=&filter[sector]=&filter[region]=&filter[stage]=&filter[type]=&sort=newest|impact|need&search=` — انظر `OpportunityFilters.toQuery()`.
- **الكشف التدريجي (Progressive disclosure)**: الـ API يُشكّل `OpportunityDetail` حسب مستوى الوصول (`access_level`) ويُرسل `documents: {locked: true}` أو ملخص المخاطر فقط قبل المنح. التطبيق **لا يحاول أبدًا** استنتاج الحقول المقفلة أو تخزينها.
- **الأخطاء**: `{message, errors{field:[...]}, code}` تُحوَّل إلى `ApiException` مع `fieldErrors` تُعرض تحت الحقول مباشرة.
- **التتبع**: كل طلب يحمل `X-Request-Id` (UUID v4) يُسجَّل في `audit_logs` على الخادم.

## 5. تدفق المصادقة (Sanctum bearer tokens)

```
POST /auth/register ──▶ 201 {token, user}  ──▶ OTP بريد + جوال (/auth/verify-email، /auth/verify-phone)
POST /auth/login {email, password, device_name:"mobile"}
   ├─ 200 {token, user}                    ──▶ يُحفظ في Secure Storage ──▶ GET /me ──▶ توجيه حسب النوع
   └─ 423 {code:"two_factor_required"}     ──▶ شاشة TOTP ──▶ POST /auth/2fa/challenge {code} ──▶ {token}
كل طلب: Authorization: Bearer <token>
401 على أي مسار غير /auth/* ──▶ مسح الرمز محليًا ──▶ شاشة الدخول (الرموز الشخصية في Sanctum لا تُجدَّد؛ المستخدم يعيد الدخول)
POST /auth/logout ──▶ إبطال الرمز على الخادم + DELETE /me/device-tokens/{token} + مسح التخزين الآمن
```

- الرمز لا يُكتب أبدًا في `SharedPreferences` أو السجلات.
- `device_name` ثابت `mobile` حتى يستطيع المستخدم من الويب رؤية «أجهزتي» وإبطالها (P2).
- التحقق بخطوتين إلزامي لحسابات `staff` (ACC-05)، واختياري للبقية من شاشة الحساب.

## 6. إشعارات الدفع

1. يُنفَّذ `PushProvider` (في `features/notifications/push_service.dart`) بمزوّد فعلي: Firebase Messaging لـ Android و APNs عبر Firebase لـ iOS. الملف يحتوي خطوات التفعيل بالتفصيل.
2. بعد المصادقة، يُستدعى `PushService.start()` تلقائيًا من `router.dart` ويُسجَّل الرمز بـ `POST /me/device-tokens {platform, token}` (عملية idempotent).
3. الخادم يرسل عنوانًا ونصًا قصيرًا وحقل `route` (مثل `/opportunities/<uuid>`) فقط — **لا بيانات شخصية ولا محتوى مستندات** تمر عبر FCM/APNs (PRV-05).
4. عند النقر، يفتح `go_router` المسار من `route`.

## 7. البناء للإصدار

### Android
```bash
# مرة واحدة: إنشاء مفتاح التوقيع وحفظه خارج المستودع
keytool -genkey -v -keystore ~/sciis-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias sciis
# android/key.properties (غير مُتتبَّع في git)
flutter build appbundle --release --dart-define=API_BASE=https://api.sciis.sa/api/v1 --obfuscate --split-debug-info=build/symbols
```
- `applicationId`: `sa.sciis.app` (يُثبَّت قبل أول رفع).
- يُرفع `.aab` إلى Google Play Console → مسار الاختبار الداخلي أولًا.

### iOS
```bash
flutter build ipa --release --dart-define=API_BASE=https://api.sciis.sa/api/v1 --obfuscate --split-debug-info=build/symbols
```
- Bundle ID: `sa.sciis.app`، فريق Apple Developer باسم المجلس (ليس حسابًا شخصيًا).
- Capabilities: Push Notifications، Associated Domains (للروابط العميقة `applinks:sciis.sa`).
- يُرفع عبر Xcode Organizer أو `xcrun altool` إلى TestFlight.

### متطلبات المتاجر (ملخص — التفاصيل في `docs/MOBILE.md`)
- سياسة خصوصية عامة بالعربية والإنجليزية على `sciis.sa/privacy` (نفس نسخة `pages` في الـ API).
- نموذج «Data safety» (Google) و«App Privacy» (Apple): البريد، الجوال، الاسم، معرّف الجهاز لأغراض الحساب والإشعارات فقط؛ لا تتبّع إعلاني.
- حساب تجريبي للمراجعين (مستثمر معتمد ببيانات «افتراضي») يُزوَّد في ملاحظات المراجعة.
- زر حذف الحساب داخل التطبيق موجود (`/me/data-requests {type: erase}`) — إلزامي في المتجرين.

## 8. الاختبار

```bash
flutter analyze            # صفر تحذيرات مطلوبة في CI
flutter test               # اختبارات الوحدة (enums مرآة SPEC §2، النماذج، الموجّه)
flutter test integration_test  # (P2) رحلات المستخدم على محاكي
```

## 9. ما يُترك للويب في المرحلة الأولى

| المهمة | السبب |
|---|---|
| إنشاء/تحرير ملف الفرصة الكامل ورفع المستندات | نماذج طويلة متعددة الأقسام؛ تجربة سطح المكتب أنسب (ENT-05) |
| لوحة الإدارة `/admin/*` | مخصصة لفريق المجلس على سطح المكتب مع 2FA |
| تحرير الملف الاستثماري الكامل | يُعرض الاكتمال فقط ويُحال للويب |

يُعاد تقييم هذه القائمة في المرحلة الثانية مع تفعيل `features.*` المناسبة.
