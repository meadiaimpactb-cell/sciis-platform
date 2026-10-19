// GET /entrepreneur/dashboard → opportunities[], required_now[], access_requests[], completion

import '../enums.dart';
import 'common.dart';

class OwnedOpportunity {
  const OwnedOpportunity({
    required this.uuid,
    required this.name,
    required this.status,
    required this.completionPercent,
    this.referenceNo,
    this.submittedAt,
    this.reviewDueAt,
    this.completionDueAt,
    this.isOverdue = false,
    this.interestsCount = 0,
  });

  factory OwnedOpportunity.fromJson(Map<String, dynamic> json) => OwnedOpportunity(
        uuid: json['uuid'] as String,
        name: (json['name'] ?? '').toString(),
        referenceNo: json['reference_no'] as String?,
        status: OpportunityStatus.fromValue(json['status'] as String?),
        completionPercent: parseInt(json['completion_percent']) ?? 0,
        submittedAt: parseDate(json['submitted_at']),
        reviewDueAt: parseDate(json['review_due_at']),
        completionDueAt: parseDate(json['completion_due_at']),
        isOverdue: parseBool(json['is_overdue']),
        interestsCount: parseInt(json['interests_count']) ?? 0,
      );

  final String uuid;
  final String name;
  final String? referenceNo;
  final OpportunityStatus status;
  final int completionPercent;
  final DateTime? submittedAt;
  final DateTime? reviewDueAt;
  final DateTime? completionDueAt;
  final bool isOverdue;
  final int interestsCount;
}

/// One line of "المطلوب منك الآن" (ADM-04 / ENT-04): a missing field or document.
class RequiredNowItem {
  const RequiredNowItem({
    required this.opportunityUuid,
    required this.opportunityName,
    required this.label,
    this.field,
    this.documentKind,
    this.note,
    this.dueAt,
  });

  factory RequiredNowItem.fromJson(Map<String, dynamic> json) => RequiredNowItem(
        opportunityUuid: (json['opportunity_uuid'] ?? '').toString(),
        opportunityName: (json['opportunity_name'] ?? '').toString(),
        label: (json['label'] ?? json['field'] ?? json['document_kind'] ?? '').toString(),
        field: json['field'] as String?,
        documentKind: json['document_kind'] == null
            ? null
            : DocumentKind.fromValue(json['document_kind'] as String?),
        note: json['note'] as String?,
        dueAt: parseDate(json['due_at']),
      );

  final String opportunityUuid;
  final String opportunityName;
  final String label;
  final String? field;
  final DocumentKind? documentKind;
  final String? note;
  final DateTime? dueAt;

  bool get isOverdue => dueAt != null && dueAt!.isBefore(DateTime.now());
}

/// A pending full-file access request from an (anonymous-to-owner) investor.
class AccessRequest {
  const AccessRequest({
    required this.interestId,
    required this.opportunityUuid,
    required this.opportunityName,
    required this.requestedAt,
    this.investorType,
    this.dueAt,
  });

  factory AccessRequest.fromJson(Map<String, dynamic> json) => AccessRequest(
        interestId: parseInt(json['interest_id'] ?? json['id']) ?? 0,
        opportunityUuid: (json['opportunity_uuid'] ?? '').toString(),
        opportunityName: (json['opportunity_name'] ?? '').toString(),
        // Only the investor *type* is exposed to the owner before connection
        // (OPP-05 / MSG-01: identity is not forwarded).
        investorType: json['investor_type'] == null
            ? null
            : UserType.fromValue(json['investor_type'] as String?),
        requestedAt: parseDate(json['requested_at'] ?? json['created_at']) ?? DateTime.now(),
        dueAt: parseDate(json['due_at']),
      );

  final int interestId;
  final String opportunityUuid;
  final String opportunityName;
  final UserType? investorType;
  final DateTime requestedAt;
  final DateTime? dueAt;
}

class EntrepreneurDashboard {
  const EntrepreneurDashboard({
    required this.opportunities,
    required this.requiredNow,
    required this.accessRequests,
    required this.completionPercent,
  });

  factory EntrepreneurDashboard.fromJson(Map<String, dynamic> json) =>
      EntrepreneurDashboard(
        opportunities: listOfMaps(json['opportunities'])
            .map(OwnedOpportunity.fromJson)
            .toList(growable: false),
        requiredNow: listOfMaps(json['required_now'])
            .map(RequiredNowItem.fromJson)
            .toList(growable: false),
        accessRequests: listOfMaps(json['access_requests'])
            .map(AccessRequest.fromJson)
            .toList(growable: false),
        completionPercent: parseInt(json['completion']) ?? parseInt(json['completion_percent']) ?? 0,
      );

  final List<OwnedOpportunity> opportunities;
  final List<RequiredNowItem> requiredNow;
  final List<AccessRequest> accessRequests;
  final int completionPercent;
}
