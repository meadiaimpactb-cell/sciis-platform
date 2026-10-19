import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import 'auth_repository.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final ok = await ref.read(authRepositoryProvider).login(
            email: _email.text.trim(),
            password: _password.text,
            deviceName: 'mobile',
          );
      if (!mounted) return;
      if (ok) {
        await ref.read(sessionProvider.notifier).refreshMe();
        if (mounted) context.go(AppRoutes.home);
      } else {
        context.go(AppRoutes.twoFactor);
      }
    } on ApiException catch (e) {
      setState(() {
        _error = e.isRateLimited
            ? l10n.errorRateLimited
            : e.isNetwork
                ? l10n.errorNetwork
                : (e.statusCode == 401 || e.isValidation)
                    ? l10n.errorInvalidCredentials
                    : e.message;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(BrandSpacing.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const _BrandMark(),
                    const SizedBox(height: BrandSpacing.xl),
                    Text(l10n.loginTitle, style: t.headlineSmall),
                    const SizedBox(height: BrandSpacing.xs),
                    Text(l10n.loginSubtitle, style: t.bodySmall),
                    const SizedBox(height: BrandSpacing.lg),
                    TextFormField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.email],
                      textDirection: TextDirection.ltr,
                      decoration: InputDecoration(
                        labelText: l10n.fieldEmail,
                        prefixIcon: const Icon(Icons.mail_outline),
                      ),
                      validator: (v) => (v == null || !v.contains('@'))
                          ? l10n.validationEmail
                          : null,
                    ),
                    const SizedBox(height: BrandSpacing.md),
                    TextFormField(
                      controller: _password,
                      obscureText: _obscure,
                      autofillHints: const [AutofillHints.password],
                      textDirection: TextDirection.ltr,
                      decoration: InputDecoration(
                        labelText: l10n.fieldPassword,
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(
                            _obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                          ),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                      validator: (v) =>
                          (v == null || v.length < 8) ? l10n.validationPassword : null,
                      onFieldSubmitted: (_) => _submit(),
                    ),
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: TextButton(
                        onPressed: () => context.push(AppRoutes.forgotPassword),
                        child: Text(l10n.loginForgot),
                      ),
                    ),
                    if (_error != null) ...[
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: BrandColors.danger.withAlpha(20),
                          borderRadius: BorderRadius.circular(BrandRadii.md),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.error_outline, color: BrandColors.danger, size: 18),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _error!,
                                style: t.bodySmall?.copyWith(color: BrandColors.danger),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: BrandSpacing.md),
                    ],
                    FilledButton(
                      onPressed: _busy ? null : _submit,
                      child: _busy
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : Text(l10n.loginButton),
                    ),
                    const SizedBox(height: BrandSpacing.md),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(l10n.loginNoAccount, style: t.bodySmall),
                        TextButton(
                          onPressed: () => context.push(AppRoutes.register),
                          child: Text(l10n.loginRegisterLink),
                        ),
                      ],
                    ),
                    const SizedBox(height: BrandSpacing.lg),
                    Text(
                      l10n.legalPendingNotice,
                      style: t.bodySmall?.copyWith(fontSize: 11),
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _BrandMark extends StatelessWidget {
  const _BrandMark();

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Column(
      children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            color: BrandColors.navy,
            borderRadius: BorderRadius.circular(BrandRadii.lg),
          ),
          child: const Icon(Icons.hub_outlined, color: Colors.white, size: 32),
        ),
        const SizedBox(height: BrandSpacing.sm),
        Text(
          l10n.appName,
          style: Theme.of(context).textTheme.titleMedium,
          textAlign: TextAlign.center,
        ),
      ],
    );
  }
}
