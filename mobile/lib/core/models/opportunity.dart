// Opportunity resources as shaped by the API per access level (SPEC §5).
//
// OpportunityCard   ← OpportunityCardResource   (teaser / list, public fields only)
// OpportunityDetail ← OpportunityDetailResource (tabs; confidential groups appear
//                     only when `access_level` is granted/connected and are
//                     otherwise absent or summarised by the API — the app never
//                     tries to "unlock" anything client-side).

import '../enums.dart';
import 'common.dart';

class OpportunityCard {
  const OpportunityCard({
    required this.uuid,
    required this.referenceNo,
    required this.name,
    required this.organizationName,
    required this.teaserSummary,
    required this.sector,
    required this.stage,
    required this.status,
    required this.regions,
    required this.investmentTypes,
    required this.beneficiaryGroups,
    this.foundedYear,
    this.beneficiariesTarget,
    this.fundingDisplay,
    this.isSaved = false,
    this.publishedAt,
  });

  factory OpportunityCard.fromJson(Map<String, dynamic> json) => OpportunityCard(
        uuid: json['uuid'] as String,
        referenceNo: (json['reference_no'] ?? '').toString(),
        name: (json['name'] ?? '').toString(),
        organizationName: json['organization_name'] as String?,
        teaserSummary: (json['teaser_summary'] ?? '').toString(),
        sector: TaxonomyTerm.fromJson(asMap(json['sector'])),
        stage: ProjectStage.fromValue(json['stage'] as String?),
        status: OpportunityStatus.fromValue(json['status'] as String?),
        regions: TaxonomyTerm.listFromJson(json['regions']),
        investmentTypes: TaxonomyTerm.listFromJson(json['investment_types']),
        beneficiaryGroups: TaxonomyTerm.listFromJson(json['beneficiary_groups']),
        foundedYear: parseInt(json['founded_year']),
        beneficiariesTarget: parseInt(json['beneficiaries_target']),
        fundingDisplay: json['funding_display'] as String?,
        isSaved: parseBool(json['is_saved']),
        publishedAt: parseDate(json['published_at']),
      );

  final String uuid;
  final String referenceNo;
  final String name;
  final String? organizationName;
  final String teaserSummary;
  final TaxonomyTerm sector;
  final ProjectStage stage;
  final OpportunityStatus status;
  final List<TaxonomyTerm> regions;
  final List<TaxonomyTerm> investmentTypes;
  final List<TaxonomyTerm> beneficiaryGroups;
  final int? foundedYear;
  final int? beneficiariesTarget;

  /// Already shaped by the API per `settings.opportunity.funding_display`
  /// (`band` → "1–3 مليون ريال", `exact` → "2,500,000 ريال", `hidden` → null).
  final String? fundingDisplay;
  final bool isSaved;
  final DateTime? publishedAt;

  OpportunityCard copyWith({bool? isSaved}) => OpportunityCard(
        uuid: uuid,
        referenceNo: referenceNo,
        name: name,
        organizationName: organizationName,
        teaserSummary: teaserSummary,
        sector: sector,
        stage: stage,
        status: status,
        regions: regions,
        investmentTypes: investmentTypes,
        beneficiaryGroups: beneficiaryGroups,
        foundedYear: foundedYear,
        beneficiariesTarget: beneficiariesTarget,
        fundingDisplay: fundingDisplay,
        isSaved: isSaved ?? this.isSaved,
        publishedAt: publishedAt,
      );
}

class ImpactIndicator {
  const ImpactIndicator({
    required this.id,
    required this.name,
    required this.unit,
    required this.isPrimary,
    required this.isDisclosed,
    this.definition,
    this.baseline,
    this.target,
    this.latestValue,
    this.frequency,
    this.sdg,
  });

