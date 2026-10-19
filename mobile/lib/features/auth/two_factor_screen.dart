// TOTP challenge (ACC-05 — mandatory for staff; optional for others).
// POST /auth/2fa/challenge {code}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import 'auth_repository.dart';
import 'otp_verify_screen.dart' show OtpCodeField;

class TwoFactorScreen extends ConsumerStatefulWidget {
  const TwoFactorScreen({super.key});

  @override
  ConsumerState<TwoFactorScreen> createState() => _TwoFactorScreenState();
}

class _TwoFactorScreenState extends ConsumerState<TwoFactorScreen> {
  bool _busy = false;
  String? _error;

  Future<void> _submit(String code) async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(authRepositoryProvider).challengeTwoFactor(code);
      await ref.read(sessionProvider.notifier).refreshMe();
      if (mounted) context.go(AppRoutes.home);
    } on ApiException catch (e) {
      setState(() => _error = e.isValidation || e.statusCode == 401
          ? l10n.twoFactorInvalid
          : e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.twoFactorTitle),
        actions: [
          TextButton(
            onPressed: () async {
              await ref.read(sessionProvider.notifier).signOut();
              if (context.mounted) context.go(AppRoutes.login);
            },
            child: Text(l10n.actionCancel),
          ),
        ],
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(BrandSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Icon(Icons.shield_outlined, size: 48, color: BrandColors.navy),
              const SizedBox(height: BrandSpacing.md),
              Text(l10n.twoFactorHint, style: t.bodyMedium, textAlign: TextAlign.center),
              const SizedBox(height: BrandSpacing.lg),
              OtpCodeField(enabled: !_busy, onCompleted: _submit),
              if (_error != null) ...[
                const SizedBox(height: BrandSpacing.sm),
                Text(
                  _error!,
                  style: t.bodySmall?.copyWith(color: BrandColors.danger),
                  textAlign: TextAlign.center,
                ),
              ],
              if (_busy) ...[
                const SizedBox(height: BrandSpacing.md),
                const LinearProgressIndicator(),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
