// GET /opportunities/{uuid} — detail at caller's access level with tabs
// overview / impact / indicators / risks / documents (locked|list)  — OPP-02, OPP-05, INV-03/04.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api_client.dart';
import '../../core/enums.dart';
import '../../core/models/opportunity.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';
import 'opportunities_repository.dart';

class OpportunityDetailScreen extends ConsumerWidget {
  const OpportunityDetailScreen({super.key, required this.uuid});

  final String uuid;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final detail = ref.watch(opportunityDetailProvider(uuid));

    return detail.when(
      loading: () => Scaffold(
        appBar: AppBar(),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(),
        body: ErrorState(
          message: e is ApiException
              ? (e.isForbidden ? l10n.opportunityForbidden : e.message)
              : l10n.errorGeneric,
          retryLabel: l10n.actionRetry,
          onRetry: () => ref.invalidate(opportunityDetailProvider(uuid)),
        ),
      ),
      data: (d) => _DetailBody(detail: d),
    );
  }
}

class _DetailBody extends ConsumerStatefulWidget {
  const _DetailBody({required this.detail});

  final OpportunityDetail detail;

  @override
  ConsumerState<_DetailBody> createState() => _DetailBodyState();
}

class _DetailBodyState extends ConsumerState<_DetailBody> {
  late bool _saved = widget.detail.card.isSaved;

  OpportunityDetail get d => widget.detail;

  Future<void> _toggleSave() async {
    final repo = ref.read(opportunitiesRepositoryProvider);
    setState(() => _saved = !_saved);
    try {
      if (_saved) {
        await repo.save(d.card.uuid);
      } else {
        await repo.unsave(d.card.uuid);
      }
    } on ApiException catch (e) {
      setState(() => _saved = !_saved);
      _toast(e.message);
    }
  }

  void _toast(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
  }

