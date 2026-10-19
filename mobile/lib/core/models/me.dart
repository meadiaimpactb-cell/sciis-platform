// GET /me → user + type + roles + membership status + completion (SPEC §6).

import '../enums.dart';
import 'common.dart';

class Membership {
  const Membership({
    required this.status,
    this.submittedAt,
    this.approvedAt,
    this.termsAcceptedAt,
    this.suspendedReason,
    this.needsCompletionItems = const [],
  });

  factory Membership.fromJson(Map<String, dynamic> json) => Membership(
        status: MembershipStatus.fromValue(json['status'] as String?),
        submittedAt: parseDate(json['submitted_at']),
        approvedAt: parseDate(json['approved_at']),
        termsAcceptedAt: parseDate(json['terms_accepted_at']),
        suspendedReason: json['suspended_reason'] as String?,
        needsCompletionItems: listOfStrings(json['needs_completion_items']),
      );

  final MembershipStatus status;
  final DateTime? submittedAt;
  final DateTime? approvedAt;
  final DateTime? termsAcceptedAt;
  final String? suspendedReason;

  /// Items the membership officer asked for (shown as "المطلوب منك الآن").
  final List<String> needsCompletionItems;

  bool get isApproved => status == MembershipStatus.approved;
}

class Me {
  const Me({
    required this.uuid,
    required this.name,
    required this.email,
    required this.type,
    required this.locale,
    required this.roles,
    required this.permissions,
    required this.accessLevel,
    required this.completionPercent,
    required this.emailVerified,
    required this.phoneVerified,
    required this.twoFactorEnabled,
    this.phone,
    this.membership,
    this.organizationName,
    this.unreadNotifications = 0,
  });

  factory Me.fromJson(Map<String, dynamic> json) {
    final user = json.containsKey('user') ? asMap(json['user']) : json;
    return Me(
      uuid: (user['uuid'] ?? '').toString(),
      name: (user['name'] ?? '').toString(),
      email: (user['email'] ?? '').toString(),
      phone: user['phone'] as String?,
      type: UserType.fromValue(user['type'] as String?),
      locale: (user['locale'] ?? 'ar').toString(),
      roles: listOfStrings(json['roles'] ?? user['roles']),
      permissions: listOfStrings(json['permissions'] ?? user['permissions']),
      accessLevel: AccessLevel.fromValue(
        (json['access_level'] ?? user['access_level']) as String?,
      ),
      completionPercent: parseInt(json['completion_percent'] ?? user['completion_percent']) ?? 0,
      emailVerified: user['email_verified_at'] != null || parseBool(user['email_verified']),
      phoneVerified: user['phone_verified_at'] != null || parseBool(user['phone_verified']),
      twoFactorEnabled: user['two_factor_confirmed_at'] != null ||
          parseBool(user['two_factor_enabled']),
      membership: json['membership'] == null
          ? null
          : Membership.fromJson(asMap(json['membership'])),
      organizationName: asMap(user['organization'])['name'] as String?,
      unreadNotifications: parseInt(json['unread_notifications']) ?? 0,
    );
  }

  final String uuid;
  final String name;
  final String email;
  final String? phone;
  final UserType type;
  final String locale;
  final List<String> roles;
  final List<String> permissions;
  final AccessLevel accessLevel;

  /// Investor profile completion (investors) or opportunity file completion
  /// (entrepreneurs) — 0..100, computed server-side.
  final int completionPercent;
  final bool emailVerified;
  final bool phoneVerified;
  final bool twoFactorEnabled;
  final Membership? membership;
  final String? organizationName;
  final int unreadNotifications;

  bool get isVerified => emailVerified && phoneVerified;
  bool get isApprovedMember => membership?.isApproved ?? false;
  bool get isStaff => type == UserType.staff;
  bool can(String permission) => permissions.contains(permission);
}

class InvestorDashboard {
  const InvestorDashboard({
    required this.counters,
    required this.completionPercent,
    required this.updates,
    this.nextMeeting,
    this.suggestedCount = 0,
  });

  factory InvestorDashboard.fromJson(Map<String, dynamic> json) {
    final counters = asMap(json['counters']);
    return InvestorDashboard(
      counters: DashboardCounters(
        saved: parseInt(counters['saved']) ?? 0,
        interests: parseInt(counters['interests']) ?? 0,
        infoRequests: parseInt(counters['info_requests']) ?? 0,
        meetings: parseInt(counters['meetings']) ?? 0,
      ),
      completionPercent: parseInt(json['completion_percent']) ?? 0,
      updates: listOfMaps(json['updates']).map(DashboardUpdate.fromJson).toList(),
      nextMeeting: json['next_meeting'] == null ? null : asMap(json['next_meeting']),
      suggestedCount: (json['suggested'] is List) ? (json['suggested'] as List).length : 0,
    );
  }

  final DashboardCounters counters;
  final int completionPercent;
  final List<DashboardUpdate> updates;
  final Map<String, dynamic>? nextMeeting;
  final int suggestedCount;
}

class DashboardCounters {
  const DashboardCounters({
    required this.saved,
    required this.interests,
    required this.infoRequests,
    required this.meetings,
  });

  final int saved;
  final int interests;
  final int infoRequests;
  final int meetings;
}

class DashboardUpdate {
  const DashboardUpdate({required this.title, required this.at, this.opportunityUuid});

  factory DashboardUpdate.fromJson(Map<String, dynamic> json) => DashboardUpdate(
        title: (json['title'] ?? json['message'] ?? '').toString(),
        at: parseDate(json['at'] ?? json['created_at']) ?? DateTime.now(),
        opportunityUuid: json['opportunity_uuid'] as String?,
      );

  final String title;
  final DateTime at;
  final String? opportunityUuid;
}
