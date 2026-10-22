import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_tts/flutter_tts.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../services/api_service.dart';
import '../../services/position_appareil.dart';
import '../../services/suivi_gps_service.dart';
import '../../utils/action_directe.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/declarer_incident.dart';

/// Écran « Conduite » (lot 9), comme Yango : la carte suit le véhicule, le
/// chemin est tracé par la route, la prochaine consigne est affichée en gros
/// (et lue à voix haute si la voix est activée), et un seul gros bouton fait
/// l'étape suivante. Tout reste dans SenAgriLink.
class ConduiteScreen extends StatefulWidget {
  final String type; // 'livraison' ou 'reservation'
  final int id;
  final String numero;
  const ConduiteScreen({super.key, required this.type, required this.id, required this.numero});

  /// Remplaçables dans les tests (pas de GPS ni de synthèse vocale).
  static Stream<LatLng> Function()? sourcePositions;
  static Future<void> Function(String texte)? parler;
  /// Délai pour annuler un appui par erreur sur le gros bouton.
  static Duration delaiAnnulation = const Duration(seconds: 4);

  @override
  State<ConduiteScreen> createState() => _ConduiteScreenState();
}

/// Actions de conduite proposées sur le gros bouton, dans l'ordre de priorité.
const _actionsConduite = ['accepter_mission', 'arriver_collecte', 'charger', 'partir', 'arriver'];

class _ConduiteScreenState extends State<ConduiteScreen> {
  final _carte = MapController();
  StreamSubscription<LatLng>? _abonnement;
  FlutterTts? _tts;
  Map<String, dynamic>? _nav;
  Map<String, dynamic> _fond = const {};
  LatLng? _moi;
  LatLng? _dernierCalcul;
  DateTime _dernierAppel = DateTime.fromMillisecondsSinceEpoch(0);
  bool _chargement = true;
  bool _action = false;
  bool _voix = true;
  bool _suivreAuto = true;
  bool _carteprete = false;
  String? _erreur;
  String? _derniereConsigneDite;
  bool _prochePrevenu = false;

  @override
  void initState() {
    super.initState();
    SharedPreferences.getInstance().then((p) { if (mounted) setState(() => _voix = p.getBool('conduite_voix') ?? true); });
    if (PositionAppareil.tuiles) PositionAppareil.fondDeCarte().then((f) { if (mounted) setState(() => _fond = f); });
    _abonnement = (ConduiteScreen.sourcePositions ?? _fluxAppareil)().listen(_surPosition, onError: (_) {});
  }

  @override
  void dispose() {
    _abonnement?.cancel();
    _tts?.stop();
    super.dispose();
  }

  Stream<LatLng> _fluxAppareil() async* {
    final premiere = await PositionAppareil.lire();
    if (premiere != null) yield premiere;
    yield* Geolocator.getPositionStream(locationSettings: const LocationSettings(accuracy: LocationAccuracy.bestForNavigation, distanceFilter: 10))
        .map((p) => LatLng(p.latitude, p.longitude));
  }

  // ── Position et recalcul ──────────────────────────────────────────────────

  void _surPosition(LatLng p) {
    final premier = _moi == null;
    setState(() => _moi = p);
    if (_suivreAuto && _carteprete) _carte.move(p, math.max(_carte.camera.zoom, 16));
    final depuis = DateTime.now().difference(_dernierAppel);
    final deplace = _dernierCalcul == null ? double.infinity : const Distance().as(LengthUnit.Meter, _dernierCalcul!, p);
    if (premier || (depuis.inSeconds >= 8 && (deplace > 60 || depuis.inSeconds >= 30 || _horsTrajet(p)))) _calculer();
    _annoncer();
  }