  Future<void> _expressInterest() async {
    final l10n = AppLocalizations.of(context);
    final note = await showModalBottomSheet<String?>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _NoteSheet(
        title: l10n.interestSheetTitle,
        description: l10n.interestSheetHint,
        hint: l10n.interestSheetNote,
        submitLabel: l10n.interestSheetSubmit,
      ),
    );
    if (note == null) return;
    try {
      await ref.read(opportunitiesRepositoryProvider).expressInterest(d.card.uuid, note: note);
      ref.invalidate(opportunityDetailProvider(d.card.uuid));
      _toast(l10n.interestSent);
    } on ApiException catch (e) {
      _toast(e.message);
    }
  }

  Future<void> _requestInfo() async {
    final l10n = AppLocalizations.of(context);
    final result = await showModalBottomSheet<({String subject, String body})?>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _InfoRequestSheet(),
    );
    if (result == null) return;
    try {
      await ref.read(opportunitiesRepositoryProvider).requestInfo(
            d.card.uuid,
            subject: result.subject,
            body: result.body,
          );
      _toast(l10n.infoRequestSent);
    } on ApiException catch (e) {
      _toast(e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;

    return DefaultTabController(
      length: 5,
      child: Scaffold(
        body: NestedScrollView(
          headerSliverBuilder: (context, inner) => [
            SliverAppBar(
              pinned: true,
              expandedHeight: 180,
              actions: [
                IconButton(
                  onPressed: _toggleSave,
                  tooltip: _saved ? l10n.actionUnsave : l10n.actionSave,
                  icon: Icon(_saved ? Icons.bookmark : Icons.bookmark_border),
                ),
              ],
              flexibleSpace: FlexibleSpaceBar(
                background: Container(
                  color: BrandColors.navy,
                  padding: const EdgeInsets.fromLTRB(
                    BrandSpacing.md, 72, BrandSpacing.md, BrandSpacing.md,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      Row(
                        children: [
                          StatusChip.opportunity(d.card.status, locale),
                          const SizedBox(width: 8),
                          Text(
                            d.card.referenceNo,
                            style: t.bodySmall?.copyWith(color: Colors.white70),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Text(
                        d.card.name,
                        style: t.titleLarge?.copyWith(color: Colors.white),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      if (d.card.organizationName != null)
                        Text(
                          d.card.organizationName!,
                          style: t.bodySmall?.copyWith(color: Colors.white70),
                        ),
                    ],
                  ),
                ),
              ),
              bottom: TabBar(
                isScrollable: true,
                tabAlignment: TabAlignment.start,
                tabs: [
                  Tab(text: l10n.tabOverview),
                  Tab(text: l10n.tabImpact),
                  Tab(text: l10n.tabIndicators),
                  Tab(text: l10n.tabRisks),
                  Tab(
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if (d.documentsLocked) const Icon(Icons.lock_outline, size: 14),
                        if (d.documentsLocked) const SizedBox(width: 4),
                        Text(l10n.tabDocuments),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
          body: TabBarView(
            children: [
              _OverviewTab(d: d),
              _ImpactTab(d: d),
              _IndicatorsTab(d: d),
              _RisksTab(d: d),
              _DocumentsTab(d: d),
            ],
          ),
        ),
        bottomNavigationBar: _ActionBar(
          detail: d,
          onInterest: _expressInterest,
          onInfoRequest: _requestInfo,
        ),
      ),
    );
  }
}

class _ActionBar extends StatelessWidget {
  const _ActionBar({
    required this.detail,
    required this.onInterest,
    required this.onInfoRequest,
  });

  final OpportunityDetail detail;
  final VoidCallback onInterest;
  final VoidCallback onInfoRequest;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final status = detail.interestStatus;

    return SafeArea(
      top: false,
      child: Container(
        padding: const EdgeInsets.all(BrandSpacing.md),
        decoration: const BoxDecoration(
          color: BrandColors.surface,
          border: Border(top: BorderSide(color: BrandColors.border)),
        ),
        child: Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: onInfoRequest,
                icon: const Icon(Icons.help_outline),
                label: Text(l10n.actionRequestInfo),
              ),
            ),
            const SizedBox(width: BrandSpacing.sm),
            Expanded(
              child: status == null
                  ? FilledButton.icon(
                      onPressed: onInterest,
                      icon: const Icon(Icons.handshake_outlined),
                      label: Text(l10n.actionExpressInterest),
                    )
                  : FilledButton.tonalIcon(
                      onPressed: null,
                      icon: const Icon(Icons.check),
                      label: Text(status.label(locale)),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------------

class _OverviewTab extends StatelessWidget {
  const _OverviewTab({required this.d});
  final OpportunityDetail d;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    return ListView(
      padding: const EdgeInsets.all(BrandSpacing.md),
      children: [
        Text(d.card.teaserSummary, style: t.bodyLarge),
        const SizedBox(height: BrandSpacing.md),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            Chip(label: Text(d.card.sector.label)),
            Chip(label: Text(d.card.stage.label(locale))),
            ...d.card.regions.map((r) => Chip(label: Text(r.label))),
            ...d.card.investmentTypes.map((r) => Chip(label: Text(r.label))),
          ],
        ),
        const SizedBox(height: BrandSpacing.lg),
        SectionTitle(l10n.sectionProblem),
        Text(d.problemStatement),
        const SizedBox(height: BrandSpacing.lg),
        SectionTitle(l10n.sectionSolution),
        Text(d.solution),
        const SizedBox(height: BrandSpacing.lg),
        SectionTitle(l10n.sectionFunding),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(BrandSpacing.md),
            child: Column(
              children: [
                KeyValueRow(
                  label: l10n.fieldFundingNeed,
                  value: d.fundingAmount != null
                      ? formatSar(d.fundingAmount!, locale)
                      : (d.fundingDisplay ?? l10n.valueHidden),
                ),
                if (d.revenueState != null)
                  KeyValueRow(label: l10n.fieldRevenueState, value: d.revenueState!.label(locale)),
                if (d.card.foundedYear != null)
                  KeyValueRow(label: l10n.fieldFoundedYear, value: '${d.card.foundedYear}'),
                if (d.website != null) KeyValueRow(label: l10n.fieldWebsite, value: d.website!),
              ],
            ),
          ),
        ),
        const SizedBox(height: BrandSpacing.lg),
        SectionTitle(l10n.sectionUseOfFunds),
        if (d.useOfFunds.isNotEmpty)
          ...d.useOfFunds.map(
            (u) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  Expanded(child: Text(u.item)),
                  Text('${u.percent.toStringAsFixed(0)}٪', style: t.labelLarge),
                ],
              ),
            ),
          )
        else if (d.useOfFundsSummary != null)
          Text(d.useOfFundsSummary!)
        else
          Text(l10n.valueAfterGrant, style: t.bodySmall),
        if (d.ownerContact != null) ...[
          const SizedBox(height: BrandSpacing.lg),
          SectionTitle(l10n.sectionOwnerContact),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(BrandSpacing.md),
              child: Column(
                children: [
                  KeyValueRow(label: l10n.fieldName, value: d.ownerContact!.name),
                  if (d.ownerContact!.email != null)
                    KeyValueRow(label: l10n.fieldEmail, value: d.ownerContact!.email!),
                  if (d.ownerContact!.phone != null)
                    KeyValueRow(label: l10n.fieldPhone, value: d.ownerContact!.phone!),
                ],
              ),
            ),
          ),
        ],
        const SizedBox(height: BrandSpacing.xl),
      ],
    );
  }
}

class _ImpactTab extends StatelessWidget {
  const _ImpactTab({required this.d});
  final OpportunityDetail d;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    final primary = d.primaryIndicator;
    return ListView(
      padding: const EdgeInsets.all(BrandSpacing.md),
      children: [
        SectionTitle(l10n.sectionExpectedChange),
        Text(d.expectedChange),
        const SizedBox(height: BrandSpacing.lg),
        SectionTitle(l10n.sectionBeneficiaries),
        Text(d.beneficiariesDesc),
        const SizedBox(height: BrandSpacing.sm),
        Row(
          children: [
            Expanded(
              child: StatTile(
                label: l10n.fieldBeneficiariesCurrent,
                value: d.beneficiariesCurrent ?? 0,
                icon: Icons.person_outline,
              ),
            ),
            const SizedBox(width: BrandSpacing.sm),
            Expanded(
              child: StatTile(
                label: l10n.fieldBeneficiariesTarget,
                value: d.card.beneficiariesTarget ?? 0,
                icon: Icons.flag_outlined,
              ),
            ),
          ],
        ),
        if (d.card.beneficiaryGroups.isNotEmpty) ...[
          const SizedBox(height: BrandSpacing.sm),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: d.card.beneficiaryGroups.map((g) => Chip(label: Text(g.label))).toList(),
          ),
        ],
        if (d.sdgs.isNotEmpty) ...[
          const SizedBox(height: BrandSpacing.lg),
          SectionTitle(l10n.sectionSdgs),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: d.sdgs.map((g) => Chip(label: Text(g.label))).toList(),
          ),
        ],
        if (primary != null) ...[
          const SizedBox(height: BrandSpacing.lg),
          SectionTitle(l10n.sectionPrimaryIndicator),
          _IndicatorCard(indicator: primary, locale: locale),
        ],
        const SizedBox(height: BrandSpacing.lg),
        Text(l10n.impactDisclaimer, style: t.bodySmall),
      ],
    );
  }
}

