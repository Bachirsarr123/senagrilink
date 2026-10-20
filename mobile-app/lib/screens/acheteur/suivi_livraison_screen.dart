import 'dart:async';
import 'dart:math' show Point;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import '../../services/api_service.dart';
import '../../utils/geo.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../shared/tracabilite_screen.dart';

/// Le transporteur envoie sa position toutes les 10 s.
const delaiSuivi = Duration(seconds: 10);
/// Livraison pas encore partie, ou réseau en échec : on revérifie moins souvent.
const delaiAttente = Duration(seconds: 30);

enum EtatSuivi { chargement, direct, sansPosition, pasPartie, terminee, probleme, refuse, introuvable }

/// Suivi d'une livraison : carte plein écran (position du transporteur, départ,
/// arrivée) et panneau d'informations. Aucune position n'est inventée : une
/// position ancienne est signalée comme telle, et hors livraison en cours le
/// serveur ne la transmet plus.
class SuiviLivraisonScreen extends StatefulWidget {
  final int livraisonId;
  const SuiviLivraisonScreen({super.key, required this.livraisonId});

  @override
  State<SuiviLivraisonScreen> createState() => _SuiviLivraisonScreenState();
}

class _SuiviLivraisonScreenState extends State<SuiviLivraisonScreen> with WidgetsBindingObserver {
  MapController _mapController = MapController();
  Timer? _minuteur;
  Timer? _horloge;

