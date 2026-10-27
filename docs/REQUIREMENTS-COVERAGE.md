# تغطية متطلبات الوثيقة التطويرية 2.0

مرجع واحد لكل متطلب في القسم 14 من «الوثيقة التطويرية 2.0»: أولويته، وحالته، والملفات التي تنفذه (دليل قابل للتدقيق).
يُولَّد من الشيفرة: كل ميزة موسومة برمز متطلبها في التعليقات، والأدلة أدناه أول ملفات تحمل الرمز.

**الإجمالي:** 129 متطلبًا — 119 منفّذة بالكامل، و10 منفّذة تنتظر طرفًا خارجيًا. لا يوجد متطلب غير مبني.

**الحالة:** ✅ منفّذ في الشيفرة · 🔌 منفّذ وينتظر طرفًا خارجيًا (عقد أو مفاتيح أو اعتماد قانوني).


## الحسابات والصلاحيات (ACC)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| ACC-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminSettingsController.php` · `api/app/Http/Controllers/Api/V1/Auth/AuthController.php` |  |
| ACC-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Auth/AuthController.php` · `web/pages/app/investor/profile.vue` |  |
| ACC-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminRoleController.php` · `api/app/Http/Controllers/Api/V1/Auth/AuthController.php` |  |
| ACC-04 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminOpportunityController.php` · `api/app/Http/Controllers/Api/V1/Investor/OpportunityBrowseController.php` |  |
| ACC-05 | P1 | 🔌 | `api/app/Http/Controllers/Api/V1/Auth/AuthController.php` · `api/app/Http/Controllers/Api/V1/Auth/TwoFactorController.php` | التحقق الثنائي مبني؛ فرضه إلزاميًا على حسابات الإدارة قرار تفعيل (`SCIIS_2FA_ENFORCE_STAFF`). |
| ACC-06 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminUserController.php` · `api/app/Http/Controllers/Api/V1/Auth/MeController.php` |  |
| ACC-07 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Auth/MeController.php` · `api/app/Http/Middleware/IdleTimeout.php` |  |
| ACC-08 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurDashboardController.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` |  |
| ACC-09 | P3 | 🔌 | `api/app/Enums/IdentityVerificationStatus.php` · `api/app/Http/Controllers/Api/V1/Auth/IdentityVerificationController.php` | موصل «نفاذ» مبني خلف إعداد؛ يحتاج اتفاق الانضمام مع الجهة المشغلة. |

## بوابة صاحب المشروع (ENT)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| ENT-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Auth/AuthController.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` |  |
| ENT-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/Concerns/BuildsChecklist.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` |  |
| ENT-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` · `api/app/Notifications/OpportunityStatusChangedNotification.php` |  |
| ENT-04 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurDashboardController.php` · `api/app/Models/CompletionRequest.php` |  |
| ENT-05 | P1 | ✅ | `api/app/Enums/OpportunityStatus.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` |  |
| ENT-06 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurDashboardController.php` · `api/app/Http/Controllers/Api/V1/Investor/InterestController.php` |  |
| ENT-07 | P1 | ✅ | `api/app/Enums/OpportunityStatus.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` |  |
| ENT-08 | P2 | ✅ | `web/pages/app/entrepreneur/dashboard.vue` · `web/pages/app/entrepreneur/opportunities/new.vue` |  |
| ENT-09 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` · `web/pages/app/entrepreneur/opportunities/[uuid]/preview.vue` |  |
| ENT-10 | P2 | ✅ | `api/app/Enums/InvestorUpdateStatus.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminFollowUpController.php` |  |

## بوابة المستثمر (INV)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| INV-01 | P1 | ✅ | `api/app/Enums/DocumentKind.php` · `api/app/Http/Controllers/Api/V1/Auth/AuthController.php` |  |
| INV-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/InvestorDocumentController.php` · `api/app/Http/Controllers/Api/V1/Investor/InvestorProfileController.php` |  |
| INV-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/InvestorDashboardController.php` · `web/pages/app/opportunities/[uuid].vue` |  |
| INV-04 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/InterestController.php` · `api/app/Models/DocumentAccessLog.php` |  |
| INV-05 | P2 | ✅ | `api/app/Enums/AccessPolicy.php` · `web/pages/app/opportunities/index.vue` |  |
| INV-06 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/InvestorPortfolioController.php` · `api/app/Services/FollowUpPresenter.php` |  |
| INV-07 | P2 | ✅ | `api/app/Console/Commands/SendProfileRemindersCommand.php` · `api/app/Http/Requests/Admin/UpdateSettingsRequest.php` |  |
| INV-08 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/System/DocumentStreamController.php` · `api/app/Services/DocumentService.php` |  |