class _IndicatorsTab extends StatelessWidget {
  const _IndicatorsTab({required this.d});
  final OpportunityDetail d;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    if (d.indicators.isEmpty) {
      return EmptyState(icon: Icons.insights_outlined, title: l10n.indicatorsEmpty);
    }
    return ListView.separated(
      padding: const EdgeInsets.all(BrandSpacing.md),
      itemCount: d.indicators.length,
      separatorBuilder: (_, __) => const SizedBox(height: BrandSpacing.sm),
      itemBuilder: (_, i) => _IndicatorCard(indicator: d.indicators[i], locale: locale),
    );
  }
}

class _IndicatorCard extends StatelessWidget {
  const _IndicatorCard({required this.indicator, required this.locale});

  final ImpactIndicator indicator;
  final Locale locale;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    final progress = indicator.progress;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(BrandSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(indicator.name, style: t.titleMedium)),
                if (indicator.isPrimary)
                  StatusChip(label: l10n.badgePrimary, color: BrandColors.blue, compact: true),
                if (indicator.isDisclosed) ...[
                  const SizedBox(width: 4),
                  StatusChip(label: l10n.badgeDisclosed, color: BrandColors.success, compact: true),
                ],
              ],
            ),
            if (indicator.definition != null) ...[
              const SizedBox(height: 4),
              Text(indicator.definition!, style: t.bodySmall),
            ],
            const SizedBox(height: BrandSpacing.sm),
            Row(
              children: [
                _Metric(label: l10n.fieldBaseline, value: indicator.baseline, unit: indicator.unit),
                _Metric(label: l10n.fieldLatest, value: indicator.latestValue, unit: indicator.unit),
                _Metric(label: l10n.fieldTarget, value: indicator.target, unit: indicator.unit),
              ],
            ),
            if (progress != null) ...[
              const SizedBox(height: BrandSpacing.sm),
              ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: LinearProgressIndicator(
                  value: progress,
                  minHeight: 6,
                  backgroundColor: BrandColors.border,
                  color: BrandColors.success,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value, required this.unit});
  final String label;
  final double? value;
  final String unit;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: t.bodySmall),
          Text(
            value == null ? '—' : '${value!.toStringAsFixed(value! % 1 == 0 ? 0 : 1)} $unit',
            style: t.labelLarge,
          ),
        ],
      ),
    );
  }
}

