// Mirrors SPEC §2 exactly (string-backed snake_case values).
// Keep in sync with api/app/Enums/*.php and web/types/enums.ts.
//
// Every enum exposes:
//   - `value`   : the wire value used by /api/v1
//   - `labelAr` : Arabic label (from الوثيقة التطويرية)
//   - `labelEn` : English label
//   - `fromValue(String)` : tolerant parser (falls back to the first member)

import 'package:flutter/material.dart';

abstract class WireEnum {
  String get value;
  String get labelAr;
  String get labelEn;

  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;
}

T _parse<T extends WireEnum>(List<T> values, String? raw, [T? fallback]) {
  for (final v in values) {
    if (v.value == raw) return v;
  }
  return fallback ?? values.first;
}

// ---------------------------------------------------------------------------
// §1.1 UserType
// ---------------------------------------------------------------------------
enum UserType implements WireEnum {
  investorIndividual('investor_individual', 'مستثمر فرد', 'Individual investor'),
  investorInstitutional(
    'investor_institutional',
    'مستثمر مؤسسي',
    'Institutional investor',
  ),
  entrepreneurIndividual(
    'entrepreneur_individual',
    'صاحب مشروع (فرد)',
    'Entrepreneur (individual)',
  ),
  entrepreneurEntity(
    'entrepreneur_entity',
    'صاحب مشروع (منشأة)',
    'Entrepreneur (entity)',
  ),
  staff('staff', 'فريق المجلس', 'Council staff');

  const UserType(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  bool get isInvestor =>
      this == UserType.investorIndividual || this == UserType.investorInstitutional;
  bool get isEntrepreneur =>
      this == UserType.entrepreneurIndividual || this == UserType.entrepreneurEntity;

  static UserType fromValue(String? raw) => _parse(UserType.values, raw);
}

// ---------------------------------------------------------------------------
// §1.3 Investor access level (visitor → registered → approved_member → granted → connected)
// ---------------------------------------------------------------------------
enum AccessLevel implements WireEnum {
  visitor('visitor', 'زائر', 'Visitor'),
  registered('registered', 'مسجّل', 'Registered'),
  approvedMember('approved_member', 'عضو معتمد', 'Approved member'),
  granted('granted', 'مُمنوح الملف الكامل', 'Granted full file'),
  connected('connected', 'متصل', 'Connected');

  const AccessLevel(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static AccessLevel fromValue(String? raw) => _parse(AccessLevel.values, raw);
}

// ---------------------------------------------------------------------------
// OpportunityStatus (§2, §3 state machine ADM-01) with brand status colours (§10)
// ---------------------------------------------------------------------------
enum OpportunityStatus implements WireEnum {
  draft('draft', 'مسودة', 'Draft', Color(0xFF94A3B8)),
  newStatus('new', 'جديدة', 'New', Color(0xFF1F5BFF)),
  underReview('under_review', 'قيد المراجعة', 'Under review', Color(0xFFB45309)),
  needsCompletion(
    'needs_completion',
    'بحاجة إلى استكمال',
    'Needs completion',
    Color(0xFFD97706),
  ),
  notEligible('not_eligible', 'غير مؤهلة', 'Not eligible', Color(0xFFB91C1C)),
  eligible('eligible', 'مؤهلة', 'Eligible', Color(0xFF047857)),
  connected('connected', 'تم الربط', 'Connected', Color(0xFF0F766E)),
  negotiating('negotiating', 'قيد التفاوض', 'Negotiating', Color(0xFF6D28D9)),
  inExecution('in_execution', 'قيد التنفيذ', 'In execution', Color(0xFF1D4ED8)),
  operating('operating', 'قيد التشغيل', 'Operating', Color(0xFF065F46)),
  withdrawn('withdrawn', 'مسحوبة', 'Withdrawn', Color(0xFF6B7280)),
  closed('closed', 'مغلقة', 'Closed', Color(0xFF374151));

  const OpportunityStatus(this.value, this.labelAr, this.labelEn, this.color);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;
  final Color color;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  /// Statuses never visible to investors (SPEC §3 visibility rule).
  bool get isInvestorHidden => const {
        OpportunityStatus.draft,
        OpportunityStatus.newStatus,
        OpportunityStatus.underReview,
        OpportunityStatus.needsCompletion,
        OpportunityStatus.notEligible,
      }.contains(this);

