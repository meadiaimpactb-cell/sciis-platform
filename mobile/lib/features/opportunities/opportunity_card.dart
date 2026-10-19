import 'package:flutter/material.dart';

import '../../core/models/opportunity.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';

/// Teaser card — public fields only (SPEC §5 "teaser/card" column).
class OpportunityCardTile extends StatelessWidget {
  const OpportunityCardTile({
    super.key,
    required this.card,
    required this.onTap,
    required this.onToggleSave,
  });

  final OpportunityCard card;
  final VoidCallback onTap;
  final VoidCallback onToggleSave;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    final locale = Localizations.localeOf(context);

    return Card(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(BrandRadii.lg),
        child: Padding(
          padding: const EdgeInsets.all(BrandSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(card.name, style: t.titleMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
                        if (card.organizationName != null)
                          Text(card.organizationName!, style: t.bodySmall),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: card.isSaved ? l10n.actionUnsave : l10n.actionSave,
                    onPressed: onToggleSave,
                    icon: Icon(
                      card.isSaved ? Icons.bookmark : Icons.bookmark_border,
                      color: card.isSaved ? BrandColors.blue : BrandColors.muted,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: BrandSpacing.sm),
              Text(
                card.teaserSummary,
                style: t.bodyMedium,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: BrandSpacing.md),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  Chip(label: Text(card.sector.label), avatar: const Icon(Icons.category_outlined, size: 14)),
                  Chip(label: Text(card.stage.label(locale))),
                  ...card.regions.take(2).map((r) => Chip(label: Text(r.label))),
                  ...card.investmentTypes.take(2).map((r) => Chip(label: Text(r.label))),
                ],
              ),
              const SizedBox(height: BrandSpacing.sm),
              Row(
                children: [
                  if (card.fundingDisplay != null) ...[
                    const Icon(Icons.payments_outlined, size: 16, color: BrandColors.muted),
                    const SizedBox(width: 4),
                    Text(card.fundingDisplay!, style: t.bodySmall),
                    const SizedBox(width: BrandSpacing.md),
                  ],
                  if (card.beneficiariesTarget != null) ...[
                    const Icon(Icons.groups_outlined, size: 16, color: BrandColors.muted),
                    const SizedBox(width: 4),
                    Text(
                      l10n.cardBeneficiariesTarget(formatInt(card.beneficiariesTarget!, locale)),
                      style: t.bodySmall,
                    ),
                  ],
                  const Spacer(),
                  Text(card.referenceNo, style: t.bodySmall?.copyWith(fontSize: 11)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
