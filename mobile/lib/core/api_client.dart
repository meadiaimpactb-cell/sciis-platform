// Dio-based client for /api/v1 (SPEC §6).
//
// Responsibilities:
//   * base URL from --dart-define=API_BASE (default: local docker compose)
//   * Accept / Accept-Language headers following the app locale
//   * Authorization: Bearer <sanctum token> from secure storage
//   * X-Request-Id on every request (echoed by the API, used in audit_logs)
//   * 401 → wipe token + redirect to login ("refresh-to-login": Sanctum
//     personal access tokens are not refreshable; the user re-authenticates)
//   * 423 (2FA required) → route to the TOTP challenge
//   * maps RFC-7807-ish error bodies {message, errors{field:[...]}, code}
//     into [ApiException]

import 'dart:async';
import 'dart:ui' show Locale;

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import 'auth_storage.dart';

/// Compile-time configuration. Build with
/// `flutter build apk --dart-define=API_BASE=https://api.sciis.sa/api/v1`.
class ApiConfig {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'http://10.0.2.2:8000/api/v1', // Android emulator → host
  );

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);
}

/// Structured API error (SPEC §6: `{message, errors{field:[...]}, code}`).
class ApiException implements Exception {
  ApiException({
    required this.statusCode,
    required this.message,
    this.code,
    this.fieldErrors = const {},
    this.requestId,
  });

  factory ApiException.fromDioException(DioException e) {
    final response = e.response;
    final data = response?.data;
    String message = e.message ?? 'network_error';
    String? code;
    Map<String, List<String>> fieldErrors = const {};
    if (data is Map<String, dynamic>) {
      message = (data['message'] as String?) ?? message;
      code = data['code'] as String?;
      final rawErrors = data['errors'];
      if (rawErrors is Map<String, dynamic>) {
        fieldErrors = rawErrors.map(
          (k, v) => MapEntry(
            k,
            (v is List) ? v.map((x) => x.toString()).toList() : [v.toString()],
          ),
        );
      }
    }
    return ApiException(
      statusCode: response?.statusCode ?? 0,
      message: message,
      code: code,
      fieldErrors: fieldErrors,
      requestId: response?.headers.value('x-request-id'),
    );
  }

  final int statusCode;
  final String message;
  final String? code;
  final Map<String, List<String>> fieldErrors;
  final String? requestId;

  bool get isNetwork => statusCode == 0;
  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isValidation => statusCode == 422;
  bool get isRateLimited => statusCode == 429;

  @override
  String toString() => 'ApiException($statusCode, $code, $message)';
}

/// Session-level events the router listens to.
enum AuthEvent { unauthenticated, twoFactorRequired }

class ApiClient {
  ApiClient({
    required AuthStorage storage,
    required Locale Function() currentLocale,
    Dio? dio,
  })  : _storage = storage,
        _currentLocale = currentLocale,
        _dio = dio ??
            Dio(
              BaseOptions(
                baseUrl: ApiConfig.baseUrl,
                connectTimeout: ApiConfig.connectTimeout,
                receiveTimeout: ApiConfig.receiveTimeout,
                headers: const {'Accept': 'application/json'},
                responseType: ResponseType.json,
              ),
            ) {
    _dio.interceptors.add(_AuthInterceptor(this));
  }

  final Dio _dio;
  final AuthStorage _storage;
  final Locale Function() _currentLocale;
  final _authEvents = StreamController<AuthEvent>.broadcast();
  static const _uuid = Uuid();

  Dio get dio => _dio;
  Stream<AuthEvent> get authEvents => _authEvents.stream;
  AuthStorage get storage => _storage;

  void emitAuthEvent(AuthEvent event) => _authEvents.add(event);

  // -------------------------------------------------------------------------
  // Thin helpers returning decoded bodies
  // -------------------------------------------------------------------------

  Future<Map<String, dynamic>> getJson(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    final res = await _run(() => _dio.get<dynamic>(path, queryParameters: query));
    return _asMap(res.data);
  }

  Future<Map<String, dynamic>> postJson(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
  }) async {
    final res = await _run(
      () => _dio.post<dynamic>(path, data: body, queryParameters: query),
    );
    return _asMap(res.data);
  }

  Future<Map<String, dynamic>> patchJson(String path, {Object? body}) async {
    final res = await _run(() => _dio.patch<dynamic>(path, data: body));
    return _asMap(res.data);
  }

  Future<Map<String, dynamic>> putJson(String path, {Object? body}) async {
    final res = await _run(() => _dio.put<dynamic>(path, data: body));
    return _asMap(res.data);
  }

  Future<void> delete(String path, {Object? body}) async {
    await _run(() => _dio.delete<dynamic>(path, data: body));
  }

  Future<Response<T>> _run<T>(Future<Response<T>> Function() call) async {
    try {
      return await call();
    } on DioException catch (e) {
      throw ApiException.fromDioException(e);
    }
  }

  Map<String, dynamic> _asMap(dynamic data) {
    if (data is Map<String, dynamic>) return data;
    if (data == null) return const {};
    return {'data': data};
  }

  void dispose() => _authEvents.close();

  String _newRequestId() => _uuid.v4();
  Locale _locale() => _currentLocale();
}

class _AuthInterceptor extends Interceptor {
  _AuthInterceptor(this._client);

  final ApiClient _client;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    options.headers['Accept-Language'] = _client._locale().languageCode;
    options.headers['X-Request-Id'] = _client._newRequestId();
    options.headers['X-Client'] = 'sciis-mobile';

    final skipAuth = options.extra['skipAuth'] == true;
    if (!skipAuth) {
      final token = await _client._storage.readToken();
      if (token != null && token.isNotEmpty) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final status = err.response?.statusCode;
    final path = err.requestOptions.path;

    // 401 on anything except the login/register endpoints means the token is
    // revoked or expired → clear and send the user to login.
    if (status == 401 && !path.startsWith('/auth/')) {
      await _client._storage.clearToken();
      await _client._storage.writePendingTwoFactor(false);
      _client.emitAuthEvent(AuthEvent.unauthenticated);
    }

    // API returns 423 Locked with code `two_factor_required` after a password
    // login on a 2FA-enabled account (ACC-05).
    if (status == 423) {
      await _client._storage.writePendingTwoFactor(true);
      _client.emitAuthEvent(AuthEvent.twoFactorRequired);
    }

    handler.next(err);
  }
}

/// Supplies the active locale to the client without a circular dependency on
/// the UI layer. [localeProvider] is overridden in main.dart.
final localeProvider = StateProvider<Locale>((ref) => const Locale('ar'));

final apiClientProvider = Provider<ApiClient>((ref) {
  final client = ApiClient(
    storage: ref.watch(authStorageProvider),
    currentLocale: () => ref.read(localeProvider),
  );
  ref.onDispose(client.dispose);
  return client;
});
