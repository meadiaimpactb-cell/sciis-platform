# خط IBM Plex Sans Arabic

الخط المعتمد في الهوية (SPEC §10) لكل من العربية والإنجليزية والأرقام هو **IBM Plex Sans Arabic**
المرخَّص برخصة SIL Open Font License 1.1.

ضع ملفات `.ttf` التالية في هذا المجلد (الأسماء مطابقة لما في `pubspec.yaml`):

| الملف | الوزن |
|---|---|
| `IBMPlexSansArabic-Regular.ttf` | 400 |
| `IBMPlexSansArabic-Medium.ttf` | 500 |
| `IBMPlexSansArabic-SemiBold.ttf` | 600 |
| `IBMPlexSansArabic-Bold.ttf` | 700 |

مصدر التحميل الرسمي: مستودع `IBM/plex` على GitHub (إصدار `IBM Plex Sans Arabic`)، أو Google Fonts.
أرفق معها ملف `OFL.txt` للامتثال لشروط الرخصة.

> إلى أن تُضاف الملفات، سيفشل `flutter build` بسبب الإشارة إليها في `pubspec.yaml`؛ وهذا مقصود حتى لا
> يُنشر إصدار بخط النظام الافتراضي.
