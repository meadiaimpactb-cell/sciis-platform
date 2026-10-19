// Push notifications — device-token registration with the API (SPEC §4
// `device_tokens`, §6 `POST /me/device-tokens`, NTF-01/02).
//
// ─────────────────────────────────────────────────────────────────────────────
// PROVIDER PLACEHOLDER
// ─────────────────────────────────────────────────────────────────────────────
// The actual push provider is intentionally NOT wired in this skeleton so the
// app compiles without Firebase/APNs project files. To activate:
//
//   Android (FCM):
//     1. add `firebase_core` + `firebase_messaging` to pubspec.yaml
//     2. drop `android/app/google-services.json`
//     3. in [PushProvider.initialize] call `Firebase.initializeApp()` then
//        `FirebaseMessaging.instance.getToken()` and listen to
//        `FirebaseMessaging.instance.onTokenRefresh`
//
//   iOS (APNs via FCM, or direct APNs):
//     1. enable Push Notifications + Background Modes (remote-notification)
//        capabilities in Xcode; upload the APNs key to the Firebase project
//     2. drop `ios/Runner/GoogleService-Info.plist`
//     3. `FirebaseMessaging.instance.requestPermission()` before getToken()
//
//   Data residency note (PRV-05): FCM/APNs relay only the notification title,
//   body and an opaque `route` payload — never personal data or document
//   content. The API templates (NotificationChannel.database/mail/sms) stay
//   the source of truth; push is a nudge that deep-links into the app.
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api_client.dart';
import '../../core/auth_storage.dart';

/// Abstraction over FCM / APNs so the rest of the app never imports a vendor SDK.
abstract class PushProvider {
  /// Ask OS permission (iOS prompt; Android 13+ POST_NOTIFICATIONS) and
  /// return the current device token, or null when unavailable.
  Future<String?> initialize();

  /// Emits a new token whenever the provider rotates it.
  Stream<String> get onTokenRefresh;

  /// Emits the `route` field of a tapped notification payload
  /// (e.g. `/opportunities/<uuid>`), used by the router for deep links.
  Stream<String> get onNotificationOpened;
}

/// No-op provider used until FCM/APNs is configured.
class NoopPushProvider implements PushProvider {
  @override
  Future<String?> initialize() async => null;

  @override
  Stream<String> get onTokenRefresh => const Stream.empty();

  @override
  Stream<String> get onNotificationOpened => const Stream.empty();
}

class PushService {
  PushService({
    required ApiClient api,
    required AuthStorage storage,
    required PushProvider provider,
  })  : _api = api,
        _storage = storage,
        _provider = provider;

  final ApiClient _api;
  final AuthStorage _storage;
  final PushProvider _provider;
  StreamSubscription<String>? _refreshSub;

  String get _platform => Platform.isIOS ? 'ios' : 'android';

  /// Call once after the session is authenticated (see router / main).
  Future<void> start() async {
    final token = await _provider.initialize();
    if (token != null) await _register(token);
    _refreshSub ??= _provider.onTokenRefresh.listen(_register);
  }

  /// POST /me/device-tokens {platform, token} — idempotent server-side
  /// (unique per user+token; `last_seen_at` refreshed).
  Future<void> _register(String token) async {
    final previous = await _storage.readDeviceToken();
    if (previous == token) return;
    try {
      await _api.postJson(
        '/me/device-tokens',
        body: {'platform': _platform, 'token': token},
      );
      await _storage.writeDeviceToken(token);
    } on ApiException {
      // Retry on next app start; never block the UI for push registration.
    }
  }

  /// DELETE /me/device-tokens/{token} — call on sign-out so the device stops
  /// receiving notifications for the previous account.
  Future<void> unregister() async {
    final token = await _storage.readDeviceToken();
    if (token == null) return;
    try {
      await _api.delete('/me/device-tokens/${Uri.encodeComponent(token)}');
    } on ApiException {
      // best effort
    }
  }

  Stream<String> get onNotificationOpened => _provider.onNotificationOpened;

  void dispose() {
    _refreshSub?.cancel();
  }
}

final pushProviderProvider = Provider<PushProvider>((ref) => NoopPushProvider());

final pushServiceProvider = Provider<PushService>((ref) {
  final service = PushService(
    api: ref.watch(apiClientProvider),
    storage: ref.watch(authStorageProvider),
    provider: ref.watch(pushProviderProvider),
  );
  ref.onDispose(service.dispose);
  return service;
});