class _RisksTab extends StatelessWidget {
  const _RisksTab({required this.d});
  final OpportunityDetail d;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    final body = d.risksFull ?? d.risksSummary;
    return ListView(
      padding: const EdgeInsets.all(BrandSpacing.md),
      children: [
        SectionTitle(
          l10n.sectionRisks,
          trailing: StatusChip(
            label: d.risksFull != null ? l10n.badgeFull : l10n.badgeSummary,
            color: d.risksFull != null ? BrandColors.success : BrandColors.warning,
            compact: true,
          ),
        ),
        if (body != null) Text(body) else Text(l10n.valueAfterGrant, style: t.bodySmall),
        if (d.licenseStatus != null) ...[
          const SizedBox(height: BrandSpacing.lg),
          KeyValueRow(label: l10n.fieldLicenseStatus, value: d.licenseStatus!),
        ],
        if (!d.isGranted) ...[
          const SizedBox(height: BrandSpacing.lg),
          LockedSection(
            title: l10n.risksLockedTitle,
            description: l10n.risksLockedHint,
          ),
        ],
      ],
    );
  }
}

class _DocumentsTab extends ConsumerWidget {
  const _DocumentsTab({required this.d});
  final OpportunityDetail d;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    if (d.documentsLocked) {
      final needsNda = d.isGranted && !d.ndaAccepted;
      return Padding(
        padding: const EdgeInsets.all(BrandSpacing.md),
        child: LockedSection(
          title: needsNda ? l10n.documentsNdaTitle : l10n.documentsLockedTitle,
          description: needsNda ? l10n.documentsNdaHint : l10n.documentsLockedHint,
          action: needsNda
              ? FilledButton(
                  onPressed: () async {
                    try {
                      await ref
                          .read(opportunitiesRepositoryProvider)
                          .acceptNda(d.card.uuid, version: 'current');
                      ref.invalidate(opportunityDetailProvider(d.card.uuid));
                    } on ApiException catch (e) {
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
                      }
                    }
                  },
                  child: Text(l10n.documentsNdaAccept),
                )
              : null,
        ),
      );
    }
    if (d.documents.isEmpty) {
      return EmptyState(icon: Icons.folder_open_outlined, title: l10n.documentsEmpty);
    }
    return ListView.separated(
      padding: const EdgeInsets.all(BrandSpacing.md),
      itemCount: d.documents.length,
      separatorBuilder: (_, __) => const SizedBox(height: BrandSpacing.sm),
      itemBuilder: (context, i) {
        final doc = d.documents[i];
        return Card(
          child: ListTile(
            leading: const Icon(Icons.description_outlined, color: BrandColors.navy),
            title: Text(doc.title),
            subtitle: Text(
              '${doc.kind.label(locale)} · v${doc.version}'
              '${doc.sizeBytes != null ? ' · ${(doc.sizeBytes! / 1024 / 1024).toStringAsFixed(1)} MB' : ''}',
            ),
            trailing: IconButton(
              icon: const Icon(Icons.download_outlined),
              tooltip: l10n.actionDownload,
              onPressed: () async {
                // Signed temporary URL; the API writes document_access_logs (DOC-02, INV-04).
                try {
                  final r = await ref.read(opportunitiesRepositoryProvider).downloadUrl(doc.uuid);
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(l10n.documentsUrlReady(r.url.host))),
                    );
                  }
                  // TODO(app): open with url_launcher / in-app PDF viewer; never cache to disk.
                } on ApiException catch (e) {
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
                  }
                }
              },
            ),
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Sheets
// ---------------------------------------------------------------------------

