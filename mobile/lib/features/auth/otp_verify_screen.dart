// Email + SMS OTP verification after registration (ACC-01).
// POST /auth/verify-email {code} · POST /auth/verify-phone {code} · POST /auth/resend-otp {channel}

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../l10n/generated/app_localizations.dart';
import '../../router.dart';
import 'auth_repository.dart';

enum OtpChannel { email, sms }

class OtpVerifyScreen extends ConsumerStatefulWidget {
  const OtpVerifyScreen({super.key});

  @override
  ConsumerState<OtpVerifyScreen> createState() => _OtpVerifyScreenState();
}

class _OtpVerifyScreenState extends ConsumerState<OtpVerifyScreen> {
  OtpChannel _channel = OtpChannel.email;
  bool _busy = false;
  String? _error;
  int _cooldown = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _startCooldown() {
    setState(() => _cooldown = 60);
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return;
      setState(() => _cooldown -= 1);
      if (_cooldown <= 0) t.cancel();
    });
  }

  Future<void> _verify(String code) async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final repo = ref.read(authRepositoryProvider);
      if (_channel == OtpChannel.email) {
        await repo.verifyEmail(code);
      } else {
        await repo.verifyPhone(code);
      }
      await ref.read(sessionProvider.notifier).refreshMe();
      if (!mounted) return;
      final me = ref.read(sessionProvider).me;
      if (me != null && !me.emailVerified) {
        setState(() => _channel = OtpChannel.email);
      } else if (me != null && !me.phoneVerified) {
        setState(() => _channel = OtpChannel.sms);
      } else {
        context.go(AppRoutes.home);
      }
    } on ApiException catch (e) {
      setState(() => _error = e.isValidation ? l10n.otpInvalid : e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    try {
      await ref.read(authRepositoryProvider).resendOtp(_channel.name);
      _startCooldown();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = Theme.of(context).textTheme;
    final me = ref.watch(sessionProvider).me;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.otpTitle)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(BrandSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SegmentedButton<OtpChannel>(
                segments: [
                  ButtonSegment(
                    value: OtpChannel.email,
                    icon: Icon(
                      me?.emailVerified == true ? Icons.check_circle : Icons.mail_outline,
                    ),
                    label: Text(l10n.otpChannelEmail),
                  ),
                  ButtonSegment(
                    value: OtpChannel.sms,
                    icon: Icon(
                      me?.phoneVerified == true ? Icons.check_circle : Icons.sms_outlined,
                    ),
                    label: Text(l10n.otpChannelSms),
                  ),
                ],
                selected: {_channel},
                onSelectionChanged: (s) => setState(() => _channel = s.first),
              ),
              const SizedBox(height: BrandSpacing.lg),
              Text(
                _channel == OtpChannel.email
                    ? l10n.otpSentToEmail(me?.email ?? '')
                    : l10n.otpSentToPhone(me?.phone ?? ''),
                style: t.bodyMedium,
              ),
              const SizedBox(height: BrandSpacing.lg),
              OtpCodeField(
                enabled: !_busy,
                onCompleted: _verify,
              ),
              if (_error != null) ...[
                const SizedBox(height: BrandSpacing.sm),
                Text(_error!, style: t.bodySmall?.copyWith(color: BrandColors.danger)),
              ],
              const SizedBox(height: BrandSpacing.lg),
              TextButton(
                onPressed: _cooldown > 0 ? null : _resend,
                child: Text(
                  _cooldown > 0 ? l10n.otpResendIn(_cooldown) : l10n.otpResend,
                ),
              ),
              if (_busy) const LinearProgressIndicator(),
            ],
          ),
        ),
      ),
    );
  }
}

/// 6-digit code entry; fires [onCompleted] when all digits are present.
class OtpCodeField extends StatefulWidget {
  const OtpCodeField({
    super.key,
    required this.onCompleted,
    this.length = 6,
    this.enabled = true,
  });

  final void Function(String code) onCompleted;
  final int length;
  final bool enabled;

  @override
  State<OtpCodeField> createState() => _OtpCodeFieldState();
}

class _OtpCodeFieldState extends State<OtpCodeField> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: TextField(
        controller: _controller,
        enabled: widget.enabled,
        autofocus: true,
        keyboardType: TextInputType.number,
        textAlign: TextAlign.center,
        maxLength: widget.length,
        autofillHints: const [AutofillHints.oneTimeCode],
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        style: const TextStyle(fontSize: 28, letterSpacing: 12, fontWeight: FontWeight.w600),
        decoration: const InputDecoration(counterText: '', hintText: '••••••'),
        onChanged: (v) {
          if (v.length == widget.length) {
            widget.onCompleted(v);
            _controller.clear();
          }
        },
      ),
    );
  }
}