  /// Owner can withdraw before `connected` (ENT-07).
  bool get ownerCanWithdraw => const {
        OpportunityStatus.draft,
        OpportunityStatus.newStatus,
        OpportunityStatus.underReview,
        OpportunityStatus.needsCompletion,
        OpportunityStatus.notEligible,
        OpportunityStatus.eligible,
      }.contains(this);

  static OpportunityStatus fromValue(String? raw) =>
      _parse(OpportunityStatus.values, raw, OpportunityStatus.draft);
}

enum MembershipStatus implements WireEnum {
  registered('registered', 'مسجّل', 'Registered'),
  underReview('under_review', 'قيد المراجعة', 'Under review'),
  needsCompletion('needs_completion', 'بحاجة إلى استكمال', 'Needs completion'),
  approved('approved', 'معتمدة', 'Approved'),
  suspended('suspended', 'موقوفة', 'Suspended'),
  rejected('rejected', 'مرفوضة', 'Rejected');

  const MembershipStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static MembershipStatus fromValue(String? raw) =>
      _parse(MembershipStatus.values, raw);
}

enum InterestStatus implements WireEnum {
  sent('sent', 'مُرسل', 'Sent'),
  awaitingOwnerApproval(
    'awaiting_owner_approval',
    'بانتظار موافقة صاحب المشروع',
    'Awaiting owner approval',
  ),
  shared('shared', 'تمت المشاركة', 'Shared'),
  connected('connected', 'تم الربط', 'Connected'),
  meeting('meeting', 'اجتماع', 'Meeting'),
  closed('closed', 'مغلق', 'Closed');

  const InterestStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  bool get isOpen => this != InterestStatus.closed;

  static InterestStatus fromValue(String? raw) => _parse(InterestStatus.values, raw);
}

enum InfoRequestStatus implements WireEnum {
  sent('sent', 'مُرسل', 'Sent'),
  processing('processing', 'قيد المعالجة', 'Processing'),
  answered('answered', 'تمت الإجابة', 'Answered'),
  closed('closed', 'مغلق', 'Closed');

  const InfoRequestStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static InfoRequestStatus fromValue(String? raw) =>
      _parse(InfoRequestStatus.values, raw);
}

/// ع / د / س / ح (doc §6.2)
enum Sensitivity implements WireEnum {
  public('public', 'عام (ع)', 'Public'),
  internal('internal', 'داخلي (د)', 'Internal'),
  confidential('confidential', 'سري (س)', 'Confidential'),
  sensitivePersonal('sensitive_personal', 'حساس شخصي (ح)', 'Sensitive personal');

  const Sensitivity(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static Sensitivity fromValue(String? raw) => _parse(Sensitivity.values, raw);
}

enum EvaluationAxis implements WireEnum {
  readiness('readiness', 'الجاهزية', 'Readiness'),
  feasibility('feasibility', 'الجدوى', 'Feasibility'),
  impact('impact', 'الأثر', 'Impact');

  const EvaluationAxis(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static EvaluationAxis fromValue(String? raw) => _parse(EvaluationAxis.values, raw);
}

enum EvaluationResult implements WireEnum {
  eligible('eligible', 'مؤهلة', 'Eligible'),
  eligibleWithConditions(
    'eligible_with_conditions',
    'مؤهلة بشروط',
    'Eligible with conditions',
  ),
  notEligibleNow('not_eligible_now', 'غير مؤهلة حاليًا', 'Not eligible now');

  const EvaluationResult(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static EvaluationResult fromValue(String? raw) =>
      _parse(EvaluationResult.values, raw);
}

enum AssignmentStatus implements WireEnum {
  assigned('assigned', 'مُسند', 'Assigned'),
  coiDeclared('coi_declared', 'تم إقرار تعارض المصالح', 'COI declared'),
  scored('scored', 'تم التقييم', 'Scored'),
  withdrawn('withdrawn', 'منسحب', 'Withdrawn');

