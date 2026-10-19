// Account & settings: locale switch, 2FA status, notification preferences link,
// PDPL data requests (PRV-03), sign out.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/widgets/common_widgets.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import '../auth/auth_repository.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  Future<void> _dataRequest(BuildContext context, WidgetRef ref, String type) async {
    final l10n = AppLocalizations.of(context);
    try {
      await ref.read(apiClientProvider).postJson('/me/data-requests', body: {'type': type});
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.dataRequestSent)));
      }
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
    final me = ref.watch(sessionProvider).me;
    final t = Theme.of(context).textTheme;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.navProfile)),
      body: ListView(
        padding: const EdgeInsets.all(BrandSpacing.md),
        children: [
          if (me != null)
            Card(
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: BrandColors.navy,
                  child: Text(
                    me.name.isEmpty ? '?' : me.name.characters.first,
                    style: const TextStyle(color: Colors.white),
                  ),
                ),
                title: Text(me.name),
                subtitle: Text('${me.type.label(locale)}\n${me.email}'),
                isThreeLine: true,
              ),
            ),
          const SizedBox(height: BrandSpacing.lg),
          SectionTitle(l10n.settingsLanguage),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'ar', label: Text('العربية')),
              ButtonSegment(value: 'en', label: Text('English')),
            ],
            selected: {locale.languageCode},
            onSelectionChanged: (s) async {
              final code = s.first;
              ref.read(localeProvider.notifier).state = Locale(code);
              await ref.read(authStorageProvider).writeLocale(code);
              try {
                await ref.read(apiClientProvider).patchJson('/me', body: {'locale': code});
              } catch (_) {}
            },
          ),
          const SizedBox(height: BrandSpacing.lg),
          SectionTitle(l10n.settingsSecurity),
          Card(
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.shield_outlined),
                  title: Text(l10n.twoFactorTitle),
                  subtitle: Text(
                    (me?.twoFactorEnabled ?? false) ? l10n.twoFactorEnabled : l10n.twoFactorDisabled,
                  ),
                  trailing: const Icon(Icons.chevron_left),
                  // TODO(app): POST /auth/2fa/enable → show QR/secret → POST /auth/2fa/confirm
                  onTap: () {},
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.notifications_outlined),
                  title: Text(l10n.settingsNotifications),
                  trailing: const Icon(Icons.chevron_left),
                  onTap: () => context.push(AppRoutes.notifications),
                ),
              ],
            ),
          ),
          const SizedBox(height: BrandSpacing.lg),
          SectionTitle(l10n.settingsPrivacy),
          Text(l10n.privacyHint, style: t.bodySmall),
          const SizedBox(height: BrandSpacing.sm),
          Card(
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.download_outlined),
                  title: Text(l10n.dataRequestAccess),
                  onTap: () => _dataRequest(context, ref, 'access'),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.edit_outlined),
                  title: Text(l10n.dataRequestRectify),
                  onTap: () => _dataRequest(context, ref, 'rectify'),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.delete_outline, color: BrandColors.danger),
                  title: Text(l10n.dataRequestErase),
                  onTap: () => _dataRequest(context, ref, 'erase'),
                ),
              ],
            ),
          ),
          const SizedBox(height: BrandSpacing.xl),
          OutlinedButton.icon(
            onPressed: () async {
              await ref.read(sessionProvider.notifier).signOut();
              if (context.mounted) context.go(AppRoutes.login);
            },
            icon: const Icon(Icons.logout),
            label: Text(l10n.actionSignOut),
          ),
          const SizedBox(height: BrandSpacing.md),
          Text(
            l10n.appVersion('0.1.0'),
            style: t.bodySmall?.copyWith(fontSize: 11),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}
