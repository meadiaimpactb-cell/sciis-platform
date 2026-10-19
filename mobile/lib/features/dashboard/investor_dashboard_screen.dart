// GET /investor/dashboard → counters, suggested[], updates[], next_meeting (INV-01, OPP-06).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/enums.dart';
import '../../core/models/common.dart';
import '../../core/models/interest.dart';
import '../../core/models/me.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import '../auth/auth_repository.dart';
import '../opportunities/opportunities_repository.dart';

final investorDashboardProvider = FutureProvider.autoDispose<InvestorDashboard>(
  (ref) async {
    final json = await ref.watch(apiClientProvider).getJson('/investor/dashboard');
    return InvestorDashboard.fromJson(asMap(json['data']).isEmpty ? json : asMap(json['data']));
  },
);

final investorInterestsProvider = FutureProvider.autoDispose<Paginated<Interest>>(
  (ref) => ref.watch(opportunitiesRepositoryProvider).interests(),
);

final investorNominationsProvider = FutureProvider.autoDispose<Paginated<Nomination>>(
  (ref) => ref.watch(opportunitiesRepositoryProvider).nominations(),
);

class InvestorDashboardScreen extends ConsumerWidget {
  const InvestorDashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    final session = ref.watch(sessionProvider);
    final me = session.me;
    final dashboard = ref.watch(investorDashboardProvider);
    final interests = ref.watch(investorInterestsProvider);
    final nominations = ref.watch(investorNominationsProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.navDashboard),
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
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(investorDashboardProvider);
          ref.invalidate(investorInterestsProvider);
          ref.invalidate(investorNominationsProvider);
          await ref.read(sessionProvider.notifier).refreshMe();
        },
        child: ListView(
          padding: const EdgeInsets.all(BrandSpacing.md),
          children: [
            if (me != null) ...[
              Text(l10n.greeting(me.name.split(' ').first), style: t.headlineSmall),
              const SizedBox(height: BrandSpacing.xs),
              _MembershipBanner(me: me),
              const SizedBox(height: BrandSpacing.md),
            ],
            dashboard.when(
              loading: () => const LinearProgressIndicator(),
              error: (e, _) => ErrorState(
                message: e is ApiException ? e.message : l10n.errorGeneric,
                retryLabel: l10n.actionRetry,
                onRetry: () => ref.invalidate(investorDashboardProvider),
              ),
              data: (d) => Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(BrandSpacing.md),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          CompletionBar(
                            percent: d.completionPercent,
                            label: l10n.profileCompletion,
                          ),
                          if (d.completionPercent < 100) ...[
                            const SizedBox(height: BrandSpacing.sm),
                            Align(
                              alignment: AlignmentDirectional.centerEnd,
                              child: TextButton(
                                onPressed: () => context.push(AppRoutes.profile),
                                child: Text(l10n.profileComplete),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: BrandSpacing.md),
                  GridView.count(
                    crossAxisCount: 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    mainAxisSpacing: BrandSpacing.sm,
                    crossAxisSpacing: BrandSpacing.sm,
                    childAspectRatio: 1.6,
                    children: [
                      StatTile(
                        label: l10n.counterSaved,
                        value: d.counters.saved,
                        icon: Icons.bookmark_outline,
                        onTap: () => context.push(AppRoutes.saved),
                      ),
                      StatTile(
                        label: l10n.counterInterests,
                        value: d.counters.interests,
                        icon: Icons.handshake_outlined,
                      ),
                      StatTile(
                        label: l10n.counterInfoRequests,
                        value: d.counters.infoRequests,
                        icon: Icons.help_outline,
                      ),
                      StatTile(
                        label: l10n.counterMeetings,
                        value: d.counters.meetings,
                        icon: Icons.event_outlined,
                      ),
                    ],
                  ),
                  if (d.nextMeeting != null) ...[
                    const SizedBox(height: BrandSpacing.md),
                    _NextMeetingCard(meeting: Meeting.fromJson(d.nextMeeting!)),
                  ],
                  if (d.updates.isNotEmpty) ...[
                    const SizedBox(height: BrandSpacing.lg),
                    SectionTitle(l10n.sectionUpdates),
                    ...d.updates.take(5).map(
                          (u) => ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.circle, size: 8, color: BrandColors.blue),
                            title: Text(u.title, style: t.bodyMedium),
                            subtitle: Text(formatDateTime(u.at, locale), style: t.bodySmall),
                            onTap: u.opportunityUuid == null
                                ? null
                                : () => context.push(AppRoutes.opportunity(u.opportunityUuid!)),
                          ),
                        ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: BrandSpacing.lg),
            nominations.when(
              loading: () => const SizedBox.shrink(),
              error: (_, __) => const SizedBox.shrink(),
              data: (page) {
                final pending = page.items.where((n) => n.isPending).toList();
                if (pending.isEmpty) return const SizedBox.shrink();
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SectionTitle(l10n.sectionNominations),
                    ...pending.map((n) => _NominationCard(nomination: n)),
                    const SizedBox(height: BrandSpacing.lg),
                  ],
                );
              },
            ),
            SectionTitle(l10n.sectionInterests),
            interests.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => Text(l10n.errorGeneric, style: t.bodySmall),
              data: (page) => page.items.isEmpty
                  ? EmptyState(
                      icon: Icons.handshake_outlined,
                      title: l10n.interestsEmpty,
                      action: TextButton(
                        onPressed: () => context.go(AppRoutes.opportunities),
                        child: Text(l10n.navOpportunities),
                      ),
                    )
                  : _InterestsTable(interests: page.items),
            ),
            const SizedBox(height: BrandSpacing.xl),
          ],
        ),
      ),
    );
  }
}

