// Secure persistence of the Sanctum bearer token and small auth state.
//
// iOS  → Keychain (accessibility: first unlock, this device only)
// Android → EncryptedSharedPreferences (AES-256-GCM via Android Keystore)
//
// Never store the token in SharedPreferences / files / logs (SEC-01, ACC-01).

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class AuthStorage {
  AuthStorage([FlutterSecureStorage? storage])
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock_this_device,
              ),
            );

  final FlutterSecureStorage _storage;

  static const _kToken = 'sciis.sanctum_token';
  static const _kUserType = 'sciis.user_type';
  static const _kLocale = 'sciis.locale';
  static const _kPendingTwoFactor = 'sciis.pending_2fa';
  static const _kDeviceToken = 'sciis.device_token';

  Future<String?> readToken() => _storage.read(key: _kToken);

  Future<void> writeToken(String token) => _storage.write(key: _kToken, value: token);

  Future<void> clearToken() => _storage.delete(key: _kToken);

  Future<String?> readUserType() => _storage.read(key: _kUserType);

  Future<void> writeUserType(String type) =>
      _storage.write(key: _kUserType, value: type);

  Future<String?> readLocale() => _storage.read(key: _kLocale);

  Future<void> writeLocale(String locale) =>
      _storage.write(key: _kLocale, value: locale);

  /// True while a login succeeded with password but TOTP challenge is pending
  /// (staff accounts — ACC-05). The temporary token is kept in [_kToken].
  Future<bool> readPendingTwoFactor() async =>
      (await _storage.read(key: _kPendingTwoFactor)) == '1';

  Future<void> writePendingTwoFactor(bool pending) =>
      _storage.write(key: _kPendingTwoFactor, value: pending ? '1' : '0');

  /// Last push token successfully registered with POST /me/device-tokens.
  Future<String?> readDeviceToken() => _storage.read(key: _kDeviceToken);

  Future<void> writeDeviceToken(String token) =>
      _storage.write(key: _kDeviceToken, value: token);

  Future<void> clearAll() => _storage.deleteAll();
}

final authStorageProvider = Provider<AuthStorage>((ref) => AuthStorage());
