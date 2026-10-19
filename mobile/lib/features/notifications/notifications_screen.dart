// In-app notification centre (Laravel database notifications) + preferences
// GET/PUT /me/notification-preferences (NTF-01/02).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/enums.dart';
import '../../core/models/common.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';

class AppNotification {
  const AppNotification({
    required this.id,
    required this.title,
    required this.body,
    required this.createdAt,
    this.readAt,
    this.route,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    final data = asMap(json['data']);
    return AppNotification(
      id: (json['id'] ?? '').toString(),
      title: (data['title'] ?? json['title'] ?? '').toString(),
      body: (data['body'] ?? json['body'] ?? '').toString(),
      route: (data['route'] ?? json['route']) as String?,
      createdAt: parseDate(json['created_at']) ?? DateTime.now(),
      readAt: parseDate(json['read_at']),
    );
  }

  final String id;
  final String title;
  final String body;
  final String? route;
  final DateTime createdAt;
  final DateTime? readAt;

  bool get isUnread => readAt == null;
}

class NotificationPreference {
  const NotificationPreference({
    required this.category,
    required this.channel,
    required this.enabled,
  });

  factory NotificationPreference.fromJson(Map<String, dynamic> json) =>
      NotificationPreference(
        category: (json['category'] ?? '').toString(),
        channel: NotificationChannel.fromValue(json['channel'] as String?),
        enabled: parseBool(json['enabled'], fallback: true),
      );

  final String category;
  final NotificationChannel channel;
  final bool enabled;

  Map<String, dynamic> toJson() =>
      {'category': category, 'channel': channel.value, 'enabled': enabled};
}

final notificationsProvider = FutureProvider.autoDispose<List<AppNotification>>((ref) async {
  final json = await ref.watch(apiClientProvider).getJson('/me/notifications');
  return listOfMaps(json['data']).map(AppNotification.fromJson).toList(growable: false);
});

final notificationPrefsProvider =
    FutureProvider.autoDispose<List<NotificationPreference>>((ref) async {
  final json = await ref.watch(apiClientProvider).getJson('/me/notification-preferences');
  return listOfMaps(json['data']).map(NotificationPreference.fromJson).toList(growable: false);
});

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(l10n.navNotifications),
          bottom: TabBar(
            tabs: [Tab(text: l10n.notificationsInbox), Tab(text: l10n.notificationsPrefs)],
          ),
        ),
        body: const TabBarView(children: [_InboxTab(), _PrefsTab()]),
      ),
    );
  }
}

class _InboxTab extends ConsumerWidget {
  const _InboxTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final t = Theme.of(context).textTheme;
    final items = ref.watch(notificationsProvider);
    return items.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorState(
        message: e is ApiException ? e.message : l10n.errorGeneric,
        retryLabel: l10n.actionRetry,
        onRetry: () => ref.invalidate(notificationsProvider),
      ),
      data: (list) => list.isEmpty
          ? EmptyState(icon: Icons.notifications_none, title: l10n.notificationsEmpty)
          : ListView.separated(
              padding: const EdgeInsets.all(BrandSpacing.md),
              itemCount: list.length,
              separatorBuilder: (_, __) => const SizedBox(height: BrandSpacing.sm),
              itemBuilder: (context, i) {
                final n = list[i];
                return Card(
                  child: ListTile(
                    leading: Icon(
                      n.isUnread ? Icons.circle : Icons.circle_outlined,
                      size: 10,
                      color: n.isUnread ? BrandColors.blue : BrandColors.border,
                    ),
                    title: Text(
                      n.title,
                      style: t.bodyMedium?.copyWith(
                        fontWeight: n.isUnread ? FontWeight.w700 : FontWeight.w400,
                      ),
                    ),
                    subtitle: Text('${n.body}\n${formatDateTime(n.createdAt, locale)}'),
                    isThreeLine: true,
                    onTap: () async {
                      try {
                        await ref
                            .read(apiClientProvider)
                            .postJson('/me/notifications/${n.id}/read');
                      } catch (_) {}
                      ref.invalidate(notificationsProvider);
                      if (n.route != null && context.mounted) context.push(n.route!);
                    },
                  ),
                );
              },
            ),
    );
  }
}

class _PrefsTab extends ConsumerWidget {
  const _PrefsTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context);
    final prefs = ref.watch(notificationPrefsProvider);
    return prefs.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorState(
        message: e is ApiException ? e.message : l10n.errorGeneric,
        retryLabel: l10n.actionRetry,
        onRetry: () => ref.invalidate(notificationPrefsProvider),
      ),
      data: (list) {
        final categories = list.map((p) => p.category).toSet().toList()..sort();
        return ListView(
          padding: const EdgeInsets.all(BrandSpacing.md),
          children: [
            for (final cat in categories) ...[
              SectionTitle(cat),
              Card(
                child: Column(
                  children: [
                    for (final p in list.where((p) => p.category == cat))
                      SwitchListTile(
                        title: Text(p.channel.label(locale)),
                        value: p.enabled,
                        onChanged: (v) async {
                          final updated = list
                              .map(
                                (x) => (x.category == p.category && x.channel == p.channel)
                                    ? NotificationPreference(
                                        category: x.category,
                                        channel: x.channel,
                                        enabled: v,
                                      )
                                    : x,
                              )
                              .map((x) => x.toJson())
                              .toList();
                          await ref.read(apiClientProvider).putJson(
                            '/me/notification-preferences',
                            body: {'preferences': updated},
                          );
                          ref.invalidate(notificationPrefsProvider);
                        },
                      ),
                  ],
                ),
              ),
              const SizedBox(height: BrandSpacing.md),
            ],
            Text(l10n.notificationsPushHint, style: Theme.of(context).textTheme.bodySmall),
          ],
        );
      },
    );
  }
}
