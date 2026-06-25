import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';

class ProfilScreen extends StatefulWidget {
  const ProfilScreen({super.key});
  @override
  State<ProfilScreen> createState() => _ProfilScreenState();
}

class _ProfilScreenState extends State<ProfilScreen> {
  final _formKey    = GlobalKey<FormState>();
  final _prenomCtl  = TextEditingController();
  final _nomCtl     = TextEditingController();
  final _emailCtl   = TextEditingController();
  final _telCtl     = TextEditingController();
  final _mdpCtl     = TextEditingController();
  final _mdpConfCtl = TextEditingController();
  bool _obscure1    = true;
  bool _obscure2    = true;
  bool _loading     = false;
  String? _erreur;
  String? _succes;

  static const _rolesLabels = {
    'producteur':            'Producteur',
    'gestionnaire_entrepot': 'Gestionnaire d\'entrepôt',
    'acheteur_gros':         'Acheteur en gros',
    'transporteur':          'Transporteur',
    'administrateur':        'Administrateur',
  };

  @override
  void initState() {
    super.initState();
    final u = context.read<AuthProvider>().utilisateur;
    if (u != null) {
      _prenomCtl.text = u.prenom;
      _nomCtl.text    = u.nom;
      _emailCtl.text  = u.email;
      _telCtl.text    = u.telephone ?? '';
    }
  }

  @override
  void dispose() {
    _prenomCtl.dispose(); _nomCtl.dispose(); _emailCtl.dispose();
    _telCtl.dispose(); _mdpCtl.dispose(); _mdpConfCtl.dispose();
    super.dispose();
  }

  Future<void> _sauvegarder() async {
    if (!_formKey.currentState!.validate()) return;
    final mdp  = _mdpCtl.text;
    final conf = _mdpConfCtl.text;
    if (mdp.isNotEmpty && mdp != conf) {
      setState(() => _erreur = 'Les mots de passe ne correspondent pas.');
      return;
    }

    setState(() { _loading = true; _erreur = null; _succes = null; });

    final data = <String, dynamic>{
      'prenom':    _prenomCtl.text.trim(),
      'nom':       _nomCtl.text.trim(),
      'email':     _emailCtl.text.trim(),
      'telephone': _telCtl.text.trim(),
    };
    if (mdp.isNotEmpty) {
      data['mot_de_passe'] = mdp;
      data['mot_de_passe_confirmation'] = conf;
    }

    try {
      await ApiService.updateProfile(data);
      setState(() { _loading = false; _succes = 'Profil mis à jour avec succès.'; });
      _mdpCtl.clear(); _mdpConfCtl.clear();
    } on ApiException catch (e) {
      setState(() { _loading = false; _erreur = e.message; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final u    = auth.utilisateur;
    final role = _rolesLabels[u?.role] ?? u?.role ?? '';

    return Scaffold(
      appBar: AppBar(title: const Text('Mon profil')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

          // Info compte
          Card(child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: AppTheme.vertClair,
                child: Text(
                  u != null ? u.prenom[0].toUpperCase() : '?',
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AppTheme.vert),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${u?.prenom ?? ''} ${u?.nom ?? ''}',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                Text(u?.email ?? '', style: const TextStyle(color: Colors.grey, fontSize: 13)),
                const SizedBox(height: 4),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(color: AppTheme.vertClair, borderRadius: BorderRadius.circular(10)),
                  child: Text(role, style: const TextStyle(color: AppTheme.vert, fontSize: 12, fontWeight: FontWeight.w600)),
                ),
              ])),
            ]),
          )),

          const SizedBox(height: 16),

          if (_erreur != null)
            Container(
              padding: const EdgeInsets.all(12), margin: const EdgeInsets.only(bottom: 12),
              decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(8), border: Border.all(color: AppTheme.rouge)),
              child: Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
            ),
          if (_succes != null)
            Container(
              padding: const EdgeInsets.all(12), margin: const EdgeInsets.only(bottom: 12),
              decoration: BoxDecoration(color: Colors.green.shade50, borderRadius: BorderRadius.circular(8), border: Border.all(color: AppTheme.vert)),
              child: Text(_succes!, style: const TextStyle(color: AppTheme.vert)),
            ),

          // Formulaire
          Card(child: Padding(
            padding: const EdgeInsets.all(16),
            child: Form(
              key: _formKey,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('Modifier mes informations',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                const SizedBox(height: 16),

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
                const SizedBox(height: 16),

                const Divider(),
                const Text('Changer le mot de passe (laisser vide pour ne pas modifier)',
                    style: TextStyle(fontSize: 13, color: Colors.grey)),
                const SizedBox(height: 12),

                TextFormField(
                  controller: _mdpCtl,
                  obscureText: _obscure1,
                  decoration: InputDecoration(
                    labelText: 'Nouveau mot de passe',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(_obscure1 ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                      onPressed: () => setState(() => _obscure1 = !_obscure1),
                    ),
                  ),
                ),
                const SizedBox(height: 12),

                TextFormField(
                  controller: _mdpConfCtl,
                  obscureText: _obscure2,
                  decoration: InputDecoration(
                    labelText: 'Confirmer le mot de passe',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(_obscure2 ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                      onPressed: () => setState(() => _obscure2 = !_obscure2),
                    ),
                  ),
                ),
                const SizedBox(height: 20),

                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: _loading ? null : _sauvegarder,
                    child: _loading
                        ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Text('Enregistrer les modifications'),
                  ),
                ),
              ]),
            ),
          )),
        ]),
      ),
    );
  }
}
