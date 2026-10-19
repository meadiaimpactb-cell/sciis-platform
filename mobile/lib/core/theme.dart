// Brand tokens — SPEC §10 (doc §8). Mirrors web/assets/css/tokens.css.

import 'package:flutter/material.dart';

class BrandColors {
  BrandColors._();

  static const ink = Color(0xFF0A1020);
  static const navy = Color(0xFF101B33);
  static const blue = Color(0xFF1F5BFF);
  static const bg = Color(0xFFF4F6FA);
  static const surface = Color(0xFFFFFFFF);
  static const text = Color(0xFF0A1020);
  static const muted = Color(0xFF5B6478);
  static const border = Color(0xFFE3E7EF);

  static const success = Color(0xFF047857);
  static const warning = Color(0xFFD97706);
  static const danger = Color(0xFFB91C1C);
}

class BrandRadii {
  BrandRadii._();
  static const sm = 8.0;
  static const md = 12.0;
  static const lg = 16.0;
}

class BrandSpacing {
  BrandSpacing._();
  static const xs = 4.0;
  static const sm = 8.0;
  static const md = 16.0;
  static const lg = 24.0;
  static const xl = 32.0;
}

const kFontFamily = 'IBM Plex Sans Arabic';

ThemeData buildLightTheme() {
  final base = ThemeData(
    useMaterial3: true,
    brightness: Brightness.light,
    fontFamily: kFontFamily,
    colorScheme: const ColorScheme.light(
      primary: BrandColors.blue,
      onPrimary: Colors.white,
      secondary: BrandColors.navy,
      onSecondary: Colors.white,
      surface: BrandColors.surface,
      onSurface: BrandColors.text,
      error: BrandColors.danger,
      onError: Colors.white,
      outline: BrandColors.border,
      onSurfaceVariant: BrandColors.muted,
    ),
    scaffoldBackgroundColor: BrandColors.bg,
  );

  final textTheme = base.textTheme.apply(
    fontFamily: kFontFamily,
    bodyColor: BrandColors.text,
    displayColor: BrandColors.ink,
  );

  return base.copyWith(
    textTheme: textTheme.copyWith(
      headlineSmall: textTheme.headlineSmall?.copyWith(
        fontWeight: FontWeight.w700,
        color: BrandColors.ink,
      ),
      titleLarge: textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
      titleMedium: textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
      labelLarge: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
      bodySmall: textTheme.bodySmall?.copyWith(color: BrandColors.muted),
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: BrandColors.surface,
      foregroundColor: BrandColors.ink,
      elevation: 0,
      scrolledUnderElevation: 0.5,
      centerTitle: false,
      titleTextStyle: TextStyle(
        fontFamily: kFontFamily,
        fontSize: 18,
        fontWeight: FontWeight.w700,
        color: BrandColors.ink,
      ),
    ),
    cardTheme: CardTheme(
      color: BrandColors.surface,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(BrandRadii.lg),
        side: const BorderSide(color: BrandColors.border),
      ),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: BrandColors.bg,
      selectedColor: BrandColors.blue.withAlpha(31),
      side: const BorderSide(color: BrandColors.border),
      labelStyle: const TextStyle(fontFamily: kFontFamily, fontSize: 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: BrandColors.surface,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(BrandRadii.md),
        borderSide: const BorderSide(color: BrandColors.border),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(BrandRadii.md),
        borderSide: const BorderSide(color: BrandColors.border),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(BrandRadii.md),
        borderSide: const BorderSide(color: BrandColors.blue, width: 1.5),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(BrandRadii.md),
        borderSide: const BorderSide(color: BrandColors.danger),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: BrandColors.blue,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(48),
        textStyle: const TextStyle(
          fontFamily: kFontFamily,
          fontWeight: FontWeight.w600,
          fontSize: 15,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(BrandRadii.md),
        ),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: BrandColors.navy,
        minimumSize: const Size.fromHeight(48),
        side: const BorderSide(color: BrandColors.border),
        textStyle: const TextStyle(
          fontFamily: kFontFamily,
          fontWeight: FontWeight.w600,
          fontSize: 15,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(BrandRadii.md),
        ),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: BrandColors.blue,
        textStyle: const TextStyle(
          fontFamily: kFontFamily,
          fontWeight: FontWeight.w600,
        ),
      ),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: BrandColors.surface,
      indicatorColor: BrandColors.blue.withAlpha(31),
      labelTextStyle: WidgetStateProperty.all(
        const TextStyle(fontFamily: kFontFamily, fontSize: 12),
      ),
    ),
    tabBarTheme: const TabBarTheme(
      labelColor: BrandColors.blue,
      unselectedLabelColor: BrandColors.muted,
      indicatorColor: BrandColors.blue,
      labelStyle: TextStyle(fontFamily: kFontFamily, fontWeight: FontWeight.w600),
      unselectedLabelStyle: TextStyle(fontFamily: kFontFamily),
    ),
    dividerTheme: const DividerThemeData(color: BrandColors.border, thickness: 1),
    snackBarTheme: SnackBarThemeData(
      backgroundColor: BrandColors.navy,
      contentTextStyle: const TextStyle(fontFamily: kFontFamily, color: Colors.white),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(BrandRadii.md),
      ),
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: BrandColors.surface,
      showDragHandle: true,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
    ),
  );
}

/// `features.dark_mode=false` in P1 (SPEC §7) — kept as a parity placeholder so
/// the switch is a one-liner later.
ThemeData buildDarkTheme() => buildLightTheme();
