import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';
import '../home_router.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});
  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey     = GlobalKey<FormState>();
  final _prenomCtl   = TextEditingController();
  final _nomCtl      = TextEditingController();
  final _emailCtl    = TextEditingController();
  final _telCtl      = TextEditingController();
  final _mdpCtl      = TextEditingController();
  final _mdpConfCtl  = TextEditingController();
  bool _obscure1     = true;
  bool _obscure2     = true;
  String _roleChoisi = 'producteur';

  // Producteur
  final _regionCtl = TextEditingController();
  final _culturesCtl = TextEditingController();
  final _superficieCtl = TextEditingController();
  // Gestionnaire
  final _nomEntrepotCtl = TextEditingController();
  final _localisationCtl = TextEditingController();
  final _capaciteCtl = TextEditingController();
  // Acheteur
  final _typeActiviteCtl = TextEditingController();
  final _volumeAchatCtl = TextEditingController();
  // Transporteur
  final _typeVehiculeCtl = TextEditingController();
  final _capaciteChargeCtl = TextEditingController();
  final _zoneCtl = TextEditingController();

  static const _roles = [
    ('producteur',            'Producteur'),
    ('gestionnaire_entrepot', 'Gestionnaire d\'entrepôt'),
    ('acheteur_gros',         'Acheteur en gros'),
    ('transporteur',          'Transporteur'),
  ];

  String? _requisPourRole(String? v, List<String> roles) {
    if (!roles.contains(_roleChoisi)) return null;
    return (v == null || v.trim().isEmpty) ? 'Requis' : null;
  }

  @override
  void dispose() {
    _prenomCtl.dispose(); _nomCtl.dispose(); _emailCtl.dispose();
    _telCtl.dispose(); _mdpCtl.dispose(); _mdpConfCtl.dispose();
    _regionCtl.dispose(); _culturesCtl.dispose(); _superficieCtl.dispose();
    _nomEntrepotCtl.dispose(); _localisationCtl.dispose(); _capaciteCtl.dispose();
    _typeActiviteCtl.dispose(); _volumeAchatCtl.dispose();
    _typeVehiculeCtl.dispose(); _capaciteChargeCtl.dispose(); _zoneCtl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_mdpCtl.text != _mdpConfCtl.text) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Les mots de passe ne correspondent pas.'), backgroundColor: AppTheme.rouge));
      return;
    }
    final auth = context.read<AuthProvider>();
    final data = <String, dynamic>{
      'prenom':       _prenomCtl.text.trim(),
      'nom':          _nomCtl.text.trim(),
      'email':        _emailCtl.text.trim(),
      'telephone':    _telCtl.text.trim(),
      'mot_de_passe': _mdpCtl.text,
      'mot_de_passe_confirmation': _mdpConfCtl.text,
      'role':         _roleChoisi,
    };

    switch (_roleChoisi) {
      case 'producteur':
        data.addAll({
          'region': _regionCtl.text.trim(),
          'types_cultures': _culturesCtl.text.trim(),
          'superficie': double.tryParse(_superficieCtl.text.trim()),
        });
        break;
      case 'gestionnaire_entrepot':
        data.addAll({
          'nom_entrepot': _nomEntrepotCtl.text.trim(),
          'localisation': _localisationCtl.text.trim(),
          'capacite': double.tryParse(_capaciteCtl.text.trim()),
        });
        break;
      case 'acheteur_gros':
        data.addAll({
          'type_activite': _typeActiviteCtl.text.trim(),
          'volume_achat_mensuel': double.tryParse(_volumeAchatCtl.text.trim()),
        });
        break;
      case 'transporteur':
        data.addAll({
          'type_vehicule': _typeVehiculeCtl.text.trim(),
          'capacite_charge': double.tryParse(_capaciteChargeCtl.text.trim()),
          'zone': _zoneCtl.text.trim(),
        });
        break;
    }

    final ok = await auth.register(data);
    if (ok && mounted) {
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => const HomeRouter()));
    }
  }

  Widget _champ(TextEditingController ctl, String label, {
    List<String> requisPour = const [],
    TextInputType? type,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextFormField(
      controller: ctl,
      keyboardType: type,
      decoration: InputDecoration(labelText: requisPour.isEmpty ? label : '$label *'),
      validator: (v) => _requisPourRole(v, requisPour),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return Scaffold(
      backgroundColor: AppTheme.vert,
      body: SafeArea(
        child: Column(children: [
          const SizedBox(height: 24),
          const Icon(Icons.agriculture, size: 48, color: Colors.white),
          const Text('SenAgriLink',
              style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
          const SizedBox(height: 16),
          Expanded(
            child: Container(
              decoration: const BoxDecoration(
                color: Color(0xFFF9FAFB),
                borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
              ),
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(24),
                child: Form(
                  key: _formKey,
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    const Text('Créer un compte',
                        style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 16),

                    if (auth.erreur != null)
                      Container(
                        padding: const EdgeInsets.all(12),
                        margin: const EdgeInsets.only(bottom: 12),
                        decoration: BoxDecoration(
                          color: Colors.red.shade50,
                          border: Border.all(color: AppTheme.rouge),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(auth.erreur!, style: const TextStyle(color: AppTheme.rouge)),
                      ),

                    Row(children: [
                      Expanded(child: TextFormField(
                        controller: _prenomCtl,
                        decoration: const InputDecoration(labelText: 'Prénom *'),
                        validator: (v) => (v == null || v.trim().isEmpty) ? 'Requis' : null,
                      )),
                      const SizedBox(width: 12),
                      Expanded(child: TextFormField(
                        controller: _nomCtl,
                        decoration: const InputDecoration(labelText: 'Nom *'),
                        validator: (v) => (v == null || v.trim().isEmpty) ? 'Requis' : null,
                      )),
                    ]),
                    const SizedBox(height: 12),

                    TextFormField(
                      controller: _emailCtl,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(labelText: 'Email *', prefixIcon: Icon(Icons.email_outlined)),
                      validator: (v) => (v == null || !v.contains('@')) ? 'Email invalide' : null,
                    ),
                    const SizedBox(height: 12),

                    TextFormField(
                      controller: _telCtl,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(labelText: 'Téléphone', prefixIcon: Icon(Icons.phone_outlined)),
                    ),
                    const SizedBox(height: 12),

                    DropdownButtonFormField<String>(
                      initialValue: _roleChoisi,
                      decoration: const InputDecoration(labelText: 'Rôle *', prefixIcon: Icon(Icons.badge_outlined)),
                      items: _roles.map((r) => DropdownMenuItem(value: r.$1, child: Text(r.$2))).toList(),
                      onChanged: (v) => setState(() => _roleChoisi = v ?? 'producteur'),
                    ),
                    const SizedBox(height: 12),

                    if (_roleChoisi == 'producteur') ...[
                      _champ(_regionCtl, 'Région', requisPour: const ['producteur']),
                      _champ(_culturesCtl, 'Types de cultures', requisPour: const ['producteur']),
                      _champ(_superficieCtl, 'Superficie exploitée (hectares)',
                          requisPour: const ['producteur'], type: TextInputType.number),
                    ],

                    if (_roleChoisi == 'gestionnaire_entrepot') ...[
                      _champ(_nomEntrepotCtl, 'Nom de l\'entrepôt', requisPour: const ['gestionnaire_entrepot']),
                      _champ(_localisationCtl, 'Localisation', requisPour: const ['gestionnaire_entrepot']),
                      _champ(_capaciteCtl, 'Capacité de stockage (kg)',
                          requisPour: const ['gestionnaire_entrepot'], type: TextInputType.number),
                    ],

                    if (_roleChoisi == 'acheteur_gros') ...[
                      _champ(_typeActiviteCtl, 'Type d\'activité', requisPour: const ['acheteur_gros']),
                      _champ(_volumeAchatCtl, 'Volume d\'achat mensuel (kg)',
                          requisPour: const ['acheteur_gros'], type: TextInputType.number),
                    ],

                    if (_roleChoisi == 'transporteur') ...[
                      _champ(_typeVehiculeCtl, 'Type de véhicule', requisPour: const ['transporteur']),
                      _champ(_capaciteChargeCtl, 'Capacité de charge (kg)',
                          requisPour: const ['transporteur'], type: TextInputType.number),
                      _champ(_zoneCtl, 'Zone de couverture', requisPour: const ['transporteur']),
                    ],

                    TextFormField(
                      controller: _mdpCtl,
                      obscureText: _obscure1,
                      decoration: InputDecoration(
                        labelText: 'Mot de passe *',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(_obscure1 ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                          onPressed: () => setState(() => _obscure1 = !_obscure1),
                        ),
                      ),
                      validator: (v) => (v == null || v.length < 8) ? 'Minimum 8 caractères' : null,
                    ),
                    const SizedBox(height: 12),

                    TextFormField(
                      controller: _mdpConfCtl,
                      obscureText: _obscure2,
                      decoration: InputDecoration(
                        labelText: 'Confirmer le mot de passe *',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(_obscure2 ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                          onPressed: () => setState(() => _obscure2 = !_obscure2),
                        ),
                      ),
                      validator: (v) => (v == null || v.isEmpty) ? 'Requis' : null,
                    ),
                    const SizedBox(height: 24),

                    ElevatedButton(
                      onPressed: auth.loading ? null : _submit,
                      child: auth.loading
                          ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Text('Créer mon compte'),
                    ),
                    const SizedBox(height: 12),

                    TextButton(
                      onPressed: () => Navigator.pop(context),
                      child: const Text('Déjà un compte ? Se connecter'),
                    ),
                  ]),
                ),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}