  const AssignmentStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static AssignmentStatus fromValue(String? raw) =>
      _parse(AssignmentStatus.values, raw);
}

enum DocumentKind implements WireEnum {
  pitchDeck('pitch_deck', 'العرض التعريفي', 'Pitch deck'),
  commercialRegister('commercial_register', 'السجل التجاري', 'Commercial register'),
  license('license', 'الترخيص', 'License'),
  financialStatements(
    'financial_statements',
    'القوائم المالية',
    'Financial statements',
  ),
  businessPlan('business_plan', 'خطة العمل', 'Business plan'),
  teamCv('team_cv', 'السير الذاتية للفريق', 'Team CVs'),
  impactEvidence('impact_evidence', 'أدلة الأثر', 'Impact evidence'),
  authorizationLetter(
    'authorization_letter',
    'خطاب التفويض',
    'Authorization letter',
  ),
  financialProjections(
    'financial_projections',
    'التوقعات المالية',
    'Financial projections',
  ),
  agreement('agreement', 'اتفاقية', 'Agreement'),
  progressEvidence('progress_evidence', 'أدلة التقدم', 'Progress evidence'),
  other('other', 'أخرى', 'Other');

  const DocumentKind(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static DocumentKind fromValue(String? raw) =>
      _parse(DocumentKind.values, raw, DocumentKind.other);
}

enum DocumentStatus implements WireEnum {
  pendingReview('pending_review', 'بانتظار المراجعة', 'Pending review'),
  accepted('accepted', 'مقبول', 'Accepted'),
  required('required', 'مطلوب', 'Required'),
  rejected('rejected', 'مرفوض', 'Rejected');

  const DocumentStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static DocumentStatus fromValue(String? raw) => _parse(DocumentStatus.values, raw);
}

enum IndicatorValueStatus implements WireEnum {
  entered('entered', 'مُدخل', 'Entered'),
  verified('verified', 'موثّق', 'Verified'),
  needsClarification(
    'needs_clarification',
    'بحاجة إلى توضيح',
    'Needs clarification',
  );

  const IndicatorValueStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static IndicatorValueStatus fromValue(String? raw) =>
      _parse(IndicatorValueStatus.values, raw);
}

enum ExecutionStatus implements WireEnum {
  onTrack('on_track', 'على المسار', 'On track'),
  delayed('delayed', 'متأخر', 'Delayed'),
  stalled('stalled', 'متوقف', 'Stalled');

  const ExecutionStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static ExecutionStatus fromValue(String? raw) =>
      _parse(ExecutionStatus.values, raw);
}

enum MeetingStatus implements WireEnum {
  scheduled('scheduled', 'مجدول', 'Scheduled'),
  held('held', 'انعقد', 'Held'),
  cancelled('cancelled', 'ملغى', 'Cancelled');

  const MeetingStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static MeetingStatus fromValue(String? raw) => _parse(MeetingStatus.values, raw);
}

enum AgreementStatus implements WireEnum {
  draft('draft', 'مسودة', 'Draft'),
  legalReview('legal_review', 'مراجعة قانونية', 'Legal review'),
  approved('approved', 'معتمدة', 'Approved'),
  signed('signed', 'موقّعة', 'Signed'),
  archived('archived', 'مؤرشفة', 'Archived');

  const AgreementStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static AgreementStatus fromValue(String? raw) =>
      _parse(AgreementStatus.values, raw);
}

enum ConsentType implements WireEnum {
  privacyPolicy('privacy_policy', 'سياسة الخصوصية', 'Privacy policy'),
  terms('terms', 'الشروط والأحكام', 'Terms'),
  teaserPublication('teaser_publication', 'نشر الملخص التعريفي', 'Teaser publication'),
  fullFileShare('full_file_share', 'مشاركة الملف الكامل', 'Full file share'),
  nda('nda', 'اتفاقية عدم الإفصاح', 'NDA'),
  dataAccuracy('data_accuracy', 'إقرار صحة البيانات', 'Data accuracy');

  const ConsentType(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static ConsentType fromValue(String? raw) => _parse(ConsentType.values, raw);
}

enum NotificationChannel implements WireEnum {
  database('database', 'داخل المنصة', 'In-app'),
  mail('mail', 'البريد الإلكتروني', 'Email'),
  sms('sms', 'رسالة نصية', 'SMS');

  const NotificationChannel(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static NotificationChannel fromValue(String? raw) =>
      _parse(NotificationChannel.values, raw);
}

enum PaymentStatus implements WireEnum {
  pending('pending', 'قيد الانتظار', 'Pending'),
  authorized('authorized', 'مُصرّح', 'Authorized'),
  paid('paid', 'مدفوع', 'Paid'),
  failed('failed', 'فشل', 'Failed'),
  refunded('refunded', 'مُسترد', 'Refunded'),
  cancelled('cancelled', 'ملغى', 'Cancelled');

