import 'package:flutter/material.dart';
import 'app_theme.dart';

/// Carte de statistique avec icône, valeur et libellé.
/// Dans une carte étroite (trois côte à côte sur un petit téléphone), l'icône
/// passe au-dessus du texte ; la valeur est réduite plutôt que coupée.
class InfoCard extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final Color? color;

  const InfoCard({super.key, required this.icon, required this.label, required this.value, this.color});

  @override
  Widget build(BuildContext context) {
    final c = color ?? AppTheme.vert;
    final avatar = CircleAvatar(backgroundColor: c.withAlpha(30), child: Icon(icon, color: c, size: 22));
    final textes = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
      Text(label, style: const TextStyle(fontSize: 12, color: Colors.grey)),
      FittedBox(
        fit: BoxFit.scaleDown,
        alignment: Alignment.centerLeft,
        child: Text(value, style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: c)),
      ),
    ]);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: LayoutBuilder(
          builder: (context, contraintes) => contraintes.maxWidth < 150
              ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [avatar, const SizedBox(height: 8), textes])
              : Row(children: [avatar, const SizedBox(width: 12), Expanded(child: textes)]),
        ),
      ),
    );
  }
}

/// Widget vide centré.
class EmptyState extends StatelessWidget {
  final String message;
  final IconData icon;
  const EmptyState({super.key, required this.message, this.icon = Icons.inbox_outlined});

  @override
  Widget build(BuildContext context) => Center(
    child: Column(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 60, color: Colors.grey.shade300),
      const SizedBox(height: 12),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Text(message, textAlign: TextAlign.center, style: const TextStyle(color: Colors.grey, fontSize: 15)),
      ),
    ]),
  );
}

/// Widget d'erreur avec message.
class ErrorState extends StatelessWidget {
  final String message;
  final VoidCallback? onRetry;
  const ErrorState({super.key, required this.message, this.onRetry});

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.error_outline, size: 50, color: AppTheme.rouge),
        const SizedBox(height: 12),
        Text(message, textAlign: TextAlign.center, style: const TextStyle(color: AppTheme.rouge)),
        if (onRetry != null) ...[
          const SizedBox(height: 16),
          ElevatedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Réessayer')),
        ],
      ]),
    ),
  );
}