class _MembershipBanner extends StatelessWidget {
  const _MembershipBanner({required this.me});
  final Me me;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final status = me.membership?.status ?? MembershipStatus.registered;
    final color = switch (status) {
      MembershipStatus.approved => BrandColors.success,
      MembershipStatus.rejected || MembershipStatus.suspended => BrandColors.danger,
      MembershipStatus.needsCompletion => BrandColors.warning,
      _ => BrandColors.blue,
    };
    return Row(
      children: [
        StatusChip(label: '${l10n.membershipLabel}: ${status.label(locale)}', color: color),
        const SizedBox(width: 8),
        StatusChip(label: me.accessLevel.label(locale), color: BrandColors.navy),
      ],
    );
  }
}

class _NextMeetingCard extends StatelessWidget {
  const _NextMeetingCard({required this.meeting});
  final Meeting meeting;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    return Card(
      child: ListTile(
        leading: Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: BrandColors.blue.withAlpha(31),
            borderRadius: BorderRadius.circular(BrandRadii.md),
          ),
          child: const Icon(Icons.event, color: BrandColors.blue),
        ),
        title: Text(l10n.nextMeeting, style: t.bodySmall),
        subtitle: Text(
          '${meeting.title}\n${formatDateTime(meeting.startsAt, locale)}',
          style: t.bodyMedium,
        ),
        isThreeLine: true,
        trailing: meeting.videoUrl != null
            ? const Icon(Icons.videocam_outlined)
            : const Icon(Icons.place_outlined),
      ),
    );
  }
}

class _NominationCard extends ConsumerWidget {
  const _NominationCard({required this.nomination});
  final Nomination nomination;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(BrandSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(nomination.opportunityName, style: t.titleMedium)),
                StatusChip(
                  label: l10n.matchScore(nomination.matchScore),
                  color: BrandColors.blue,
                  compact: true,
                ),
              ],
            ),
            if (nomination.teaserSummary != null) ...[
              const SizedBox(height: 4),
              Text(nomination.teaserSummary!, style: t.bodySmall, maxLines: 2, overflow: TextOverflow.ellipsis),
            ],
            const SizedBox(height: BrandSpacing.sm),
            Text('${l10n.nominationRationale}: ${nomination.rationale}', style: t.bodySmall),
            if (nomination.matchedCriteria.isNotEmpty) ...[
              const SizedBox(height: 6),
              Wrap(
                spacing: 4,
                children: nomination.matchedCriteria
                    .map((c) => Chip(label: Text(c), visualDensity: VisualDensity.compact))
                    .toList(),
              ),
            ],
            const SizedBox(height: BrandSpacing.sm),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () async {
                      await ref
                          .read(opportunitiesRepositoryProvider)
                          .respondNomination(nomination.id, interested: false);
                      ref.invalidate(investorNominationsProvider);
                    },
                    child: Text(l10n.nominationDecline),
                  ),
                ),
                const SizedBox(width: BrandSpacing.sm),
                Expanded(
                  child: FilledButton(
                    onPressed: () async {
                      await ref
                          .read(opportunitiesRepositoryProvider)
                          .respondNomination(nomination.id, interested: true);
                      ref.invalidate(investorNominationsProvider);
                      if (context.mounted) {
                        context.push(AppRoutes.opportunity(nomination.opportunityUuid));
                      }
                    },
                    child: Text(l10n.nominationInterested),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _InterestsTable extends StatelessWidget {
  const _InterestsTable({required this.interests});
  final List<Interest> interests;

  Color _color(InterestStatus s) => switch (s) {
        InterestStatus.sent || InterestStatus.awaitingOwnerApproval => BrandColors.warning,
        InterestStatus.shared => BrandColors.blue,
        InterestStatus.connected || InterestStatus.meeting => BrandColors.success,
        InterestStatus.closed => BrandColors.muted,
      };

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    return Card(
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(BrandSpacing.md, BrandSpacing.sm, BrandSpacing.md, BrandSpacing.sm),
            child: Row(
              children: [
                Expanded(flex: 3, child: Text(l10n.colOpportunity, style: t.bodySmall)),
                Expanded(flex: 2, child: Text(l10n.colStatus, style: t.bodySmall)),
                Expanded(flex: 2, child: Text(l10n.colDate, style: t.bodySmall)),
              ],
            ),
          ),
          const Divider(height: 1),
          ...interests.map(
            (i) => InkWell(
              onTap: () => context.push(AppRoutes.opportunity(i.opportunityUuid)),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: BrandSpacing.md, vertical: 10),
                child: Row(
                  children: [
                    Expanded(
                      flex: 3,
                      child: Text(i.opportunityName, maxLines: 1, overflow: TextOverflow.ellipsis),
                    ),
                    Expanded(
                      flex: 2,
                      child: Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: StatusChip(
                          label: i.status.label(locale),
                          color: _color(i.status),
                          compact: true,
                        ),
                      ),
                    ),
                    Expanded(
                      flex: 2,
                      child: Text(formatDate(i.createdAt, locale), style: t.bodySmall),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
