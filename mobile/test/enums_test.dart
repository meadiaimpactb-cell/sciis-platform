import 'package:flutter_test/flutter_test.dart';
import 'package:sciis_mobile/core/enums.dart';

void main() {
  group('Enums mirror SPEC §2 wire values', () {
    test('OpportunityStatus values', () {
      expect(
        OpportunityStatus.values.map((e) => e.value).toList(),
        [
          'draft', 'new', 'under_review', 'needs_completion', 'not_eligible',
          'eligible', 'connected', 'negotiating', 'in_execution', 'operating',
          'withdrawn', 'closed',
        ],
      );
    });

    test('MembershipStatus values', () {
      expect(
        MembershipStatus.values.map((e) => e.value).toList(),
        ['registered', 'under_review', 'needs_completion', 'approved', 'suspended', 'rejected'],
      );
    });

    test('InterestStatus values', () {
      expect(
        InterestStatus.values.map((e) => e.value).toList(),
        ['sent', 'awaiting_owner_approval', 'shared', 'connected', 'meeting', 'closed'],
      );
    });

    test('Sensitivity values', () {
      expect(
        Sensitivity.values.map((e) => e.value).toList(),
        ['public', 'internal', 'confidential', 'sensitive_personal'],
      );
    });

    test('DocumentKind has 12 kinds', () {
      expect(DocumentKind.values.length, 12);
    });

    test('InvestmentType has 9 seeded types', () {
      expect(InvestmentType.values.length, 9);
    });

    test('fromValue is tolerant', () {
      expect(OpportunityStatus.fromValue('eligible'), OpportunityStatus.eligible);
      expect(OpportunityStatus.fromValue('unknown'), OpportunityStatus.draft);
      expect(OpportunityStatus.fromValue(null), OpportunityStatus.draft);
    });

    test('investor-hidden statuses per SPEC §3', () {
      for (final s in [
        OpportunityStatus.draft,
        OpportunityStatus.newStatus,
        OpportunityStatus.underReview,
        OpportunityStatus.needsCompletion,
        OpportunityStatus.notEligible,
      ]) {
        expect(s.isInvestorHidden, isTrue, reason: s.value);
      }
      expect(OpportunityStatus.eligible.isInvestorHidden, isFalse);
    });
  });
}
