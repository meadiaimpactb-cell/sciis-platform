// GET /entrepreneur/dashboard → status strip + "المطلوب منك الآن" + access requests
// (ENT-01, ENT-04, ENT-06, ADM-04).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/enums.dart';
import '../../core/models/common.dart';
import '../../core/models/entrepreneur.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import '../auth/auth_repository.dart';

final entrepreneurDashboardProvider = FutureProvider.autoDispose<EntrepreneurDashboard>(
  (ref) async {
    final json = await ref.watch(apiClientProvider).getJson('/entrepreneur/dashboard');
    return EntrepreneurDashboard.fromJson(asMap(json['data']).isEmpty ? json : asMap(json['data']));
  },
);

class EntrepreneurDashboardScreen extends ConsumerWidget {
  const EntrepreneurDashboardScreen({super.key});

  Future<void> _decide(BuildContext context, WidgetRef ref, AccessRequest r, bool approve) async {
    final l10n = AppLocalizations.of(context);
    String? reason;
    if (!approve) {
      reason = await showDialog<String>(
        context: context,
        builder: (ctx) {
          final c = TextEditingController();
          return AlertDialog(
            title: Text(l10n.accessDeclineTitle),
            content: TextField(
              controller: c,
              maxLines: 3,
              decoration: InputDecoration(hintText: l10n.accessDeclineReason),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: Text(l10n.actionCancel)),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, c.text.trim()),
                child: Text(l10n.accessDecline),
              ),
            ],
          );
        },
      );
      if (reason == null) return;
    }
    try {
      // ENT-06: dual consent — the owner approves/declines full-file access.
      await ref.read(apiClientProvider).postJson(
        '/entrepreneur/access-requests/${r.interestId}/decide',
        body: {
          'decision': approve ? 'approve' : 'decline',
          if (reason != null && reason.isNotEmpty) 'reason': reason,
        },
      );
      ref.invalidate(entrepreneurDashboardProvider);
    } on ApiException catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    final me = ref.watch(sessionProvider).me;
    final dashboard = ref.watch(entrepreneurDashboardProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.navMyProjects),
        actions: [
          IconButton(
            onPressed: () => context.push(AppRoutes.notifications),
            icon: Badge(
              isLabelVisible: (me?.unreadNotifications ?? 0) > 0,
              label: Text('${me?.unreadNotifications ?? 0}'),
              child: const Icon(Icons.notifications_outlined),
            ),
          ),
          IconButton(
            onPressed: () => context.push(AppRoutes.profile),
            icon: const Icon(Icons.person_outline),
          ),
        ],
      ),
      body: dashboard.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(
          message: e is ApiException ? e.message : l10n.errorGeneric,
          retryLabel: l10n.actionRetry,
          onRetry: () => ref.invalidate(entrepreneurDashboardProvider),
        ),
        data: (d) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(entrepreneurDashboardProvider),
          child: ListView(
            padding: const EdgeInsets.all(BrandSpacing.md),
            children: [
              if (me != null) Text(l10n.greeting(me.name.split(' ').first), style: t.headlineSmall),
              const SizedBox(height: BrandSpacing.md),
              if (d.opportunities.isEmpty)
                EmptyState(
                  icon: Icons.add_business_outlined,
                  title: l10n.projectsEmptyTitle,
                  subtitle: l10n.projectsEmptyHint,
                  action: FilledButton.icon(
                    onPressed: () => _notYetMobile(context),
                    icon: const Icon(Icons.add),
                    label: Text(l10n.projectsCreate),
                  ),
                )
              else
                ...d.opportunities.map((o) => _OpportunityStatusStrip(opportunity: o)),
              const SizedBox(height: BrandSpacing.lg),
              SectionTitle(
                l10n.requiredNowTitle,
                trailing: d.requiredNow.isEmpty
                    ? null
                    : StatusChip(
                        label: '${d.requiredNow.length}',
                        color: BrandColors.warning,
                        compact: true,
                      ),
              ),
              if (d.requiredNow.isEmpty)
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.check_circle_outline, color: BrandColors.success),
                    title: Text(l10n.requiredNowEmpty),
                  ),
                )
              else
                ...d.requiredNow.map((item) => _RequiredNowTile(item: item)),
              if (d.accessRequests.isNotEmpty) ...[
                const SizedBox(height: BrandSpacing.lg),
                SectionTitle(l10n.accessRequestsTitle),
                Text(l10n.accessRequestsHint, style: t.bodySmall),
                const SizedBox(height: BrandSpacing.sm),
                ...d.accessRequests.map(
                  (r) => Card(
                    child: Padding(
                      padding: const EdgeInsets.all(BrandSpacing.md),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(r.opportunityName, style: t.titleMedium),
                          const SizedBox(height: 4),
                          Text(
                            '${r.investorType?.label(locale) ?? l10n.investorAnonymous} · ${formatDate(r.requestedAt, locale)}',
                            style: t.bodySmall,
                          ),
                          if (r.dueAt != null)
                            Text(
                              l10n.accessRequestDue(formatDate(r.dueAt!, locale)),
                              style: t.bodySmall?.copyWith(color: BrandColors.warning),
                            ),
                          const SizedBox(height: BrandSpacing.sm),
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton(
                                  onPressed: () => _decide(context, ref, r, false),
                                  child: Text(l10n.accessDecline),
                                ),
                              ),
                              const SizedBox(width: BrandSpacing.sm),
                              Expanded(
                                child: FilledButton(
                                  onPressed: () => _decide(context, ref, r, true),
                                  child: Text(l10n.accessApprove),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
              const SizedBox(height: BrandSpacing.xl),
            ],
          ),
        ),
      ),
    );
  }

  /// Creating/editing the full opportunity file is a desktop-first journey in P1
  /// (long forms, multi-file uploads). The app deep-links to the web portal.
  void _notYetMobile(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.projectsUseWeb)));
  }
}

