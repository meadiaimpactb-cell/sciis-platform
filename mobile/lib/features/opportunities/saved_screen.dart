// GET /investor/saved (OPP-04).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/models/common.dart';
import '../../core/models/opportunity.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import 'opportunities_repository.dart';
import 'opportunity_card.dart';

final savedOpportunitiesProvider = FutureProvider.autoDispose<Paginated<OpportunityCard>>(
  (ref) => ref.watch(opportunitiesRepositoryProvider).saved(),
);

class SavedOpportunitiesScreen extends ConsumerWidget {
  const SavedOpportunitiesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final saved = ref.watch(savedOpportunitiesProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.navSaved)),
      body: saved.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(
          message: e is ApiException ? e.message : l10n.errorGeneric,
          retryLabel: l10n.actionRetry,
          onRetry: () => ref.invalidate(savedOpportunitiesProvider),
        ),
        data: (page) => page.items.isEmpty
            ? EmptyState(icon: Icons.bookmark_border, title: l10n.savedEmpty)
            : ListView.separated(
                padding: const EdgeInsets.all(BrandSpacing.md),
                itemCount: page.items.length,
                separatorBuilder: (_, __) => const SizedBox(height: BrandSpacing.sm),
                itemBuilder: (context, i) {
                  final card = page.items[i];
                  return OpportunityCardTile(
                    card: card.copyWith(isSaved: true),
                    onTap: () => context.push(AppRoutes.opportunity(card.uuid)),
                    onToggleSave: () async {
                      await ref.read(opportunitiesRepositoryProvider).unsave(card.uuid);
                      ref.invalidate(savedOpportunitiesProvider);
                    },
                  );
                },
              ),
      ),
    );
  }
}
