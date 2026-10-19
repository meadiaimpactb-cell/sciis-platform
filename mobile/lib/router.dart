// go_router configuration with auth-aware redirects.
//
// Public:   /login, /register, /forgot-password
// Pending:  /verify (OTP), /2fa
// Investor: /dashboard, /opportunities, /opportunities/:uuid, /saved
// Owner:    /projects
// Shared:   /notifications, /profile

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'core/theme.dart';
import 'features/auth/auth_repository.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/otp_verify_screen.dart';
import 'features/auth/register_screen.dart';
import 'features/auth/two_factor_screen.dart';
import 'features/dashboard/investor_dashboard_screen.dart';
import 'features/dashboard/profile_screen.dart';
import 'features/entrepreneur/entrepreneur_dashboard_screen.dart';
import 'features/notifications/notifications_screen.dart';
import 'features/notifications/push_service.dart';
import 'features/opportunities/opportunities_list_screen.dart';
import 'features/opportunities/opportunity_detail_screen.dart';
import 'features/opportunities/saved_screen.dart';
import 'l10n/generated/app_localizations.dart';

class AppRoutes {
  AppRoutes._();

  static const splash = '/';
  static const login = '/login';
  static const register = '/register';
  static const forgotPassword = '/forgot-password';
  static const otpVerify = '/verify';
  static const twoFactor = '/2fa';
  static const home = '/home';
  static const dashboard = '/dashboard';
  static const opportunities = '/opportunities';
  static const saved = '/saved';
  static const projects = '/projects';
  static const notifications = '/notifications';
  static const profile = '/profile';

  static String opportunity(String uuid) => '/opportunities/$uuid';
}

/// Bridges a Riverpod provider to go_router's `refreshListenable`.
class _SessionListenable extends ChangeNotifier {
  _SessionListenable(Ref ref) {
    _sub = ref.listen<SessionState>(sessionProvider, (_, __) => notifyListeners());
  }

  late final ProviderSubscription<SessionState> _sub;

  @override
  void dispose() {
    _sub.close();
    super.dispose();
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final listenable = _SessionListenable(ref);
  ref.onDispose(listenable.dispose);

  // Start push registration once authenticated.
  ref.listen<SessionState>(sessionProvider, (prev, next) {
    if (next.isAuthenticated && !(prev?.isAuthenticated ?? false)) {
      unawaited(ref.read(pushServiceProvider).start());
    }
  });

  return GoRouter(
    initialLocation: AppRoutes.splash,
    refreshListenable: listenable,
    debugLogDiagnostics: false,
    redirect: (context, state) {
      final session = ref.read(sessionProvider);
      final loc = state.matchedLocation;
      final isPublic = loc == AppRoutes.login ||
          loc == AppRoutes.register ||
          loc == AppRoutes.forgotPassword;

      if (session.loading) return loc == AppRoutes.splash ? null : AppRoutes.splash;

      if (!session.hasToken) return isPublic ? null : AppRoutes.login;

      if (session.pendingTwoFactor) {
        return loc == AppRoutes.twoFactor ? null : AppRoutes.twoFactor;
      }

      final me = session.me;
      if (me == null) return loc == AppRoutes.splash ? null : AppRoutes.splash;

      if (!me.isVerified) {
        return loc == AppRoutes.otpVerify ? null : AppRoutes.otpVerify;
      }

      if (loc == AppRoutes.splash || loc == AppRoutes.home || isPublic ||
          loc == AppRoutes.otpVerify || loc == AppRoutes.twoFactor) {
        return me.type.isEntrepreneur ? AppRoutes.projects : AppRoutes.dashboard;
      }

      // Role gating: entrepreneurs do not browse opportunities (doc §6.6).
      if (me.type.isEntrepreneur &&
          (loc.startsWith(AppRoutes.opportunities) ||
              loc == AppRoutes.saved ||
              loc == AppRoutes.dashboard)) {
        return AppRoutes.projects;
      }
      if (!me.type.isEntrepreneur && loc == AppRoutes.projects) {
        return AppRoutes.dashboard;
      }
      return null;
    },
    routes: [
      GoRoute(path: AppRoutes.splash, builder: (_, __) => const _SplashScreen()),
      GoRoute(path: AppRoutes.login, builder: (_, __) => const LoginScreen()),
      GoRoute(path: AppRoutes.register, builder: (_, __) => const RegisterScreen()),
      GoRoute(path: AppRoutes.forgotPassword, builder: (_, __) => const ForgotPasswordScreen()),
      GoRoute(path: AppRoutes.otpVerify, builder: (_, __) => const OtpVerifyScreen()),
      GoRoute(path: AppRoutes.twoFactor, builder: (_, __) => const TwoFactorScreen()),
      GoRoute(path: AppRoutes.home, builder: (_, __) => const _SplashScreen()),
      GoRoute(path: AppRoutes.notifications, builder: (_, __) => const NotificationsScreen()),
      GoRoute(path: AppRoutes.profile, builder: (_, __) => const ProfileScreen()),
      GoRoute(path: AppRoutes.saved, builder: (_, __) => const SavedOpportunitiesScreen()),
      GoRoute(
        path: '${AppRoutes.opportunities}/:uuid',
        builder: (_, state) => OpportunityDetailScreen(uuid: state.pathParameters['uuid']!),
      ),
      // Investor shell with bottom navigation.
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => _InvestorShell(shell: shell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(path: AppRoutes.dashboard, builder: (_, __) => const InvestorDashboardScreen()),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(path: AppRoutes.opportunities, builder: (_, __) => const OpportunitiesListScreen()),
            ],
          ),
        ],
      ),
      // Entrepreneur home.
      GoRoute(path: AppRoutes.projects, builder: (_, __) => const EntrepreneurDashboardScreen()),
    ],
    errorBuilder: (context, state) => Scaffold(
      body: Center(child: Text(AppLocalizations.of(context).errorNotFound)),
    ),
  );
});

class _InvestorShell extends StatelessWidget {
  const _InvestorShell({required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      body: shell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        onDestinationSelected: (i) => shell.goBranch(i, initialLocation: i == shell.currentIndex),
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.space_dashboard_outlined),
            selectedIcon: const Icon(Icons.space_dashboard),
            label: l10n.navDashboard,
          ),
          NavigationDestination(
            icon: const Icon(Icons.explore_outlined),
            selectedIcon: const Icon(Icons.explore),
            label: l10n.navOpportunities,
          ),
        ],
      ),
    );
  }
}

class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: BrandColors.navy,
      body: Center(child: CircularProgressIndicator(color: Colors.white)),
    );
  }
}
