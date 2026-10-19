// Interest (investor ↔ opportunity, dual consent) and Nomination resources.

import '../enums.dart';
import 'common.dart';

class Interest {
  const Interest({
    required this.id,
    required this.opportunityUuid,
    required this.opportunityName,
    required this.status,
    required this.createdAt,
    this.referenceNo,
    this.ownerDecision,
    this.ownerDecidedAt,
    this.note,
    this.closedReason,
    this.closedAt,
    this.nextMeetingAt,
  });

  factory Interest.fromJson(Map<String, dynamic> json) {
    final opp = asMap(json['opportunity']);
    return Interest(
      id: parseInt(json['id']) ?? 0,
      opportunityUuid: (opp['uuid'] ?? json['opportunity_uuid'] ?? '').toString(),
      opportunityName: (opp['name'] ?? json['opportunity_name'] ?? '').toString(),
      referenceNo: (opp['reference_no'] ?? json['reference_no']) as String?,
      status: InterestStatus.fromValue(json['status'] as String?),
      ownerDecision: json['owner_decision'] as String?,
      ownerDecidedAt: parseDate(json['owner_decided_at']),
      note: json['note'] as String?,
      closedReason: json['closed_reason'] as String?,
      closedAt: parseDate(json['closed_at']),
      nextMeetingAt: parseDate(json['next_meeting_at']),
      createdAt: parseDate(json['created_at']) ?? DateTime.now(),
    );
  }

  final int id;
  final String opportunityUuid;
  final String opportunityName;
  final String? referenceNo;
  final InterestStatus status;

  /// `approved | declined | null` — owner side of the dual consent (ENT-06).
  final String? ownerDecision;
  final DateTime? ownerDecidedAt;
  final String? note;
  final String? closedReason;
  final DateTime? closedAt;
  final DateTime? nextMeetingAt;
  final DateTime createdAt;

  bool get isOpen => status.isOpen;
}

class Nomination {
  const Nomination({
    required this.id,
    required this.opportunityUuid,
    required this.opportunityName,
    required this.matchedCriteria,
    required this.matchScore,
    required this.rationale,
    required this.sentAt,
    this.teaserSummary,
    this.response,
    this.respondedAt,
    this.declineReason,
  });

  factory Nomination.fromJson(Map<String, dynamic> json) {
    final opp = asMap(json['opportunity']);
    return Nomination(
      id: parseInt(json['id']) ?? 0,
      opportunityUuid: (opp['uuid'] ?? json['opportunity_uuid'] ?? '').toString(),
      opportunityName: (opp['name'] ?? json['opportunity_name'] ?? '').toString(),
      teaserSummary: (opp['teaser_summary'] ?? json['teaser_summary']) as String?,
      matchedCriteria: listOfStrings(json['matched_criteria']),
      matchScore: parseInt(json['match_score']) ?? 0,
      rationale: (json['rationale'] ?? '').toString(),
      sentAt: parseDate(json['sent_at']) ?? DateTime.now(),
      response: json['response'] as String?,
      respondedAt: parseDate(json['responded_at']),
      declineReason: json['decline_reason'] == null
          ? null
          : TaxonomyTerm.fromJson(asMap(json['decline_reason'])),
    );
  }

  final int id;
  final String opportunityUuid;
  final String opportunityName;
  final String? teaserSummary;

  /// Transparent rule (MAT-02): which of sector/stage/region/type/range matched.
  final List<String> matchedCriteria;
  final int matchScore;

  /// Why the council nominated this investor (MAT-03).
  final String rationale;
  final DateTime sentAt;

  /// `interested | declined | null`.
  final String? response;
  final DateTime? respondedAt;
  final TaxonomyTerm? declineReason;

  bool get isPending => response == null;
}

class InfoRequest {
  const InfoRequest({
    required this.id,
    required this.opportunityUuid,
    required this.opportunityName,
    required this.subject,
    required this.body,
    required this.status,
    required this.createdAt,
    this.answer,
    this.answeredAt,
  });

  factory InfoRequest.fromJson(Map<String, dynamic> json) {
    final opp = asMap(json['opportunity']);
    return InfoRequest(
      id: parseInt(json['id']) ?? 0,
      opportunityUuid: (opp['uuid'] ?? json['opportunity_uuid'] ?? '').toString(),
      opportunityName: (opp['name'] ?? json['opportunity_name'] ?? '').toString(),
      subject: (json['subject'] ?? '').toString(),
      body: (json['body'] ?? '').toString(),
      status: InfoRequestStatus.fromValue(json['status'] as String?),
      answer: json['owner_answer'] as String?,
      answeredAt: parseDate(json['answered_at']),
      createdAt: parseDate(json['created_at']) ?? DateTime.now(),
    );
  }

  final int id;
  final String opportunityUuid;
  final String opportunityName;
  final String subject;
  final String body;
  final InfoRequestStatus status;
  final String? answer;
  final DateTime? answeredAt;
  final DateTime createdAt;
}

class Meeting {
  const Meeting({
    required this.id,
    required this.title,
    required this.startsAt,
    required this.status,
    this.endsAt,
    this.location,
    this.videoUrl,
    this.opportunityName,
    this.opportunityUuid,
  });

  factory Meeting.fromJson(Map<String, dynamic> json) {
    final opp = asMap(json['opportunity']);
    return Meeting(
      id: parseInt(json['id']) ?? 0,
      title: (json['title'] ?? '').toString(),
      startsAt: parseDate(json['starts_at']) ?? DateTime.now(),
      endsAt: parseDate(json['ends_at']),
      location: json['location'] as String?,
      videoUrl: json['video_url'] as String?,
      status: MeetingStatus.fromValue(json['status'] as String?),
      opportunityName: (opp['name'] ?? json['opportunity_name']) as String?,
      opportunityUuid: (opp['uuid'] ?? json['opportunity_uuid']) as String?,
    );
  }

  final int id;
  final String title;
  final DateTime startsAt;
  final DateTime? endsAt;
  final String? location;
  final String? videoUrl;
  final MeetingStatus status;
  final String? opportunityName;
  final String? opportunityUuid;
}