## لوحة الإدارة وسير العمل (ADM)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| ADM-01 | P1 | ✅ | `api/app/Events/OpportunityStatusChanged.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminOpportunityController.php` |  |
| ADM-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminOpportunityController.php` · `api/app/Models/Opportunity.php` |  |
| ADM-03 | P1 | ✅ | `api/app/Console/Commands/SendSlaRemindersCommand.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminSettingsController.php` |  |
| ADM-04 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminOpportunityController.php` · `api/app/Http/Requests/Admin/CompletionRequestRequest.php` |  |
| ADM-05 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminOpportunityController.php` · `api/app/Http/Requests/Admin/InternalNoteRequest.php` |  |
| ADM-06 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminMembershipController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminUserController.php` |  |
| ADM-07 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminDashboardController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminOpportunityController.php` |  |
| ADM-08 | P2 | ✅ | `api/app/Exports/DatasetExport.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminAssignmentController.php` |  |
| ADM-09 | P2 | ✅ | `api/app/Http/Requests/Admin/UpdateSettingsRequest.php` · `api/app/Listeners/AutoAssignReviewer.php` |  |

## النماذج (FRM)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| FRM-01 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminFormController.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/Concerns/BuildsChecklist.php` |  |
| FRM-02 | P1 | ✅ | `web/pages/auth/register.vue` · `web/components/DynamicQuestions.vue` |  |
| FRM-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminEvaluationController.php` · `api/app/Http/Requests/Admin/EvaluationCriterionRequest.php` |  |
| FRM-04 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminSettingsController.php` · `api/app/Http/Controllers/Api/V1/Public/TaxonomyController.php` |  |

## التقييم (EVL)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| EVL-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminEvaluationController.php` · `api/app/Http/Requests/Admin/EvaluationCriterionRequest.php` |  |
| EVL-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminAssignmentController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminEvaluationController.php` |  |
| EVL-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminDashboardController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminEvaluationController.php` |  |
| EVL-04 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminAdvisoryController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminOpportunityController.php` |  |
| EVL-05 | P2 | ✅ | `api/app/Notifications/EvaluationDivergenceNotification.php` · `api/app/Services/EvaluationService.php` |  |

## البحث والمطابقة (MAT)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| MAT-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminMatchingController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminMembershipController.php` |  |
| MAT-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminDashboardController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminMatchingController.php` |  |
| MAT-03 | P1 | ✅ | `api/database/migrations/0001_01_01_000012_create_matching_tables.php` · `docs/SPEC.md` |  |
| MAT-04 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminMatchingController.php` · `api/app/Services/MatchingService.php` |  |
| MAT-05 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminMatchingController.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` |  |
| MAT-06 | P3 | ✅ | `api/app/Http/Requests/Admin/UpdateSettingsRequest.php` · `api/app/Services/MatchingService.php` |  |

## الاجتماعات والمراسلات (MTG)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| MTG-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminMeetingController.php` · `api/app/Http/Requests/Admin/MeetingRequest.php` |  |
| MTG-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminDashboardController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminMeetingController.php` |  |
| MTG-03 | P2 | ✅ | `api/app/Enums/DocumentKind.php` · `api/app/Http/Controllers/Api/V1/Messages/MessageController.php` |  |
| MTG-04 | P2 | ✅ | `api/app/Exports/EventRegistrationsExport.php` · `api/app/Http/Controllers/Api/V1/Events/AdminEventRegistrationController.php` |  |
| MTG-05 | P3 | ✅ | `api/app/Http/Controllers/Api/V1/Events/CalendarFeedController.php` · `api/app/Models/CalendarFeed.php` |  |

## المستندات (DOC)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| DOC-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` · `api/app/Http/Requests/Entrepreneur/UploadDocumentRequest.php` |  |
| DOC-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminAgreementController.php` · `api/app/Http/Controllers/Api/V1/Investor/InterestController.php` |  |
| DOC-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/InvestorDocumentController.php` · `api/app/Http/Controllers/Api/V1/System/OwnDocumentController.php` |  |