/// Horizontal status strip: the opportunity lifecycle with the current step
/// highlighted (doc §4.3 figure).
class _OpportunityStatusStrip extends StatelessWidget {
  const _OpportunityStatusStrip({required this.opportunity});

  final OwnedOpportunity opportunity;

  static const _main = [
    OpportunityStatus.draft,
    OpportunityStatus.newStatus,
    OpportunityStatus.underReview,
    OpportunityStatus.eligible,
    OpportunityStatus.connected,
    OpportunityStatus.negotiating,
    OpportunityStatus.inExecution,
    OpportunityStatus.operating,
  ];

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    final status = opportunity.status;
    final idx = _main.indexOf(status);
    final isSide = idx < 0; // needs_completion / not_eligible / withdrawn / closed

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(BrandSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(opportunity.name, style: t.titleMedium)),
                StatusChip.opportunity(status, locale),
              ],
            ),
            if (opportunity.referenceNo != null)
              Text(opportunity.referenceNo!, style: t.bodySmall),
            const SizedBox(height: BrandSpacing.md),
            SizedBox(
              height: 36,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: _main.length,
                separatorBuilder: (_, __) => Container(
                  width: 16,
                  alignment: Alignment.center,
                  child: Container(height: 2, color: BrandColors.border),
                ),
                itemBuilder: (_, i) {
                  final s = _main[i];
                  final done = !isSide && i < idx;
                  final current = !isSide && i == idx;
                  return Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Container(
                        width: 12,
                        height: 12,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: current ? s.color : (done ? BrandColors.success : BrandColors.border),
                          border: current ? Border.all(color: s.color, width: 3) : null,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        s.label(locale),
                        style: t.bodySmall?.copyWith(
                          fontSize: 10,
                          fontWeight: current ? FontWeight.w700 : FontWeight.w400,
                          color: current ? s.color : BrandColors.muted,
                        ),
                      ),
                    ],
                  );
                },
              ),
            ),
            const SizedBox(height: BrandSpacing.md),
            CompletionBar(percent: opportunity.completionPercent, label: l10n.fileCompletion),
            if (opportunity.isOverdue) ...[
              const SizedBox(height: BrandSpacing.sm),
              Row(
                children: [
                  const Icon(Icons.schedule, size: 16, color: BrandColors.danger),
                  const SizedBox(width: 4),
                  Text(l10n.overdue, style: t.bodySmall?.copyWith(color: BrandColors.danger)),
                ],
              ),
            ],
            if (status == OpportunityStatus.draft || status == OpportunityStatus.needsCompletion) ...[
              const SizedBox(height: BrandSpacing.sm),
              Text(l10n.projectsUseWeb, style: t.bodySmall),
            ],
          ],
        ),
      ),
    );
  }
}

class _RequiredNowTile extends StatelessWidget {
  const _RequiredNowTile({required this.item});
  final RequiredNowItem item;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    return Card(
      child: ListTile(
        leading: Icon(
          item.documentKind != null ? Icons.upload_file_outlined : Icons.edit_note_outlined,
          color: item.isOverdue ? BrandColors.danger : BrandColors.warning,
        ),
        title: Text(item.documentKind?.label(locale) ?? item.label),
        subtitle: Text(
          [
            item.opportunityName,
            if (item.note != null) item.note!,
            if (item.dueAt != null) l10n.dueOn(formatDate(item.dueAt!, locale)),
          ].join(' · '),
          style: t.bodySmall?.copyWith(color: item.isOverdue ? BrandColors.danger : null),
        ),
        trailing: const Icon(Icons.chevron_left),
        onTap: () => ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(l10n.projectsUseWeb))),
      ),
    );
  }
}