  factory ImpactIndicator.fromJson(Map<String, dynamic> json) => ImpactIndicator(
        id: parseInt(json['id']) ?? 0,
        name: (json['name'] ?? '').toString(),
        definition: json['definition'] as String?,
        unit: (json['unit'] ?? '').toString(),
        baseline: parseDouble(json['baseline']),
        target: parseDouble(json['target']),
        latestValue: parseDouble(json['latest_value']),
        frequency: json['frequency'] as String?,
        isPrimary: parseBool(json['is_primary']),
        isDisclosed: parseBool(json['is_disclosed']),
        sdg: json['sdg'] == null ? null : TaxonomyTerm.fromJson(asMap(json['sdg'])),
      );

  final int id;
  final String name;
  final String? definition;
  final String unit;
  final double? baseline;
  final double? target;
  final double? latestValue;
  final String? frequency;
  final bool isPrimary;
  final bool isDisclosed;
  final TaxonomyTerm? sdg;

  double? get progress {
    if (baseline == null || target == null || latestValue == null) return null;
    final span = target! - baseline!;
    if (span == 0) return null;
    return ((latestValue! - baseline!) / span).clamp(0.0, 1.0).toDouble();
  }
}

class UseOfFundsItem {
  const UseOfFundsItem({required this.item, required this.percent});

  factory UseOfFundsItem.fromJson(Map<String, dynamic> json) => UseOfFundsItem(
        item: (json['item'] ?? '').toString(),
        percent: parseDouble(json['percent']) ?? 0,
      );

  final String item;
  final double percent;
}

class DocumentSummary {
  const DocumentSummary({
    required this.uuid,
    required this.kind,
    required this.title,
    required this.sensitivity,
    required this.status,
    this.sizeBytes,
    this.mime,
    this.version = 1,
  });

  factory DocumentSummary.fromJson(Map<String, dynamic> json) => DocumentSummary(
        uuid: json['uuid'] as String,
        kind: DocumentKind.fromValue(json['kind'] as String?),
        title: (json['title'] ?? '').toString(),
        sensitivity: Sensitivity.fromValue(json['sensitivity'] as String?),
        status: DocumentStatus.fromValue(json['status'] as String?),
        sizeBytes: parseInt(json['size_bytes']),
        mime: json['mime'] as String?,
        version: parseInt(json['version']) ?? 1,
      );

  final String uuid;
  final DocumentKind kind;
  final String title;
  final Sensitivity sensitivity;
  final DocumentStatus status;
  final int? sizeBytes;
  final String? mime;
  final int version;
}

/// Owner contact — only present when interest status == connected (sensitive_personal).
class OwnerContact {
  const OwnerContact({required this.name, this.email, this.phone});

  factory OwnerContact.fromJson(Map<String, dynamic> json) => OwnerContact(
        name: (json['name'] ?? '').toString(),
        email: json['email'] as String?,
        phone: json['phone'] as String?,
      );

  final String name;
  final String? email;
  final String? phone;
}

class OpportunityDetail {
  const OpportunityDetail({
    required this.card,
    required this.accessLevel,
    required this.problemStatement,
    required this.solution,
    required this.expectedChange,
    required this.beneficiariesDesc,
    required this.indicators,
    required this.sdgs,
    required this.documentsLocked,
    required this.documents,
    required this.useOfFunds,
    this.beneficiariesCurrent,
    this.fundingDisplay,
    this.fundingAmount,
    this.useOfFundsSummary,
    this.risksSummary,
    this.risksFull,
    this.licenseStatus,
    this.revenueState,
    this.revenueModelDesc,
    this.website,
    this.interestStatus,
    this.infoRequestOpen = false,
    this.ndaAccepted = false,
    this.ownerContact,
    this.lastUpdatedAt,
  });

