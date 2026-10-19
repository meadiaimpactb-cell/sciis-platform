// Auth flows against /auth/* and /me (SPEC §6) using Sanctum bearer tokens.
//
// Mobile login flow:
//   POST /auth/login {email, password, device_name} → {token, user}
//   (staff with TOTP) → 423 two_factor_required → POST /auth/2fa/challenge {code}
//   token stored in AuthStorage → every request carries Authorization: Bearer
//   POST /auth/logout revokes the current token server-side.

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api_client.dart';
import '../../core/auth_storage.dart';
import '../../core/enums.dart';
import '../../core/models/me.dart';

class RegisterInput {
  const RegisterInput({
    required this.type,
    required this.name,
    required this.email,
    required this.phone,
    required this.password,
    required this.locale,
  });

  final UserType type;
  final String name;
  final String email;
  final String phone; // E.164, e.g. +9665XXXXXXXX
  final String password;
  final String locale;

  Map<String, dynamic> toJson() => {
        'type': type.value,
        'name': name,
        'email': email,
        'phone': phone,
        'password': password,
        'locale': locale,
        // ACC-01 / PRV-02: explicit consents captured at registration.
        'consents': [ConsentType.privacyPolicy.value, ConsentType.terms.value],
      };
}

class AuthRepository {
  AuthRepository(this._api, this._storage);

  final ApiClient _api;
  final AuthStorage _storage;

  Future<Me> register(RegisterInput input) async {
    final json = await _api.postJson(
      '/auth/register',
      body: input.toJson(),
      query: null,
    );
    // Mobile registration returns a token immediately (unverified state).
    final token = json['token'] as String?;
    if (token != null) await _storage.writeToken(token);
    return Me.fromJson(json);
  }

  /// Returns `true` when fully authenticated, `false` when a 2FA challenge is
  /// required (the caller routes to the TOTP screen).
  Future<bool> login({
    required String email,
    required String password,
    required String deviceName,
  }) async {
    try {
      final json = await _api.postJson(
        '/auth/login',
        body: {'email': email, 'password': password, 'device_name': deviceName},
      );
      final token = json['token'] as String?;
      if (token == null) {
        throw ApiException(statusCode: 500, message: 'token_missing');
      }
      await _storage.writeToken(token);
      await _storage.writePendingTwoFactor(json['two_factor_required'] == true);
      final me = Me.fromJson(json);
      await _storage.writeUserType(me.type.value);
      return json['two_factor_required'] != true;
    } on ApiException catch (e) {
      if (e.statusCode == 423) {
        // Interceptor already stored the pending flag; the temporary token (if
        // any) is in the response body of some implementations — be tolerant.
        return false;
      }
      rethrow;
    }
  }

  Future<void> challengeTwoFactor(String code) async {
    final json = await _api.postJson('/auth/2fa/challenge', body: {'code': code});
    final token = json['token'] as String?;
    if (token != null) await _storage.writeToken(token);
    await _storage.writePendingTwoFactor(false);
  }

  Future<void> verifyEmail(String code) =>
      _api.postJson('/auth/verify-email', body: {'code': code});

  Future<void> verifyPhone(String code) =>
      _api.postJson('/auth/verify-phone', body: {'code': code});

  Future<void> resendOtp(String channel) =>
      _api.postJson('/auth/resend-otp', body: {'channel': channel});

  Future<void> forgotPassword(String email) =>
      _api.postJson('/auth/forgot-password', body: {'email': email});

  Future<Me> me() async => Me.fromJson(await _api.getJson('/me'));

  Future<void> logout() async {
    try {
      await _api.postJson('/auth/logout');
    } catch (_) {
      // Token may already be invalid; always clear locally.
    }
    await _storage.clearAll();
  }
}

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.watch(apiClientProvider), ref.watch(authStorageProvider)),
);

/// Session state consumed by the router redirect.
class SessionState {
  const SessionState({
    this.me,
    this.hasToken = false,
    this.pendingTwoFactor = false,
    this.loading = true,
  });

  final Me? me;
  final bool hasToken;
  final bool pendingTwoFactor;
  final bool loading;

  bool get isAuthenticated => hasToken && !pendingTwoFactor && me != null;

  SessionState copyWith({
    Me? me,
    bool? hasToken,
    bool? pendingTwoFactor,
    bool? loading,
    bool clearMe = false,
  }) =>
      SessionState(
        me: clearMe ? null : (me ?? this.me),
        hasToken: hasToken ?? this.hasToken,
        pendingTwoFactor: pendingTwoFactor ?? this.pendingTwoFactor,
        loading: loading ?? this.loading,
      );
}

class SessionNotifier extends Notifier<SessionState> {
  @override
  SessionState build() {
    // Listen to interceptor events (401 / 423).
    final api = ref.watch(apiClientProvider);
    final sub = api.authEvents.listen((event) {
      switch (event) {
        case AuthEvent.unauthenticated:
          state = const SessionState(loading: false);
        case AuthEvent.twoFactorRequired:
          state = state.copyWith(hasToken: true, pendingTwoFactor: true, loading: false);
      }
    });
    ref.onDispose(sub.cancel);
    Future<void>.microtask(restore);
    return const SessionState();
  }

  Future<void> restore() async {
    final storage = ref.read(authStorageProvider);
    final token = await storage.readToken();
    if (token == null || token.isEmpty) {
      state = const SessionState(loading: false);
      return;
    }
    final pending = await storage.readPendingTwoFactor();
    if (pending) {
      state = const SessionState(hasToken: true, pendingTwoFactor: true, loading: false);
      return;
    }
    try {
      final me = await ref.read(authRepositoryProvider).me();
      state = SessionState(me: me, hasToken: true, loading: false);
    } on ApiException catch (e) {
      if (e.isNetwork) {
        // Offline: keep token, mark loaded; screens will show cached/empty state.
        state = const SessionState(hasToken: true, loading: false);
      } else {
        state = const SessionState(loading: false);
      }
    }
  }

  Future<void> refreshMe() async {
    final me = await ref.read(authRepositoryProvider).me();
    state = state.copyWith(me: me, hasToken: true, pendingTwoFactor: false, loading: false);
  }

  Future<void> signOut() async {
    await ref.read(authRepositoryProvider).logout();
    state = const SessionState(loading: false);
  }
}

final sessionProvider =
    NotifierProvider<SessionNotifier, SessionState>(SessionNotifier.new);