## الاتفاقيات (AGR)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| AGR-01 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminAgreementTemplateController.php` · `api/app/Http/Requests/Agreements/AgreementTemplateRequest.php` |  |
| AGR-02 | P2 | ✅ | `api/app/Enums/AgreementStatus.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminAgreementController.php` |  |
| AGR-03 | P2 | ✅ | `api/app/Enums/AgreementReviewDecision.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminAgreementController.php` |  |
| AGR-04 | P2 | ✅ | `api/app/Enums/AgreementParty.php` · `api/app/Enums/AgreementStatus.php` |  |
| AGR-05 | P3 | 🔌 | `api/app/Http/Controllers/Api/V1/Admin/AdminAgreementController.php` · `api/app/Http/Controllers/Api/V1/Agreements/PartyAgreementController.php` | إرسال للتوقيع مبني مع بديل رفع النسخة الموقعة ورقيًا؛ التوقيع الإلكتروني يحتاج مزودًا معتمدًا. |

## متابعة التنفيذ (PRJ)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| PRJ-01 | P2 | ✅ | `api/app/Enums/MilestonePlanStatus.php` · `api/app/Enums/MilestoneStatus.php` |  |
| PRJ-02 | P2 | ✅ | `api/app/Console/Commands/FollowUpRemindersCommand.php` · `api/app/Enums/ProgressReportStatus.php` |  |
| PRJ-03 | P2 | ✅ | `api/app/Console/Commands/FollowUpRemindersCommand.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminFollowUpController.php` |  |

## قياس الأثر (IMP)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| IMP-01 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurFollowUpController.php` · `api/app/Services/FollowUpPresenter.php` |  |
| IMP-02 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminFollowUpController.php` · `api/app/Http/Controllers/Api/V1/Investor/InvestorPortfolioController.php` |  |
| IMP-03 | P3 | ✅ | `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` · `api/app/Http/Requests/Entrepreneur/StoreIndicatorRequest.php` |  |

## التقارير (RPT)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| RPT-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminReportController.php` · `api/app/Services/ReportService.php` |  |
| RPT-02 | P1 | ✅ | `api/app/Exports/ReportExport.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminReportController.php` |  |
| RPT-03 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminReportController.php` · `api/app/Services/ReportService.php` |  |
| RPT-04 | P2 | ✅ | `api/app/Exports/DatasetExport.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminReportBuilderController.php` |  |
| RPT-05 | P2 | ✅ | `api/app/Console/Commands/SendScheduledReportsCommand.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminReportBuilderController.php` |  |
| RPT-06 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminReportController.php` · `api/app/Services/Reporting/ReportPdf.php` |  |

## الإشعارات (NTF)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| NTF-01 | P1 | ✅ | `api/app/Events/OpportunityStatusChanged.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminNotificationTemplateController.php` |  |
| NTF-02 | P1 | 🔌 | `api/app/Http/Controllers/Api/V1/Admin/AdminSettingsController.php` · `api/app/Http/Controllers/Api/V1/Auth/MeController.php` | الرسائل النصية مبنية؛ تفعيلها على الإنتاج ينتظر مزود رسائل معتمد واسم مرسل مسجّل. |
| NTF-03 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Auth/MeController.php` · `web/pages/app/account.vue` |  |
| NTF-04 | P3 | 🔌 | `api/app/Enums/NotificationChannel.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminSettingsController.php` | قناة واتساب مبنية (Cloud API)؛ تحتاج اعتماد القناة ومفاتيح الحساب. |

## سجل العمليات (AUD)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| AUD-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminFollowUpController.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminMembershipController.php` |  |
| AUD-02 | P1 | ✅ | `api/app/Console/Commands/PurgeRetentionCommand.php` · `api/app/Models/AuditLog.php` |  |
| AUD-03 | P1 | ✅ | `api/app/Exports/AuditLogExport.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminReportController.php` |  |