  factory OpportunityDetail.fromJson(Map<String, dynamic> json) {
    final docsRaw = json['documents'];
    final locked = docsRaw == null ||
        (docsRaw is Map<String, dynamic> && parseBool(docsRaw['locked'], fallback: true));
    final docList = docsRaw is Map<String, dynamic>
        ? listOfMaps(docsRaw['items'])
        : listOfMaps(docsRaw);
    final risks = asMap(json['risks']);
    final funds = json['use_of_funds'];

    return OpportunityDetail(
      card: OpportunityCard.fromJson(json),
      accessLevel: AccessLevel.fromValue(json['access_level'] as String?),
      problemStatement: (json['problem_statement'] ?? '').toString(),
      solution: (json['solution'] ?? '').toString(),
      expectedChange: (json['expected_change'] ?? '').toString(),
      beneficiariesDesc: (json['beneficiaries_desc'] ?? '').toString(),
      beneficiariesCurrent: parseInt(json['beneficiaries_current']),
      indicators: listOfMaps(json['indicators'])
          .map(ImpactIndicator.fromJson)
          .toList(growable: false),
      sdgs: TaxonomyTerm.listFromJson(json['sdgs']),
      fundingDisplay: json['funding_display'] as String?,
      fundingAmount: parseDouble(json['funding_amount']),
      useOfFundsSummary: funds is Map<String, dynamic>
          ? funds['summary'] as String?
          : null,
      useOfFunds: funds is List
          ? listOfMaps(funds).map(UseOfFundsItem.fromJson).toList(growable: false)
          : listOfMaps(asMap(funds)['items'])
              .map(UseOfFundsItem.fromJson)
              .toList(growable: false),
      risksSummary: risks['summary'] as String?,
      risksFull: risks['full'] as String?,
      licenseStatus: json['license_status'] as String?,
      revenueState: json['revenue_state'] == null
          ? null
          : RevenueState.fromValue(json['revenue_state'] as String?),
      revenueModelDesc: json['revenue_model_desc'] as String?,
      website: json['website'] as String?,
      documentsLocked: locked,
      documents: docList.map(DocumentSummary.fromJson).toList(growable: false),
      interestStatus: json['interest_status'] == null
          ? null
          : InterestStatus.fromValue(json['interest_status'] as String?),
      infoRequestOpen: parseBool(json['info_request_open']),
      ndaAccepted: parseBool(json['nda_accepted']),
      ownerContact: json['owner_contact'] == null
          ? null
          : OwnerContact.fromJson(asMap(json['owner_contact'])),
      lastUpdatedAt: parseDate(json['updated_at']),
    );
  }

  final OpportunityCard card;
  final AccessLevel accessLevel;

  // Overview (public)
  final String problemStatement;
  final String solution;
  final String expectedChange;
  final String beneficiariesDesc;
  final int? beneficiariesCurrent;
  final String? website;

  // Impact & indicators (public: primary + disclosed)
  final List<ImpactIndicator> indicators;
  final List<TaxonomyTerm> sdgs;

  // Financials (band/exact per settings; exact only after grant)
  final String? fundingDisplay;
  final double? fundingAmount;
  final String? useOfFundsSummary;
  final List<UseOfFundsItem> useOfFunds;
  final RevenueState? revenueState;
  final String? revenueModelDesc;

  // Risks (summary before grant, full after)
  final String? risksSummary;
  final String? risksFull;
  final String? licenseStatus;

  // Documents (locked before grant)
  final bool documentsLocked;
  final List<DocumentSummary> documents;

  // Caller-specific state
  final InterestStatus? interestStatus;
  final bool infoRequestOpen;
  final bool ndaAccepted;
  final OwnerContact? ownerContact;
  final DateTime? lastUpdatedAt;

  bool get isGranted =>
      accessLevel == AccessLevel.granted || accessLevel == AccessLevel.connected;
  bool get canExpressInterest => interestStatus == null;
  ImpactIndicator? get primaryIndicator {
    for (final i in indicators) {
      if (i.isPrimary) return i;
    }
    return indicators.isEmpty ? null : indicators.first;
  }
}