  EtatSuivi _etat = EtatSuivi.chargement;
  String? _statut;
  Map<String, dynamic>? _infos;
  Map<String, dynamic>? _origine;
  Map<String, dynamic>? _destination;
  ({double lat, double lng})? _vehicule;
  DateTime? _horodatage;
  Duration _decalageHorloge = Duration.zero;
  bool _erreurReseau = false;
  // Notifier : signaler une tuile en échec sans reconstruire la carte (chargements en cours préservés)
  final _tuilesIndisponibles = ValueNotifier(false);
  bool _suiviAuto = true;
  // Le MapController n'est utilisable qu'une fois la carte dessinée : avant,
  // move() lève une exception (affichée à tort comme « position indisponible »).
  bool _cartePrete = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _rafraichir();
    _horloge = Timer.periodic(const Duration(seconds: 15), (_) { if (mounted) setState(() {}); });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _minuteur?.cancel();
    _horloge?.cancel();
    _tuilesIndisponibles.dispose();
    super.dispose();
  }

  /// Application en arrière-plan : plus de requêtes (batterie, données).
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _rafraichir();
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      _minuteur?.cancel();
    }
  }

  void _planifier(Duration delai) {
    _minuteur?.cancel();
    if (mounted) _minuteur = Timer(delai, _rafraichir);
  }

  Future<void> _rafraichir() async {
    _minuteur?.cancel();
    try {
      final res = await ApiService.getPositionLivraison(widget.livraisonId) as Map<String, dynamic>;
      if (!mounted) return;
      final serveur = lireDate(res['genere_le']);
      final vehicule = coordonnees(res['position']);
      setState(() {
        _decalageHorloge = serveur == null ? Duration.zero : DateTime.now().difference(serveur);
        _erreurReseau = false;
        _statut = res['statut_livraison'] as String?;
        _infos = (res['livraison'] as Map?)?.cast<String, dynamic>();
        _origine = (res['origine'] as Map?)?.cast<String, dynamic>();
        _destination = (res['destination'] as Map?)?.cast<String, dynamic>();
        _vehicule = vehicule;
        _horodatage = lireDate((res['position'] as Map?)?['timestamp']);
        _etat = vehicule != null ? EtatSuivi.direct : EtatSuivi.sansPosition;
      });
      if (vehicule != null && _cartePrete && _suiviAuto) {
        _mapController.move(LatLng(vehicule.lat, vehicule.lng), _mapController.camera.zoom);
      }
      _planifier(delaiSuivi);
    } on ApiException catch (e) {
      if (!mounted) return;
      _surErreur(e);
    } catch (_) {
      // Réseau coupé ou réponse illisible : la dernière position reste visible, signalée comme ancienne.
      if (!mounted) return;
      setState(() {
        _erreurReseau = true;
        if (_etat == EtatSuivi.chargement) _etat = EtatSuivi.sansPosition;
      });
      _planifier(delaiAttente);
    }
  }

  void _surErreur(ApiException e) {
    final statut = e.donnees?['statut_livraison'] as String?;
    // Réseau coupé ou délai dépassé (ApiException sans code) et erreurs 5xx : la
    // dernière position reste visible, signalée comme ancienne.
    if (e.statusCode == null || e.statusCode! >= 500) {
      setState(() { _erreurReseau = true; if (_etat == EtatSuivi.chargement) _etat = EtatSuivi.sansPosition; });
      return _planifier(delaiAttente);
    }
    setState(() {
      _erreurReseau = false;
      if (e.donnees?['livraison'] is Map) _infos = (e.donnees!['livraison'] as Map).cast<String, dynamic>();
      if (statut != null) _statut = statut;
      // Hors livraison en cours, aucune position n'est exposée : on n'affiche plus l'ancienne.
      if (_vehicule != null) {
        // La carte disparaît : un nouveau contrôleur servira si elle réapparaît.
        _mapController = MapController();
        _cartePrete = false;
      }
      _vehicule = null;
      _horodatage = null;
      _etat = switch ((e.statusCode, statut)) {
        (403, _) => EtatSuivi.refuse,
        (_, 'livree') => EtatSuivi.terminee,
        (_, 'probleme') => EtatSuivi.probleme,
        (_, 'en_attente') => EtatSuivi.pasPartie,
        (_, 'en_cours') => EtatSuivi.sansPosition,
        _ => EtatSuivi.introuvable,
      };
    });
    if (_etat == EtatSuivi.pasPartie) _planifier(delaiAttente);
    if (_etat == EtatSuivi.sansPosition) _planifier(delaiSuivi);
  }

  Duration? get _age {
    if (_horodatage == null) return null;
    final a = DateTime.now().subtract(_decalageHorloge).difference(_horodatage!);
    return a.isNegative ? Duration.zero : a;
  }

  bool get _positionDirecte =>
      _etat == EtatSuivi.direct && !_erreurReseau && _age != null && _age! <= seuilPositionDirecte;

  List<LatLng> get _tousLesPoints => [
        for (final p in [_vehicule, coordonnees(_origine), coordonnees(_destination)])
          if (p != null) LatLng(p.lat, p.lng),
      ];

  void _vueEnsemble() {
    setState(() => _suiviAuto = false);
    final pts = _tousLesPoints;
    if (!_cartePrete || pts.isEmpty) return;
    if (pts.length == 1) {
      _mapController.move(pts.first, 14);
      return;
    }
    _mapController.fitCamera(CameraFit.coordinates(coordinates: pts, padding: const EdgeInsets.all(64), maxZoom: 15));
  }

  void _centrerVehicule() {
    setState(() => _suiviAuto = true);
    if (_cartePrete && _vehicule != null) {
      final z = _mapController.camera.zoom;
      _mapController.move(LatLng(_vehicule!.lat, _vehicule!.lng), z < 15 ? 15 : z);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFE8EFE9),
      body: LayoutBuilder(builder: (context, c) {
        // Tablette ou téléphone en paysage : panneau latéral ; sinon panneau inférieur.
        final lateral = c.maxWidth >= 640 && c.maxWidth > c.maxHeight;
        final carte = _zoneCarte();
        if (lateral) {
          return Row(children: [
            Expanded(child: carte),
            SizedBox(width: c.maxWidth >= 900 ? 380 : 320, child: SafeArea(left: false, child: _panneau(lateral: true))),
          ]);
        }
        return Stack(children: [
          Positioned.fill(child: carte),
          Align(
            alignment: Alignment.bottomCenter,
            child: ConstrainedBox(
              constraints: BoxConstraints(maxHeight: c.maxHeight * (_vehicule != null ? .46 : .62)),
              child: _panneau(lateral: false),
            ),
          ),
        ]);
      }),
    );
  }

  Widget _zoneCarte() {
    final vehicule = _vehicule;
    return Stack(children: [
      if (vehicule != null) Positioned.fill(child: _carte(vehicule)) else Positioned.fill(child: _etatSansCarte()),
      // Boutons flottants : retour à gauche, cadrage à droite
      SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            _boutonRond(Icons.arrow_back, 'Retour', () => Navigator.of(context).maybePop()),
            const SizedBox(width: 8),
            if (vehicule != null) Flexible(child: _puceFraicheur()),
            const Spacer(),
            if (vehicule != null)
              Column(children: [
                _boutonRond(Icons.zoom_out_map, 'Vue d’ensemble du trajet', _vueEnsemble),
                const SizedBox(height: 8),
                _boutonRond(Icons.navigation, 'Centrer sur le transporteur', _centrerVehicule, actif: _suiviAuto),
              ]),
          ]),
        ),
      ),
      if (vehicule != null)
        ValueListenableBuilder<bool>(
          valueListenable: _tuilesIndisponibles,
          builder: (_, ko, _) => !ko ? const SizedBox.shrink() : const SafeArea(
            child: Align(
              alignment: Alignment(0, -.62),
              child: Card(child: Padding(
                padding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                child: Text('Fond de carte indisponible : les positions restent affichées.',
                    style: TextStyle(fontSize: 13, color: Color(0xFF92400E))),
              )),
            ),
          ),
        ),
    ]);
  }

  /// Cadrage calculé avant le premier rendu, à la taille réelle de la carte :
  /// les tuiles sont chargées directement au bon zoom (un recadrage après coup
  /// abandonnerait les tuiles déjà demandées).
  ({LatLng centre, double zoom}) _cadrageInitial(List<LatLng> pts, Size taille, LatLng repli) {
    if (pts.length < 2 || taille.isEmpty || !taille.isFinite) return (centre: repli, zoom: 13);
    final camera = MapCamera(
      crs: const Epsg3857(), center: repli, zoom: 13, rotation: 0,
      nonRotatedSize: Point(taille.width, taille.height),
    );
    final cadre = CameraFit.coordinates(
      coordinates: pts, padding: const EdgeInsets.fromLTRB(56, 120, 56, 280), maxZoom: 15,
    ).fit(camera);
    return (centre: cadre.center, zoom: cadre.zoom);
  }

  Widget _carte(({double lat, double lng}) v) => LayoutBuilder(builder: (context, c) => _carteDimensionnee(v, c.biggest));

  Widget _carteDimensionnee(({double lat, double lng}) v, Size taille) {
    final vehicule = LatLng(v.lat, v.lng);
    final o = coordonnees(_origine), d = coordonnees(_destination);
    final origine = o == null ? null : LatLng(o.lat, o.lng);
    final destination = d == null ? null : LatLng(d.lat, d.lng);
    final cadrage = _cadrageInitial(_tousLesPoints, taille, vehicule);
    return FlutterMap(
      mapController: _mapController,
      options: MapOptions(
        initialCenter: cadrage.centre,
        initialZoom: cadrage.zoom,
        onMapReady: () => _cartePrete = true,
        onPositionChanged: (_, geste) { if (geste && _suiviAuto) setState(() => _suiviAuto = false); },
      ),
      children: [
        TileLayer(
          urlTemplate: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
          userAgentPackageName: 'com.senagrilink.agri_platform_mobile',
          errorTileCallback: (_, _, _) {
            if (mounted) _tuilesIndisponibles.value = true;
          },
        ),
        // Tracé indicatif à vol d'oiseau (aucun calcul d'itinéraire routier n'est disponible)
        PolylineLayer(polylines: [
          if (origine != null)
            Polyline(points: [origine, vehicule], color: const Color(0xFF40916C), strokeWidth: 6,
                borderStrokeWidth: 2, borderColor: AppTheme.vertFonce.withAlpha(90)),
          if (destination != null)
            Polyline(points: [vehicule, destination], color: AppTheme.vert, strokeWidth: 4,
                pattern: const StrokePattern.dotted(spacingFactor: 2)),
        ]),
        MarkerLayer(markers: [
          if (origine != null)
            Marker(point: origine, width: 22, height: 22, child: Semantics(label: 'Départ : entrepôt', child: Container(
              decoration: BoxDecoration(color: Colors.white, shape: BoxShape.circle,
                  border: Border.all(color: AppTheme.vertFonce, width: 5), boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 4)]),
            ))),
          if (destination != null)
            Marker(point: destination, width: 40, height: 40, child: Semantics(label: 'Arrivée : adresse de livraison', child: Container(
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10),
                  boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 6)]),
              child: const Icon(Icons.flag, color: AppTheme.vertFonce, size: 22),
            ))),
          Marker(point: vehicule, width: 46, height: 46, child: Semantics(label: 'Dernière position du transporteur', child: Container(
            decoration: BoxDecoration(color: AppTheme.vert, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3),
                boxShadow: const [BoxShadow(color: Colors.black38, blurRadius: 8)]),
            child: const Icon(Icons.local_shipping, color: Colors.white, size: 22),
          ))),
        ]),
      ],
    );
  }

  Widget _etatSansCarte() {
    final (icone, message) = _messageEtat;
    return SafeArea(
      child: Center(child: Padding(
        padding: const EdgeInsets.fromLTRB(32, 72, 32, 200),
        child: _etat == EtatSuivi.chargement
            ? const Column(mainAxisSize: MainAxisSize.min, children: [
                CircularProgressIndicator(), SizedBox(height: 12), Text('Chargement du suivi…'),
              ])
            : Column(mainAxisSize: MainAxisSize.min, children: [
                Icon(icone, size: 48, color: AppTheme.vert),
                const SizedBox(height: 12),
                Text(message, textAlign: TextAlign.center, style: const TextStyle(fontSize: 15, height: 1.4)),
              ]),
      )),
    );
  }

  (IconData, String) get _messageEtat => switch (_etat) {
        EtatSuivi.sansPosition => (Icons.satellite_alt_outlined, 'Le transporteur n’a pas encore transmis sa position. La carte s’affichera dès sa réception.'),
        EtatSuivi.pasPartie => (Icons.schedule, 'Le suivi en direct démarrera au départ du transporteur. Cet écran se met à jour automatiquement.'),
        EtatSuivi.terminee => (Icons.check_circle_outline, 'La livraison est terminée : la position du transporteur n’est plus partagée.'),
        EtatSuivi.probleme => (Icons.warning_amber_rounded, 'Le transporteur a signalé un problème : le suivi en direct est suspendu.'),
        EtatSuivi.refuse => (Icons.lock_outline, 'Vous n’avez pas accès au suivi de cette livraison.'),
        EtatSuivi.introuvable => (Icons.search_off, 'Cette livraison n’existe pas ou n’est plus disponible.'),
        _ => (Icons.location_on_outlined, ''),
      };

  String get _titreEtat => switch (_etat) {
        EtatSuivi.sansPosition => 'En attente de position',
        EtatSuivi.pasPartie => 'Livraison pas encore partie',
        EtatSuivi.terminee => 'Livraison effectuée',
        EtatSuivi.probleme => 'Problème signalé',
        EtatSuivi.refuse => 'Accès refusé',
        EtatSuivi.introuvable => 'Livraison introuvable',
        _ => '',
      };

  Widget _boutonRond(IconData icone, String libelle, VoidCallback action, {bool actif = false}) => Material(
        color: actif ? AppTheme.vert : Colors.white,
        shape: const CircleBorder(),
        elevation: 3,
        child: IconButton(
          tooltip: libelle,
          icon: Icon(icone, color: actif ? Colors.white : AppTheme.vertFonce),
          constraints: const BoxConstraints.tightFor(width: 48, height: 48),
          onPressed: action,
        ),
      );

  Widget _puceFraicheur() {
    final direct = _positionDirecte;
    final age = _age;
    return Material(
      color: Colors.white,
      elevation: 3,
      borderRadius: BorderRadius.circular(24),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Container(width: 9, height: 9, decoration: BoxDecoration(
            color: direct ? const Color(0xFF22C55E) : AppTheme.orange, shape: BoxShape.circle)),
          const SizedBox(width: 7),
          Flexible(child: Text(
            '${direct ? 'En direct' : 'Dernière position connue'} · ${age == null ? 'heure inconnue' : libelleAge(age)}',
            maxLines: 2, overflow: TextOverflow.ellipsis,
            style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13,
                color: direct ? AppTheme.vertFonce : const Color(0xFF92400E)),
          )),
        ]),
      ),
    );
  }

  Widget _panneau({required bool lateral}) {
    final infos = _infos;
    final v = _vehicule, o = coordonnees(_origine), d = coordonnees(_destination);
    final reste = v != null && d != null ? distanceKm(v, d) : null;
    final total = o != null && d != null ? distanceKm(o, d) : null;
    final progression = reste != null && total != null && total > .05 ? (1 - reste / total).clamp(0.0, 1.0) : null;
    final code = infos?['code_tracabilite'] as String?;

    return Material(
      color: Colors.white,
      elevation: lateral ? 0 : 12,
      borderRadius: lateral ? BorderRadius.zero : const BorderRadius.vertical(top: Radius.circular(22)),
      child: SafeArea(
        top: lateral,
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 10, 20, 16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            if (!lateral)
              Center(child: Container(width: 40, height: 4, margin: const EdgeInsets.only(bottom: 12),
                  decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(4)))),
            if (_statut != null || infos?['numero_livraison'] != null)
              Row(children: [
                if (_statut != null) StatusBadge(_statut!),
                const Spacer(),
                if (infos?['numero_livraison'] != null)
                  Flexible(child: Text(infos!['numero_livraison'], overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontFamily: 'monospace', fontSize: 12, color: Colors.grey))),
              ]),
            const SizedBox(height: 10),
            if (v != null) ...[
              Text(reste != null ? formaterDistance(reste) : 'En route',
                  style: const TextStyle(fontSize: 30, fontWeight: FontWeight.bold, color: AppTheme.vertFonce, height: 1.1)),
              Text(reste != null ? 'jusqu’à la destination' : 'Coordonnées de destination non renseignées',
                  style: const TextStyle(fontSize: 15)),
              const SizedBox(height: 2),
              const Text('Distance à vol d’oiseau, depuis la dernière position reçue.',
                  style: TextStyle(fontSize: 12, color: Colors.grey)),
              if (progression != null) ...[
                const SizedBox(height: 10),
                ClipRRect(borderRadius: BorderRadius.circular(6), child: LinearProgressIndicator(
                  value: progression, minHeight: 6, color: AppTheme.vert, backgroundColor: Colors.grey.shade200,
                  semanticsLabel: 'Progression estimée',
                )),
              ],
            ] else if (_etat != EtatSuivi.chargement) ...[
              Text(_titreEtat, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AppTheme.vertFonce)),
              const SizedBox(height: 4),
              Text(_messageEtat.$2, style: const TextStyle(fontSize: 13, color: Colors.grey)),
            ],
            if (_erreurReseau) ...[
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(8)),
                child: Text(
                  'Connexion au serveur perdue — nouvelle tentative automatique.${v != null ? ' La position affichée est la dernière reçue.' : ''}',
                  style: const TextStyle(color: AppTheme.rouge, fontSize: 13),
                ),
              ),
            ],
            if (v != null) ...[
              const SizedBox(height: 10),
              const Text('Tracé indicatif · Fond de carte © OpenStreetMap',
                  style: TextStyle(fontSize: 11, color: Colors.grey)),
            ],
            if (infos != null) ...[
              const SizedBox(height: 8),
              if (infos['produit'] != null)
                _ligne('Marchandise', '${infos['produit']}${infos['quantite'] != null ? ' · ${infos['quantite']} kg' : ''}'),
              if (_origine?['nom'] != null) _ligne('Départ', _origine!['nom']),
              if (_destination?['adresse'] != null) _ligne('Destination', _destination!['adresse']),
              if (lireDate(infos['date_depart']) case final dep?) _ligne('Parti le', _formaterDate(dep)),
              if (lireDate(infos['date_livraison']) case final liv?) _ligne('Livré le', _formaterDate(liv)),
              if (v != null && _horodatage != null) _ligne('Position GPS reçue', _formaterDate(_horodatage!.toLocal())),
              if (code != null) ...[
                const SizedBox(height: 12),
                ElevatedButton.icon(
                  icon: const Icon(Icons.timeline),
                  label: const Text('Voir la traçabilité du lot'),
                  onPressed: () => Navigator.push(context, MaterialPageRoute(
                    builder: (_) => TracabiliteScreen(codeInitial: code),
                  )),
                ),
              ],
            ],
          ]),
        ),
      ),
    );
  }

  Widget _ligne(String libelle, String valeur) => Container(
        padding: const EdgeInsets.symmetric(vertical: 9),
        decoration: BoxDecoration(border: Border(top: BorderSide(color: Colors.grey.shade200))),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 120, child: Text(libelle, style: const TextStyle(fontSize: 12, color: Colors.grey, fontWeight: FontWeight.w600))),
          Expanded(child: Text(valeur, textAlign: TextAlign.right, style: const TextStyle(fontSize: 14))),
        ]),
      );

  String _formaterDate(DateTime d) {
    String p(int n) => n.toString().padLeft(2, '0');
    return '${p(d.day)}/${p(d.month)}/${d.year} à ${p(d.hour)}:${p(d.minute)}';
  }
}
