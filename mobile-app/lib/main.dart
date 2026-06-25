import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'services/auth_provider.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/register_screen.dart';
import 'screens/home_router.dart';
import 'screens/shared/profil_screen.dart';
import 'screens/shared/tracabilite_screen.dart';
import 'widgets/app_theme.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final auth = AuthProvider();
  await auth.init(); // Charge le token/utilisateur depuis SharedPreferences
  runApp(
    ChangeNotifierProvider.value(value: auth, child: const AgriPlatformApp()),
  );
}

class AgriPlatformApp extends StatelessWidget {
  const AgriPlatformApp({super.key});

  @override
  Widget build(BuildContext context) {
    final isLoggedIn = context.watch<AuthProvider>().isLoggedIn;
    return MaterialApp(
      title: 'SenAgriLink',
      theme: AppTheme.theme,
      debugShowCheckedModeBanner: false,
      home: isLoggedIn ? const HomeRouter() : const LoginScreen(),
      routes: {
        '/login':       (_) => const LoginScreen(),
        '/register':    (_) => const RegisterScreen(),
        '/home':        (_) => const HomeRouter(),
        '/profil':      (_) => const ProfilScreen(),
        '/tracabilite': (_) => const TracabiliteScreen(),
      },
    );
  }
}