  const PaymentStatus(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static PaymentStatus fromValue(String? raw) => _parse(PaymentStatus.values, raw);
}

enum PaymentPurpose implements WireEnum {
  membershipFee('membership_fee', 'رسوم العضوية', 'Membership fee'),
  serviceFee('service_fee', 'رسوم خدمة', 'Service fee'),
  training('training', 'تدريب', 'Training'),
  sponsorship('sponsorship', 'رعاية', 'Sponsorship');

  const PaymentPurpose(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static PaymentPurpose fromValue(String? raw) => _parse(PaymentPurpose.values, raw);
}

/// 9 seeded investment types (taxonomy, but mirrored as enum for UI chips).
enum InvestmentType implements WireEnum {
  equity('equity', 'ملكية', 'Equity'),
  debt('debt', 'دين', 'Debt'),
  payForResults('pay_for_results', 'الدفع مقابل النتائج', 'Pay for results'),
  grant('grant', 'منحة', 'Grant'),
  guarantee('guarantee', 'ضمان', 'Guarantee'),
  patientCapital('patient_capital', 'رأس مال صبور', 'Patient capital'),
  convertible('convertible', 'قابل للتحويل', 'Convertible'),
  revenueShare('revenue_share', 'مشاركة الإيرادات', 'Revenue share'),
  blended('blended', 'تمويل مختلط', 'Blended');

  const InvestmentType(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static InvestmentType fromValue(String? raw) => _parse(InvestmentType.values, raw);
}

enum ProjectStage implements WireEnum {
  idea('idea', 'فكرة', 'Idea'),
  prototype('prototype', 'نموذج أولي', 'Prototype'),
  preSeed('pre_seed', 'ما قبل البذرة', 'Pre-seed'),
  seed('seed', 'بذرة', 'Seed'),
  earlyStage('early_stage', 'مرحلة مبكرة', 'Early stage'),
  laterStage('later_stage', 'مرحلة متقدمة', 'Later stage');

  const ProjectStage(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static ProjectStage fromValue(String? raw) => _parse(ProjectStage.values, raw);
}

enum RiskAppetite implements WireEnum {
  high('high', 'مرتفع', 'High'),
  medium('medium', 'متوسط', 'Medium'),
  low('low', 'منخفض', 'Low');

  const RiskAppetite(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static RiskAppetite fromValue(String? raw) => _parse(RiskAppetite.values, raw);
}

enum RevenueState implements WireEnum {
  none('none', 'لا توجد إيرادات', 'No revenue'),
  early('early', 'إيرادات مبكرة', 'Early revenue'),
  regular('regular', 'إيرادات منتظمة', 'Regular revenue');

  const RevenueState(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static RevenueState fromValue(String? raw) => _parse(RevenueState.values, raw);
}

enum LegalForm implements WireEnum {
  company('company', 'شركة', 'Company'),
  establishment('establishment', 'مؤسسة', 'Establishment'),
  nonprofit('nonprofit', 'منظمة غير ربحية', 'Nonprofit'),
  other('other', 'أخرى', 'Other');

  const LegalForm(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static LegalForm fromValue(String? raw) => _parse(LegalForm.values, raw, LegalForm.other);
}

enum InstitutionType implements WireEnum {
  company('company', 'شركة', 'Company'),
  fund('fund', 'صندوق', 'Fund'),
  familyOffice('family_office', 'مكتب عائلي', 'Family office'),
  donor('donor', 'جهة مانحة', 'Donor'),
  nonprofit('nonprofit', 'منظمة غير ربحية', 'Nonprofit'),
  other('other', 'أخرى', 'Other');

  const InstitutionType(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static InstitutionType fromValue(String? raw) =>
      _parse(InstitutionType.values, raw, InstitutionType.other);
}

/// Sort options for GET /opportunities (SPEC §6 conventions).
enum OpportunitySort implements WireEnum {
  newest('newest', 'الأحدث', 'Newest'),
  impact('impact', 'الأثر', 'Impact'),
  need('need', 'الحاجة التمويلية', 'Funding need');

  const OpportunitySort(this.value, this.labelAr, this.labelEn);

  @override
  final String value;
  @override
  final String labelAr;
  @override
  final String labelEn;

  @override
  String label(Locale locale) => locale.languageCode == 'ar' ? labelAr : labelEn;

  static OpportunitySort fromValue(String? raw) => _parse(OpportunitySort.values, raw);
}
