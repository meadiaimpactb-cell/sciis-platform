// Small semantic components shared across features (UX-04).

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../enums.dart';
import '../theme.dart';

/// Coloured status pill using SPEC §10 status colours.
class StatusChip extends StatelessWidget {
  const StatusChip({
    super.key,
    required this.label,
    required this.color,
    this.compact = false,
  });

  StatusChip.opportunity(OpportunityStatus status, Locale locale, {super.key})
      : label = status.label(locale),
        color = status.color,
        compact = false;

  final String label;
  final Color color;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 8 : 10,
        vertical: compact ? 2 : 4,
      ),
      decoration: BoxDecoration(
        color: color.withAlpha(31),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 6,
            height: 6,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Text(
            label,
            style: TextStyle(
              fontSize: compact ? 11 : 12,
              fontWeight: FontWeight.w600,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.trailing});

  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: BrandSpacing.sm),
      child: Row(
        children: [
          Expanded(
            child: Text(text, style: Theme.of(context).textTheme.titleMedium),
          ),
          if (trailing != null) trailing!,
        ],
      ),
    );
  }
}

class KeyValueRow extends StatelessWidget {
  const KeyValueRow({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(label, style: t.bodySmall),
          ),
          Expanded(child: Text(value, style: t.bodyMedium)),
        ],
      ),
    );
  }
}

/// Counter tile for dashboards.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    required this.icon,
    this.onTap,
  });

  final String label;
  final int value;
  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Card(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(BrandRadii.lg),
        child: Padding(
          padding: const EdgeInsets.all(BrandSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: BrandColors.blue, size: 20),
              const SizedBox(height: BrandSpacing.sm),
              Text(
                formatInt(value, Localizations.localeOf(context)),
                style: t.headlineSmall,
              ),
              Text(label, style: t.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}

/// Profile / file completion bar with percent label.
class CompletionBar extends StatelessWidget {
  const CompletionBar({super.key, required this.percent, required this.label});

  final int percent;
  final String label;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final color = percent >= 100
        ? BrandColors.success
        : percent >= 60
            ? BrandColors.blue
            : BrandColors.warning;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(child: Text(label, style: t.bodyMedium)),
            Text(
              '${formatInt(percent, Localizations.localeOf(context))}٪',
              style: t.labelLarge?.copyWith(color: color),
            ),
          ],
        ),
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: LinearProgressIndicator(
            value: percent / 100,
            minHeight: 8,
            backgroundColor: BrandColors.border,
            color: color,
          ),
        ),
      ],
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.action,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(BrandSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: BrandColors.muted),
            const SizedBox(height: BrandSpacing.md),
            Text(title, style: t.titleMedium, textAlign: TextAlign.center),
            if (subtitle != null) ...[
              const SizedBox(height: BrandSpacing.xs),
              Text(subtitle!, style: t.bodySmall, textAlign: TextAlign.center),
            ],
            if (action != null) ...[
              const SizedBox(height: BrandSpacing.md),
              action!,
            ],
          ],
        ),
      ),
    );
  }
}

class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.message, this.onRetry, this.retryLabel});

  final String message;
  final VoidCallback? onRetry;
  final String? retryLabel;

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      icon: Icons.error_outline,
      title: message,
      action: onRetry == null
          ? null
          : OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: Text(retryLabel ?? 'إعادة المحاولة'),
            ),
    );
  }
}

/// Lock card for confidential sections before grant (SPEC §5).
class LockedSection extends StatelessWidget {
  const LockedSection({
    super.key,
    required this.title,
    required this.description,
    this.action,
  });

  final String title;
  final String description;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Container(
      padding: const EdgeInsets.all(BrandSpacing.lg),
      decoration: BoxDecoration(
        color: BrandColors.navy.withAlpha(10),
        borderRadius: BorderRadius.circular(BrandRadii.lg),
        border: Border.all(color: BrandColors.border),
      ),
      child: Column(
        children: [
          const Icon(Icons.lock_outline, color: BrandColors.navy, size: 32),
          const SizedBox(height: BrandSpacing.sm),
          Text(title, style: t.titleMedium, textAlign: TextAlign.center),
          const SizedBox(height: BrandSpacing.xs),
          Text(description, style: t.bodySmall, textAlign: TextAlign.center),
          if (action != null) ...[
            const SizedBox(height: BrandSpacing.md),
            action!,
          ],
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Arabic-safe formatting (UX-02). Western digits are used in both locales so
// reference numbers (A-2026-0001) and amounts stay copy-paste safe.
// ---------------------------------------------------------------------------

String formatInt(int value, Locale locale) =>
    NumberFormat.decimalPattern('en').format(value);

String formatSar(num value, Locale locale) {
  final n = NumberFormat.decimalPattern('en').format(value);
  return locale.languageCode == 'ar' ? '$n ر.س' : 'SAR $n';
}

String formatDate(DateTime d, Locale locale) =>
    DateFormat.yMMMd(locale.languageCode == 'ar' ? 'ar' : 'en').format(d.toLocal());

String formatDateTime(DateTime d, Locale locale) =>
    DateFormat.yMMMd(locale.languageCode == 'ar' ? 'ar' : 'en')
        .add_jm()
        .format(d.toLocal());
