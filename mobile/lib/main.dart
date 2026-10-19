// SCIIS mobile — entry point.
//
// * MaterialApp.router with go_router (lib/router.dart)
// * Locales: ar (default) + en; text direction follows the locale automatically
//   via MaterialApp's Localizations/Directionality (RTL for ar).
// * Theme from SPEC §10 brand tokens (lib/core/theme.dart) with
//   "IBM Plex Sans Arabic" declared in pubspec.yaml.

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'core/api_client.dart';
import 'core/auth_storage.dart';
import 'core/theme.dart';
import 'l10n/generated/app_localizations.dart';
import 'router.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('ar');
  await initializeDateFormatting('en');

  // Restore the persisted locale before the first frame so the UI never flashes.
  final storage = AuthStorage();
  final savedLocale = await storage.readLocale();
  final initialLocale = Locale(savedLocale == 'en' ? 'en' : 'ar');

  runApp(
    ProviderScope(
      overrides: [
        authStorageProvider.overrideWithValue(storage),
        localeProvider.overrideWith((ref) => initialLocale),
      ],
      child: const SciisApp(),
    ),
  );
}

class SciisApp extends ConsumerWidget {
  const SciisApp({super.key});

  static const supportedLocales = [Locale('ar'), Locale('en')];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);
    final router = ref.watch(routerProvider);

    return MaterialApp.router(
      onGenerateTitle: (context) => AppLocalizations.of(context).appName,
      debugShowCheckedModeBanner: false,
      routerConfig: router,
      theme: buildLightTheme(),
      darkTheme: buildDarkTheme(),
      themeMode: ThemeMode.light, // features.dark_mode=false in P1
      locale: locale,
      supportedLocales: supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      localeResolutionCallback: (device, supported) {
        // Explicit user choice wins; otherwise Arabic unless the device is English.
        if (supported.contains(locale)) return locale;
        if (device != null && device.languageCode == 'en') return const Locale('en');
        return const Locale('ar');
      },
      builder: (context, child) {
        // Clamp system text scaling so Arabic layouts stay stable (UX-01).
        final mq = MediaQuery.of(context);
        return MediaQuery(
          data: mq.copyWith(
            textScaler: mq.textScaler.clamp(minScaleFactor: 0.9, maxScaleFactor: 1.3),
          ),
          child: child ?? const SizedBox.shrink(),
        );
      },
    );
  }
}
