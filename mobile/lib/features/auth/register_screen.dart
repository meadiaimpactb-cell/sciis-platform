// Register entry (ACC-01): account type picker → basic form → consents → OTP.
// POST /auth/register {type, name, email, phone, password, locale, consents:[privacy_policy, terms(, data_accuracy for investors)]}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/enums.dart';
import '../../core/theme.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import 'auth_repository.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController(text: '+966');
  final _password = TextEditingController();
  UserType _type = UserType.investorIndividual;
  bool _acceptPrivacy = false;
  bool _acceptTerms = false;
  bool _acceptAccuracy = false;
  bool _busy = false;
  Map<String, List<String>> _fieldErrors = const {};
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  String? _fieldError(String key) => _fieldErrors[key]?.join('\n');

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    if (!(_formKey.currentState?.validate() ?? false)) return;
    if (!_acceptPrivacy || !_acceptTerms || (_type.isInvestor && !_acceptAccuracy)) {
      setState(() => _error = l10n.registerConsentRequired);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final locale = Localizations.localeOf(context).languageCode;
      await ref.read(authRepositoryProvider).register(
            RegisterInput(
              type: _type,
              name: _name.text.trim(),
              email: _email.text.trim(),
              phone: _phone.text.trim(),
              password: _password.text,
              locale: locale,
            ),
          );
      await ref.read(sessionProvider.notifier).refreshMe();
      if (mounted) context.go(AppRoutes.otpVerify);
    } on ApiException catch (e) {
      setState(() {
        _fieldErrors = e.fieldErrors;
        _error = e.isValidation ? null : e.message;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    final locale = Localizations.localeOf(context);
    final selectable = UserType.values.where((u) => u != UserType.staff).toList();

    return Scaffold(
      appBar: AppBar(title: Text(l10n.registerTitle)),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(BrandSpacing.lg),
            children: [
              Text(l10n.registerTypeLabel, style: t.titleMedium),
              const SizedBox(height: BrandSpacing.sm),
              ...selectable.map(
                (u) => Card(
                  child: RadioListTile<UserType>(
                    value: u,
                    groupValue: _type,
                    onChanged: (v) => setState(() => _type = v ?? _type),
                    title: Text(u.label(locale)),
                    subtitle: Text(
                      u.isInvestor ? l10n.registerTypeInvestorHint : l10n.registerTypeEntrepreneurHint,
                      style: t.bodySmall,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: BrandSpacing.lg),
              TextFormField(
                controller: _name,
                decoration: InputDecoration(
                  labelText: l10n.fieldName,
                  errorText: _fieldError('name'),
                ),
                validator: (v) => (v == null || v.trim().length < 3) ? l10n.validationRequired : null,
              ),
              const SizedBox(height: BrandSpacing.md),
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                textDirection: TextDirection.ltr,
                decoration: InputDecoration(
                  labelText: l10n.fieldEmail,
                  errorText: _fieldError('email'),
                ),
                validator: (v) => (v == null || !v.contains('@')) ? l10n.validationEmail : null,
              ),
              const SizedBox(height: BrandSpacing.md),
              TextFormField(
                controller: _phone,
                keyboardType: TextInputType.phone,
                textDirection: TextDirection.ltr,
                decoration: InputDecoration(
                  labelText: l10n.fieldPhone,
                  helperText: l10n.fieldPhoneHint,
                  errorText: _fieldError('phone'),
                ),
                validator: (v) => (v == null || !RegExp(r'^\+[1-9]\d{7,14}$').hasMatch(v.trim()))
                    ? l10n.validationPhone
                    : null,
              ),
              const SizedBox(height: BrandSpacing.md),
              TextFormField(
                controller: _password,
                obscureText: true,
                textDirection: TextDirection.ltr,
                decoration: InputDecoration(
                  labelText: l10n.fieldPassword,
                  helperText: l10n.fieldPasswordHint,
                  errorText: _fieldError('password'),
                ),
                validator: (v) => (v == null || v.length < 8) ? l10n.validationPassword : null,
              ),
              const SizedBox(height: BrandSpacing.lg),
              CheckboxListTile(
                value: _acceptPrivacy,
                onChanged: (v) => setState(() => _acceptPrivacy = v ?? false),
                controlAffinity: ListTileControlAffinity.leading,
                contentPadding: EdgeInsets.zero,
                title: Text(l10n.consentPrivacy, style: t.bodyMedium),
              ),
              CheckboxListTile(
                value: _acceptTerms,
                onChanged: (v) => setState(() => _acceptTerms = v ?? false),
                controlAffinity: ListTileControlAffinity.leading,
                contentPadding: EdgeInsets.zero,
                title: Text(l10n.consentTerms, style: t.bodyMedium),
              ),
              if (_type.isInvestor)
                CheckboxListTile(
                  value: _acceptAccuracy,
                  onChanged: (v) => setState(() => _acceptAccuracy = v ?? false),
                  controlAffinity: ListTileControlAffinity.leading,
                  contentPadding: EdgeInsets.zero,
                  title: Text(l10n.consentDataAccuracy, style: t.bodyMedium),
                ),
              if (_error != null) ...[
                const SizedBox(height: BrandSpacing.sm),
                Text(_error!, style: t.bodySmall?.copyWith(color: BrandColors.danger)),
              ],
              const SizedBox(height: BrandSpacing.lg),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: Text(l10n.registerButton),
              ),
              const SizedBox(height: BrandSpacing.sm),
              Text(
                l10n.legalPendingNotice,
                style: t.bodySmall?.copyWith(fontSize: 11),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _email = TextEditingController();
  bool _sent = false;
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.forgotTitle)),
      body: Padding(
        padding: const EdgeInsets.all(BrandSpacing.lg),
        child: _sent
            ? Center(child: Text(l10n.forgotSent, textAlign: TextAlign.center))
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(l10n.forgotHint),
                  const SizedBox(height: BrandSpacing.md),
                  TextField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    textDirection: TextDirection.ltr,
                    decoration: InputDecoration(labelText: l10n.fieldEmail),
                  ),
                  const SizedBox(height: BrandSpacing.lg),
                  FilledButton(
                    onPressed: _busy
                        ? null
                        : () async {
                            setState(() => _busy = true);
                            try {
                              await ref
                                  .read(authRepositoryProvider)
                                  .forgotPassword(_email.text.trim());
                            } catch (_) {
                              // Always show the same message (no account enumeration).
                            }
                            if (mounted) setState(() => _sent = true);
                          },
                    child: Text(l10n.forgotButton),
                  ),
                ],
              ),
      ),
    );
  }
}
