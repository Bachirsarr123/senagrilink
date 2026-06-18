import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';

class NouvelleRecolteScreen extends StatefulWidget {
  const NouvelleRecolteScreen({super.key});
  @override
  State<NouvelleRecolteScreen> createState() => _NouvelleRecolteScreenState();
}

class _NouvelleRecolteScreenState extends State<NouvelleRecolteScreen> {
  final _formKey        = GlobalKey<FormState>();
  final _cultureCtl     = TextEditingController();
  final _superficieCtl  = TextEditingController();
  final _qteEstimeeCtl  = TextEditingController();
  final _qteReelleCtl   = TextEditingController();
  DateTime? _dateRecolte;
  bool _loading = false;
  String? _erreur;

  @override
  void dispose() {
    _cultureCtl.dispose(); _superficieCtl.dispose();
    _qteEstimeeCtl.dispose(); _qteReelleCtl.dispose();
    super.dispose();
  }

  Future<void> _choisirDate() async {
    final d = await showDatePicker(
      context: context,
      initialDate: DateTime.now(),
      firstDate: DateTime(2020),
      lastDate: DateTime.now(),
    );
    if (d != null) setState(() => _dateRecolte = d);
  }

  Future<void> _soumettre() async {
    if (!_formKey.currentState!.validate()) return;
    if (_dateRecolte == null) {
      setState(() => _erreur = 'Veuillez sélectionner une date de récolte.');
      return;
    }
    setState(() { _loading = true; _erreur = null; });
    try {
      await ApiService.storeProduction({
        'type_culture':     _cultureCtl.text.trim(),
        'superficie':       double.tryParse(_superficieCtl.text),
        'date_recolte':     '${_dateRecolte!.year}-${_dateRecolte!.month.toString().padLeft(2,'0')}-${_dateRecolte!.day.toString().padLeft(2,'0')}',
        'quantite_estimee': double.parse(_qteEstimeeCtl.text),
        'quantite_reelle':  _qteReelleCtl.text.isEmpty ? null : double.tryParse(_qteReelleCtl.text),
      });
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Enregistrer une récolte')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Form(
          key: _formKey,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (_erreur != null)
              Container(
                padding: const EdgeInsets.all(12),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: Colors.red.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AppTheme.rouge),
                ),
                child: Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
              ),
            TextFormField(
              controller: _cultureCtl,
              decoration: const InputDecoration(labelText: 'Type de culture *', hintText: 'Mil, Arachide, Maïs...'),
              validator: (v) => (v == null || v.isEmpty) ? 'Champ requis' : null,
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _superficieCtl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Superficie (ha)', suffixText: 'ha'),
            ),
            const SizedBox(height: 14),
            // Sélecteur de date
            InkWell(
              onTap: _choisirDate,
              child: InputDecorator(
                decoration: InputDecoration(
                  labelText: 'Date de récolte *',
                  suffixIcon: const Icon(Icons.calendar_today),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                ),
                child: Text(
                  _dateRecolte == null
                      ? 'Appuyer pour choisir'
                      : '${_dateRecolte!.day}/${_dateRecolte!.month}/${_dateRecolte!.year}',
                  style: TextStyle(color: _dateRecolte == null ? Colors.grey : Colors.black87),
                ),
              ),
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _qteEstimeeCtl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Quantité estimée (kg) *', suffixText: 'kg'),
              validator: (v) {
                if (v == null || v.isEmpty) return 'Champ requis';
                if (double.tryParse(v) == null) return 'Nombre invalide';
                return null;
              },
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _qteReelleCtl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Quantité réelle (kg)', suffixText: 'kg'),
            ),
            const SizedBox(height: 28),
            ElevatedButton(
              onPressed: _loading ? null : _soumettre,
              child: _loading
                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Text('Enregistrer la récolte'),
            ),
          ]),
        ),
      ),
    );
  }
}