## الموقع العام وإدارة المحتوى (CMS)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| CMS-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Public/PublicContentController.php` · `api/routes/api.php` |  |
| CMS-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminContentController.php` · `api/app/Http/Requests/Admin/CmsContentRequest.php` |  |
| CMS-03 | P1 | 🔌 | `web/components/LegalPage.vue` · `docs/openapi.yaml` | الصفحات القانونية موجودة وقابلة للتعديل؛ اعتمادها القانوني قبل فتح التسجيل رسميًا. |
| CMS-04 | P1 | ✅ | `api/app/Services/SettingsService.php` · `web/components/CookieConsent.vue` |  |
| CMS-05 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Public/PublicContentController.php` · `api/app/Http/Requests/Events/RegisterForEventRequest.php` |  |
| CMS-06 | P2 | ✅ | `web/locales/en.json` · `web/nuxt.config.ts` |  |

## الاستكشاف وصفحات الفرص (OPP)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| OPP-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/OpportunityBrowseController.php` · `web/pages/app/opportunities/index.vue` |  |
| OPP-02 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/OpportunityBrowseController.php` · `api/app/Support/OpportunityFilters.php` |  |
| OPP-03 | P1 | ✅ | `web/components/OpportunityCard.vue` · `api/README.md` |  |
| OPP-04 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/OpportunityBrowseController.php` · `api/app/Models/SavedOpportunity.php` |  |
| OPP-05 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminMatchingController.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurDashboardController.php` |  |
| OPP-06 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/OpportunityBrowseController.php` · `docs/openapi.yaml` |  |
| OPP-07 | P2 | ✅ | `api/app/Console/Commands/SendOpportunityAlertsCommand.php` · `api/app/Http/Controllers/Api/V1/Investor/SavedFilterController.php` |  |
| OPP-08 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/SavedFilterController.php` · `api/app/Http/Requests/Investor/SavedFilterRequest.php` |  |
| OPP-09 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/InvestorNoteController.php` · `api/app/Http/Requests/Investor/InvestorNoteRequest.php` |  |
| OPP-10 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/OpportunityBrowseController.php` · `api/app/Support/OpportunityFilters.php` |  |
| OPP-11 | P2 | ✅ | `api/app/Http/Requests/Entrepreneur/StoreOpportunityRequest.php` · `api/app/Http/Requests/Entrepreneur/UpdateOpportunityRequest.php` |  |
| OPP-12 | P3 | ✅ | `api/app/Http/Controllers/Api/V1/Investor/OpportunityCompareController.php` · `api/app/Http/Requests/Investor/CompareOpportunitiesRequest.php` |  |

## التواصل المُدار (MSG)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| MSG-01 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminMatchingController.php` · `api/app/Http/Resources/InfoRequestResource.php` |  |
| MSG-02 | P1 | 🔌 | `api/app/Http/Controllers/Api/V1/Investor/NdaController.php` · `api/app/Http/Requests/Investor/NdaAcceptRequest.php` | تعهد السرية الإلكتروني مبني ويحفظ النص وتاريخه؛ صياغته معلّقة على الرأي القانوني. |
| MSG-03 | P2 | ✅ | `api/app/Console/Commands/SendInvestorDigestCommand.php` · `api/app/Http/Requests/Admin/UpdateSettingsRequest.php` |  |

