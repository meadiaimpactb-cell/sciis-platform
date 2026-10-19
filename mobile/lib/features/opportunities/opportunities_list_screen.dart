// GET /opportunities (approved members only; filtered by access policy) — OPP-01..04.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/enums.dart';
import '../../core/models/common.dart';
import '../../core/models/me.dart';
import '../../core/models/opportunity.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import '../auth/auth_repository.dart';
import 'opportunities_repository.dart';
import 'opportunity_card.dart';

class OpportunitiesListScreen extends ConsumerStatefulWidget {
  const OpportunitiesListScreen({super.key});

  @override
  ConsumerState<OpportunitiesListScreen> createState() => _OpportunitiesListScreenState();
}

class _OpportunitiesListScreenState extends ConsumerState<OpportunitiesListScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  final List<OpportunityCard> _extraPages = [];
  int _lastPage = 1;
  int _currentPage = 1;
  bool _loadingMore = false;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
  }

  @override
  void dispose() {
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _onScroll() async {
    if (_loadingMore || _currentPage >= _lastPage) return;
    if (_scroll.position.pixels < _scroll.position.maxScrollExtent - 300) return;
    setState(() => _loadingMore = true);
    try {
      final next = await ref
          .read(opportunitiesRepositoryProvider)
          .list(ref.read(opportunityFiltersProvider), page: _currentPage + 1);
      setState(() {
        _extraPages.addAll(next.items);
        _currentPage = next.currentPage;
        _lastPage = next.lastPage;
      });
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  void _resetPaging(Paginated<OpportunityCard> first) {
    _extraPages.clear();
    _currentPage = first.currentPage;
    _lastPage = first.lastPage;
  }

  Future<void> _toggleSave(OpportunityCard card) async {
    final repo = ref.read(opportunitiesRepositoryProvider);
    try {
      if (card.isSaved) {
        await repo.unsave(card.uuid);
      } else {
        await repo.save(card.uuid);
      }
      ref.invalidate(opportunitiesPageProvider);
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _openFilters() async {
    final result = await showModalBottomSheet<OpportunityFilters>(
      context: context,
      isScrollControlled: true,
      builder: (_) => FiltersBottomSheet(initial: ref.read(opportunityFiltersProvider)),
    );
    if (result != null) {
      ref.read(opportunityFiltersProvider.notifier).state = result;
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final session = ref.watch(sessionProvider);
    final filters = ref.watch(opportunityFiltersProvider);
    final page = ref.watch(opportunitiesPageProvider);

    // Progressive disclosure (doc §6.6): only approved members see the list.
    if (session.me != null && !session.me!.isApprovedMember) {
      return Scaffold(
        appBar: AppBar(title: Text(l10n.navOpportunities)),
        body: _MembershipGate(me: session.me!),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.navOpportunities),
        actions: [
          IconButton(
            tooltip: l10n.navSaved,
            onPressed: () => context.push(AppRoutes.saved),
            icon: const Icon(Icons.bookmarks_outlined),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(BrandSpacing.md, BrandSpacing.sm, BrandSpacing.md, 0),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _search,
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      hintText: l10n.searchHint,
                      prefixIcon: const Icon(Icons.search),
                      isDense: true,
                    ),
                    onSubmitted: (v) => ref.read(opportunityFiltersProvider.notifier).state =
                        filters.copyWith(search: v.trim()),
                  ),
                ),
                const SizedBox(width: BrandSpacing.sm),
                Badge(
                  isLabelVisible: filters.activeCount > 0,
                  label: Text('${filters.activeCount}'),
                  child: IconButton.filledTonal(
                    onPressed: _openFilters,
                    icon: const Icon(Icons.tune),
                    tooltip: l10n.filtersTitle,
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: BrandSpacing.md, vertical: BrandSpacing.sm),
            child: Row(
              children: [
                Text(l10n.sortLabel, style: Theme.of(context).textTheme.bodySmall),
                const SizedBox(width: BrandSpacing.sm),
                ...OpportunitySort.values.map(
                  (s) => Padding(
                    padding: const EdgeInsetsDirectional.only(end: 6),
                    child: ChoiceChip(
                      label: Text(s.label(locale)),
                      selected: filters.sort == s,
                      onSelected: (_) => ref.read(opportunityFiltersProvider.notifier).state =
                          filters.copyWith(sort: s),
                    ),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: page.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorState(
                message: e is ApiException ? e.message : l10n.errorGeneric,
                retryLabel: l10n.actionRetry,
                onRetry: () => ref.invalidate(opportunitiesPageProvider),
              ),
              data: (first) {
                if (_currentPage == 1 && _extraPages.isEmpty) _resetPaging(first);
                final items = [...first.items, ..._extraPages];
                if (items.isEmpty) {
                  return EmptyState(
                    icon: Icons.inbox_outlined,
                    title: l10n.opportunitiesEmptyTitle,
                    subtitle: l10n.opportunitiesEmptyHint,
                    action: filters.isEmpty
                        ? null
                        : TextButton(
                            onPressed: () => ref.read(opportunityFiltersProvider.notifier).state =
                                OpportunityFilters.empty,
                            child: Text(l10n.filtersClear),
                          ),
                  );
                }
                return RefreshIndicator(
                  onRefresh: () async {
                    setState(() {
                      _extraPages.clear();
                      _currentPage = 1;
                    });
                    ref.invalidate(opportunitiesPageProvider);
                  },
                  child: ListView.separated(
                    controller: _scroll,
                    padding: const EdgeInsets.fromLTRB(
                      BrandSpacing.md, 0, BrandSpacing.md, BrandSpacing.xl,
                    ),
                    itemCount: items.length + (_loadingMore ? 1 : 0),
                    separatorBuilder: (_, __) => const SizedBox(height: BrandSpacing.sm),
                    itemBuilder: (context, i) {
                      if (i >= items.length) {
                        return const Center(
                          child: Padding(
                            padding: EdgeInsets.all(16),
                            child: CircularProgressIndicator(),
                          ),
                        );
                      }
                      final card = items[i];
                      return OpportunityCardTile(
                        card: card,
                        onTap: () => context.push(AppRoutes.opportunity(card.uuid)),
                        onToggleSave: () => _toggleSave(card),
                      );
                    },
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _MembershipGate extends ConsumerWidget {
  const _MembershipGate({required this.me});

  final Me me;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final status = me.membership?.status ?? MembershipStatus.registered;
    return EmptyState(
      icon: Icons.verified_user_outlined,
      title: l10n.membershipGateTitle,
      subtitle: l10n.membershipGateHint(status.label(locale)),
      action: FilledButton(
        onPressed: () => context.go(AppRoutes.dashboard),
        child: Text(l10n.membershipGateAction),
      ),
    );
  }
}

/// Filters bottom sheet — sector / region / stage / investment type from taxonomies (FRM-04).
class FiltersBottomSheet extends ConsumerStatefulWidget {
  const FiltersBottomSheet({super.key, required this.initial});

  final OpportunityFilters initial;

  @override
  ConsumerState<FiltersBottomSheet> createState() => _FiltersBottomSheetState();
}

class _FiltersBottomSheetState extends ConsumerState<FiltersBottomSheet> {
  late OpportunityFilters _f = widget.initial;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    final taxonomies = ref.watch(taxonomiesProvider);

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.75,
      maxChildSize: 0.95,
      builder: (context, controller) => Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: BrandSpacing.lg),
            child: Row(
              children: [
                Expanded(child: Text(l10n.filtersTitle, style: t.titleLarge)),
                TextButton(
                  onPressed: () => setState(() => _f = OpportunityFilters(sort: _f.sort)),
                  child: Text(l10n.filtersClear),
                ),
              ],
            ),
          ),
          Expanded(
            child: taxonomies.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorState(
                message: l10n.errorGeneric,
                retryLabel: l10n.actionRetry,
                onRetry: () => ref.invalidate(taxonomiesProvider),
              ),
              data: (tx) => ListView(
                controller: controller,
                padding: const EdgeInsets.all(BrandSpacing.lg),
                children: [
                  _FilterGroup(
                    title: l10n.filterSector,
                    terms: tx['sectors'] ?? const [],
                    selected: _f.sector,
                    onChanged: (k) => setState(
                      () => _f = k == null ? _f.copyWith(clearSector: true) : _f.copyWith(sector: k),
                    ),
                  ),
                  _FilterGroup(
                    title: l10n.filterRegion,
                    terms: tx['regions'] ?? const [],
                    selected: _f.region,
                    onChanged: (k) => setState(
                      () => _f = k == null ? _f.copyWith(clearRegion: true) : _f.copyWith(region: k),
                    ),
                  ),
                  _FilterGroup(
                    title: l10n.filterStage,
                    terms: tx['project_stages'] ??
                        ProjectStage.values
                            .map((s) => TaxonomyTerm(key: s.value, label: s.label(locale)))
                            .toList(),
                    selected: _f.stage,
                    onChanged: (k) => setState(
                      () => _f = k == null ? _f.copyWith(clearStage: true) : _f.copyWith(stage: k),
                    ),
                  ),
                  _FilterGroup(
                    title: l10n.filterInvestmentType,
                    terms: tx['investment_types'] ??
                        InvestmentType.values
                            .map((s) => TaxonomyTerm(key: s.value, label: s.label(locale)))
                            .toList(),
                    selected: _f.type,
                    onChanged: (k) => setState(
                      () => _f = k == null ? _f.copyWith(clearType: true) : _f.copyWith(type: k),
                    ),
                  ),
                ],
              ),
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(BrandSpacing.lg),
              child: FilledButton(
                onPressed: () => Navigator.of(context).pop(_f),
                child: Text(l10n.filtersApply),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FilterGroup extends StatelessWidget {
  const _FilterGroup({
    required this.title,
    required this.terms,
    required this.selected,
    required this.onChanged,
  });

  final String title;
  final List<TaxonomyTerm> terms;
  final String? selected;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: BrandSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionTitle(title),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: terms
                .map(
                  (term) => FilterChip(
                    label: Text(term.label),
                    selected: selected == term.key,
                    onSelected: (sel) => onChanged(sel ? term.key : null),
                  ),
                )
                .toList(),
          ),
        ],
      ),
    );
  }
}