  Future<void> _calculer() async {
    final p = _moi;
    if (p == null) return;
    _dernierAppel = DateTime.now();
    _dernierCalcul = p;
    try {
      final res = (await ApiService.getNavigation(widget.type, widget.id, p.latitude, p.longitude) as Map).cast<String, dynamic>();
      if (!mounted) return;
      setState(() { _nav = res; _chargement = false; _erreur = null; });
      _annoncer();
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.statusCode == 422 && e.donnees?['vers'] == null) {
        // Plus rien à rejoindre à cette étape : retour à la mission
        Navigator.pop(context, true);
        return;
      }
      setState(() { _erreur = e.message; _chargement = false; });
    }
  }

  List<LatLng> get _points => [
        for (final c in ((_nav?['itineraire']?['points'] as List?) ?? const []))
          LatLng(nombre((c as List)[0])!, nombre(c[1])!),
      ];

  /// Indice du point du trajet le plus proche de la position actuelle.
  int _indexProche(LatLng p, List<LatLng> pts) {
    var meilleur = 0;
    var dMin = double.infinity;
    for (var i = 0; i < pts.length; i++) {
      final d = const Distance().as(LengthUnit.Meter, p, pts[i]);
      if (d < dMin) { dMin = d; meilleur = i; }
    }
    return meilleur;
  }

  bool _horsTrajet(LatLng p) {
    final pts = _points;
    if (pts.length < 3) return false;
    return const Distance().as(LengthUnit.Meter, p, pts[_indexProche(p, pts)]) > 80;
  }

  /// Prochaine consigne : (étape, distance jusqu'à elle en mètres).
  (Map<String, dynamic>, double)? _prochaine() {
    final etapes = ((_nav?['itineraire']?['etapes'] as List?) ?? const []).cast<Map>().map((e) => e.cast<String, dynamic>()).toList();
    final pts = _points;
    final p = _moi;
    if (etapes.isEmpty || pts.isEmpty || p == null) return null;
    final i = _indexProche(p, pts);
    final enCours = etapes.indexWhere((e) => (nombre(e['fin']) ?? 0) > i);
    if (enCours == -1) return (etapes.last, const Distance().as(LengthUnit.Meter, p, pts.last));
    final finEtape = pts[math.min((nombre(etapes[enCours]['fin']) ?? 0).toInt(), pts.length - 1)];
    final distance = const Distance().as(LengthUnit.Meter, p, finEtape);
    // Ligne droite (une seule consigne) : on garde la consigne de direction
    if (etapes.length == 1 || enCours + 1 >= etapes.length) return (etapes[math.min(enCours + 1, etapes.length - 1)], distance);
    return (etapes[enCours + 1], distance);
  }

  // ── Voix ──────────────────────────────────────────────────────────────────

  Future<void> _dire(String texte) async {
    if (!_voix) return;
    if (ConduiteScreen.parler != null) return ConduiteScreen.parler!(texte);
    try {
      _tts ??= FlutterTts()..setLanguage('fr-FR');
      await _tts!.speak(texte);
    } catch (_) {/* synthèse vocale indisponible : l'écran suffit */}
  }

  void _annoncer() {
    final suivante = _prochaine();
    if (suivante != null) {
      final (etape, distance) = suivante;
      final consigne = etape['instruction'] as String? ?? '';
      final cle = '$consigne|${distance < 150 ? 'proche' : 'loin'}';
      if (cle != _derniereConsigneDite && consigne.isNotEmpty) {
        _derniereConsigneDite = cle;
        _dire(distance < 30 ? consigne : 'Dans ${_distance(distance)}, $consigne');
      }
    }
    if (_nav?['proche'] == true && !_prochePrevenu) {
      _prochePrevenu = true;
      _dire('Vous êtes arrivé. Touchez le bouton vert pour confirmer.');
    }
  }

  Future<void> _basculerVoix() async {
    setState(() => _voix = !_voix);
    (await SharedPreferences.getInstance()).setBool('conduite_voix', _voix);
    if (!_voix) await _tts?.stop();
  }

  // ── Gros bouton ───────────────────────────────────────────────────────────

  String? get _actionPrincipale {
    final actions = ((_nav?['actions'] as List?) ?? const []).cast<String>();
    for (final a in _actionsConduite) {
      if (actions.contains(a)) return a;
    }
    return null;
  }

  Future<void> _agir(String action) async {
    final messager = ScaffoldMessenger.of(context);
    final libelle = libelleActionDirecte(action, sujetType: widget.type);
    // Appui par erreur : quelques secondes pour annuler, sans fenêtre à confirmer
    final annule = await messager.showSnackBar(SnackBar(
      content: Text('« $libelle »…'),
      duration: ConduiteScreen.delaiAnnulation,
      persist: false, // se ferme seul : l'action part si personne n'annule
      action: SnackBarAction(label: 'ANNULER', onPressed: () {}),
    )).closed;
    if (annule == SnackBarClosedReason.action || !mounted) return;
    setState(() => _action = true);
    try {
      final message = await executerActionDirecte(widget.type, widget.id, action);
      if (demarrePartage(action)) await SuiviGpsService.instance.demarrer((type: widget.type, id: widget.id, numero: widget.numero));
      if (arretePartage(action) && SuiviGpsService.instance.suit(widget.type, widget.id)) await SuiviGpsService.instance.arreter();
      messager.showSnackBar(SnackBar(content: Text(message), backgroundColor: AppTheme.vert));
      _prochePrevenu = false;
      _derniereConsigneDite = null;
      await _calculer();
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    } finally {
      if (mounted) setState(() => _action = false);
    }
  }

  // ── Affichage ─────────────────────────────────────────────────────────────

  static String _distance(double m) => m >= 1000 ? '${nombreLisible(((m / 100).round()) / 10)} km' : '${((m / 10).round() * 10)} mètres';

  static IconData _fleche(String? type) => switch (type) {
        'gauche' || 'gauche_serre' => Icons.turn_left,
        'droite' || 'droite_serre' => Icons.turn_right,
        'gauche_leger' => Icons.turn_slight_left,
        'droite_leger' => Icons.turn_slight_right,
        'rond_point' => Icons.roundabout_right,
        'demi_tour' => Icons.u_turn_left,
        'arrivee' => Icons.flag,
        _ => Icons.straight,
      };

  @override
  Widget build(BuildContext context) {
    final cible = (_nav?['cible'] as Map?)?.cast<String, dynamic>();
    final pointCible = nombre(cible?['latitude']) != null && nombre(cible?['longitude']) != null
        ? LatLng(nombre(cible!['latitude'])!, nombre(cible['longitude'])!) : null;
    final it = (_nav?['itineraire'] as Map?)?.cast<String, dynamic>();
    final suivante = _prochaine();
    final action = _actionPrincipale;
    final proche = _nav?['proche'] == true;
    final versLeProducteur = _nav?['vers'] == 'origine';

    return Scaffold(
      appBar: AppBar(
        title: Text(versLeProducteur ? 'Aller chercher la marchandise' : 'Livrer la marchandise'),
        actions: [
          IconButton(
            key: const Key('conduite-voix'),
            tooltip: _voix ? 'Couper la voix' : 'Activer la voix',
            icon: Icon(_voix ? Icons.volume_up : Icons.volume_off),
            onPressed: _basculerVoix,
          ),
        ],
      ),
      body: Stack(children: [
        FlutterMap(
          mapController: _carte,
          options: MapOptions(
            initialCenter: _moi ?? pointCible ?? const LatLng(14.69, -17.44),
            initialZoom: 15,
            onMapReady: () => _carteprete = true,
            onPositionChanged: (_, geste) { if (geste && _suivreAuto) setState(() => _suivreAuto = false); },
          ),
          children: [
            if (PositionAppareil.tuiles)
              TileLayer(urlTemplate: _fond['tuiles_url'] as String? ?? 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', userAgentPackageName: 'com.agriplatform.agri_platform_mobile'),
            if (_points.length > 1)
              PolylineLayer(polylines: [Polyline(points: _points, strokeWidth: 7, color: const Color(0xFF1565C0),
                  pattern: it?['routier'] == false ? StrokePattern.dashed(segments: const [12, 8]) : const StrokePattern.solid())]),
            MarkerLayer(markers: [
              if (pointCible != null) Marker(point: pointCible, width: 44, height: 44, child: const Icon(Icons.location_on, color: AppTheme.rouge, size: 44)),
              if (_moi != null) Marker(point: _moi!, width: 34, height: 34, child: Container(
                decoration: BoxDecoration(color: const Color(0xFF1565C0), shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3)),
                child: const Icon(Icons.navigation, color: Colors.white, size: 18))),
            ]),
          ],
        ),
        // Consigne en gros, en haut
        Positioned(top: 8, left: 8, right: 8, child: Card(
          color: const Color(0xFF0D47A1),
          child: Padding(padding: const EdgeInsets.all(12), child: _chargement
              ? const Text('Recherche de votre position…', style: TextStyle(color: Colors.white, fontSize: 18))
              : _erreur != null
                  ? Text(_erreur!, style: const TextStyle(color: Colors.white, fontSize: 16))
                  : it == null
                      ? Text(_nav?['message'] as String? ?? 'Trajet indisponible', key: const Key('conduite-message'), style: const TextStyle(color: Colors.white, fontSize: 16))
                      : Row(children: [
                          Icon(_fleche(suivante?.$1['type'] as String?), color: Colors.white, size: 44),
                          const SizedBox(width: 12),
                          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            if (suivante != null && suivante.$2 >= 30)
                              Text('Dans ${_distance(suivante.$2)}', style: const TextStyle(color: Colors.white70, fontSize: 16)),
                            Text(suivante?.$1['instruction'] as String? ?? '', key: const Key('conduite-consigne'),
                                style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold)),
                          ])),
                        ])),
        )),
        if (!_suivreAuto && _moi != null)
          Positioned(right: 12, bottom: 210, child: FloatingActionButton.small(
            heroTag: 'recentrer', tooltip: 'Recentrer',
            onPressed: () { setState(() => _suivreAuto = true); _carte.move(_moi!, 16); },
            child: const Icon(Icons.my_location))),
      ]),
      // Destination, heure d'arrivée et gros bouton, en bas
      bottomNavigationBar: SafeArea(child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (cible != null) Text('${versLeProducteur ? 'Prise en charge' : 'Destination'} : ${cible['nom'] ?? ''}'
              '${(cible['adresse'] as String?)?.isNotEmpty == true ? ' — ${cible['adresse']}' : ''}',
              style: const TextStyle(fontWeight: FontWeight.w600), maxLines: 2, overflow: TextOverflow.ellipsis),
          if (it != null) Text(_resume(it), key: const Key('conduite-arrivee'), style: const TextStyle(color: Colors.grey)),
          if (proche) Padding(padding: const EdgeInsets.only(top: 6), child: Text('📍 Vous êtes arrivé ?', key: const Key('conduite-proche'),
              style: TextStyle(color: AppTheme.vertFonce, fontWeight: FontWeight.bold, fontSize: 16))),
          const SizedBox(height: 8),
          if (action != null) SizedBox(height: 64, child: ElevatedButton.icon(
            key: const Key('conduite-action'),
            style: ElevatedButton.styleFrom(backgroundColor: proche ? AppTheme.vertFonce : AppTheme.vert, textStyle: const TextStyle(fontSize: 19, fontWeight: FontWeight.bold)),
            icon: Icon(action == 'partir' ? Icons.play_arrow : Icons.check_circle, size: 28),
            label: Text(_action ? 'Envoi…' : libelleActionDirecte(action, sujetType: widget.type)),
            onPressed: _action ? null : () => _agir(action),
          )),
          TextButton.icon(
            icon: const Icon(Icons.report_problem_outlined, size: 18),
            label: const Text('Signaler un problème'),
            onPressed: () => declarerIncident(context, sujetType: widget.type, sujetId: widget.id, numero: widget.numero),
          ),
        ]),
      )),
    );
  }

  String _resume(Map<String, dynamic> it) {
    final secondes = nombre(it['secondes']) ?? 0;
    final arrivee = DateTime.tryParse(it['arrivee_vers'] as String? ?? '')?.toLocal();
    final heure = arrivee != null ? '${arrivee.hour.toString().padLeft(2, '0')}:${arrivee.minute.toString().padLeft(2, '0')}' : '—';
    return 'Arrivée vers $heure · ${(secondes / 60).ceil()} min · ${nombreLisible(nombre(it['km']) ?? 0)} km';
  }
}