## الهوية والواجهة (BRD)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| BRD-01 | P1 | ✅ | `web/assets/css/tokens.css` · `web/tailwind.config.ts` |  |
| BRD-02 | P1 | ✅ | `web/components/DesignStyleguide.vue` · `web/components/AppButton.vue` |  |
| BRD-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Admin/AdminContentController.php` · `api/app/Http/Requests/Admin/CmsContentRequest.php` |  |
| BRD-04 | P3 | ✅ | `api/app/Http/Controllers/Api/V1/Auth/PreferencesController.php` · `api/app/Http/Resources/UserResource.php` |  |

## حماية البيانات (PRV)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| PRV-01 | P1 | ✅ | `api/app/Console/Commands/ImportLegacyCommand.php` · `api/app/Http/Controllers/Api/V1/Auth/AuthController.php` |  |
| PRV-02 | P1 | ✅ | `api/app/Console/Commands/ImportLegacyCommand.php` · `api/app/Http/Controllers/Api/V1/Entrepreneur/EntrepreneurOpportunityController.php` |  |
| PRV-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/Auth/MeController.php` · `api/app/Http/Requests/Auth/DataRequestRequest.php` |  |
| PRV-04 | P1 | ✅ | `api/app/Console/Commands/PurgeRetentionCommand.php` · `api/README.md` |  |
| PRV-05 | P1 | 🔌 | `api/config/filesystems.php` · `docs/ARCHITECTURE.md` | التطبيق جاهز للاستضافة داخل المملكة؛ الخادم الحالي (Hostinger) مرحلي، والنقل لاستضافة داخل المملكة قرار قبل الإطلاق الرسمي. |

## الأمن (SEC)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| SEC-01 | P1 | ✅ | `api/app/Console/Commands/EncryptExistingDocumentsCommand.php` · `api/app/Exceptions/DocumentDecryptionException.php` |  |
| SEC-02 | P1 | 🔌 | `api/app/Http/Middleware/SecurityHeaders.php` · `api/README.md` | بوابة اختبار الاختراق موثقة (docs/SECURITY.md)؛ الاختبار نفسه تجريه جهة مستقلة مرخصة. |
| SEC-03 | P1 | ✅ | `docs/SECURITY.md` |  |
| SEC-04 | P1 | ✅ | `api/database/seeders/DatabaseSeeder.php` · `api/database/seeders/DemoSeeder.php` |  |
| SEC-05 | P1 | 🔌 | `docs/SECURITY.md` | خريطة ضوابط NCA ECC موثقة؛ المراجعة الرسمية تقنية وقانونية خارجية. |

## الأداء والتوسع (SCL)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| SCL-01 | P1 | ✅ | `docs/design-rollout/README.md` · `web/nuxt.config.ts` |  |
| SCL-02 | P1 | ✅ | `api/config/filesystems.php` · `api/config/queue.php` |  |
| SCL-03 | P2 | ✅ | `docs/openapi.yaml` |  |

## النسخ الاحتياطي (BKP)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| BKP-01 | P1 | ✅ | `api/app/Console/Commands/BackupCommand.php` · `api/app/Http/Controllers/Api/V1/Admin/AdminSettingsController.php` |  |
| BKP-02 | P1 | ✅ | `docs/DEPLOYMENT.md` |  |
| BKP-03 | P1 | ✅ | `api/app/Http/Controllers/Api/V1/System/HealthController.php` · `api/config/logging.php` |  |

## الجوال واللغة وإمكانية الوصول (UX)

| الرمز | الأولوية | الحالة | الدليل في الشيفرة | ملاحظة |
|---|---|---|---|---|
| UX-01 | P1 | ✅ | `web/nuxt.config.ts` · `docs/SPEC.md` |  |
| UX-02 | P1 | ✅ | `web/composables/useFormat.ts` · `docs/TESTING.md` |  |
| UX-03 | P2 | ✅ | `web/locales/en.json` · `web/i18n.config.ts` |  |
| UX-04 | P2 | ✅ | `web/components/AppBadge.vue` · `web/components/FormField.vue` |  |
| UX-05 | P2 | ✅ | `api/app/Http/Controllers/Api/V1/Auth/PreferencesController.php` · `api/app/Http/Resources/UserResource.php` |  |