class _NoteSheet extends StatefulWidget {
  const _NoteSheet({
    required this.title,
    required this.description,
    required this.hint,
    required this.submitLabel,
  });

  final String title;
  final String description;
  final String hint;
  final String submitLabel;

  @override
  State<_NoteSheet> createState() => _NoteSheetState();
}

class _NoteSheetState extends State<_NoteSheet> {
  final _ctrl = TextEditingController();

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Padding(
      padding: EdgeInsets.fromLTRB(
        BrandSpacing.lg, 0, BrandSpacing.lg,
        MediaQuery.of(context).viewInsets.bottom + BrandSpacing.lg,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(widget.title, style: t.titleLarge),
          const SizedBox(height: BrandSpacing.xs),
          Text(widget.description, style: t.bodySmall),
          const SizedBox(height: BrandSpacing.md),
          TextField(
            controller: _ctrl,
            maxLines: 4,
            maxLength: 500,
            decoration: InputDecoration(hintText: widget.hint),
          ),
          const SizedBox(height: BrandSpacing.sm),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(_ctrl.text.trim()),
            child: Text(widget.submitLabel),
          ),
        ],
      ),
    );
  }
}

class _InfoRequestSheet extends StatefulWidget {
  const _InfoRequestSheet();

  @override
  State<_InfoRequestSheet> createState() => _InfoRequestSheetState();
}

class _InfoRequestSheetState extends State<_InfoRequestSheet> {
  final _subject = TextEditingController();
  final _body = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  @override
  void dispose() {
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    return Padding(
      padding: EdgeInsets.fromLTRB(
        BrandSpacing.lg, 0, BrandSpacing.lg,
        MediaQuery.of(context).viewInsets.bottom + BrandSpacing.lg,
      ),
      child: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(l10n.infoRequestTitle, style: t.titleLarge),
            const SizedBox(height: BrandSpacing.xs),
            // OPP-05 / MSG-01: identity is not forwarded to the owner.
            Text(l10n.infoRequestAnonymousHint, style: t.bodySmall),
            const SizedBox(height: BrandSpacing.md),
            TextFormField(
              controller: _subject,
              decoration: InputDecoration(labelText: l10n.fieldSubject),
              validator: (v) => (v == null || v.trim().isEmpty) ? l10n.validationRequired : null,
            ),
            const SizedBox(height: BrandSpacing.sm),
            TextFormField(
              controller: _body,
              maxLines: 5,
              maxLength: 1000,
              decoration: InputDecoration(labelText: l10n.fieldMessage),
              validator: (v) => (v == null || v.trim().length < 10) ? l10n.validationRequired : null,
            ),
            const SizedBox(height: BrandSpacing.sm),
            FilledButton(
              onPressed: () {
                if (_formKey.currentState?.validate() ?? false) {
                  Navigator.of(context).pop((subject: _subject.text.trim(), body: _body.text.trim()));
                }
              },
              child: Text(l10n.infoRequestSubmit),
            ),
          ],
        ),
      ),
    );
  }
}
