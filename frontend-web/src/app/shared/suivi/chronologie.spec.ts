import { construireChronologie, resumerLot } from './chronologie';

/** Réponse de GET /tracabilite/{code} (forme réelle de TracabiliteController). */
function lot(livraisons: any[]) {
  return {
    code_tracabilite: 'LOT-2026-TEST',
    etape_production: {
      type_culture: 'riz', date_recolte: '2026-06-01', quantite_estimee: 100, quantite_reelle: null, statut: 'disponible',
      producteur: { nom: 'Ndiaye', prenom: 'Awa', region: 'Thiès' },
    },
    etapes_stockage: [{
      entrepot: { nom: 'Entrepôt Nord', localisation: 'Thiès' }, produit: 'riz', quantite: 80,
      date_entree: '2026-06-05', date_sortie: null, statut: 'disponible',
      commandes: livraisons.map((l, i) => ({
        numero_commande: `CMD-${i}`, produit: 'riz', quantite: 10, date_commande: `2026-06-1${i} 09:00:00`, statut: 'confirmee',
        acheteur: i === 0 ? { nom: 'Ba', prenom: 'Moussa' } : null, livraison: l,
      })),
    }],
  };
}

describe('construireChronologie — parcours réel du lot', () => {
  it('ordonne récolte, entrée, commande puis livraison, et marque la livraison en cours', () => {
    const etapes = construireChronologie(lot([
      { id: 9, numero_livraison: 'LIV-1', statut: 'en_cours', date_depart: '2026-06-12 08:00:00', origine: 'Entrepôt Nord', destination: 'Dakar' },
    ]));

    expect(etapes.map(e => e.type)).toEqual(['recolte', 'entree', 'commande', 'depart']);
    expect(etapes.at(-1)).toMatchObject({ titre: 'En cours de livraison', etat: 'actuel', livraisonId: 9 });
    expect(etapes.filter(e => e.etat === 'actuel')).toHaveLength(1);
    expect(etapes[0]).toMatchObject({ lieu: 'Thiès', detail: 'riz — 100 kg estimés — par Awa Ndiaye' });
    expect(etapes[2].detail).toContain('pour Moussa Ba');
  });

  it('sans livraison en cours, l\'étape actuelle est la dernière enregistrée', () => {
    const etapes = construireChronologie(lot([
      { numero_livraison: 'LIV-1', statut: 'livree', date_depart: '2026-06-11 08:00:00', date_livraison: '2026-06-11 15:00:00', destination: 'Dakar' },
    ]));

    expect(etapes.map(e => e.type)).toEqual(['recolte', 'entree', 'commande', 'depart', 'livree']);
    expect(etapes.at(-1)).toMatchObject({ titre: 'Livrée', etat: 'actuel', lieu: 'Dakar' });
    expect(etapes.find(e => e.livraisonId != null)).toBeUndefined();
  });

  it('une livraison planifiée est « prévue » en fin de liste, sans date inventée', () => {
    const etapes = construireChronologie(lot([{ statut: 'en_attente', date_depart: null, origine: 'Entrepôt Nord' }]));

    expect(etapes.at(-1)).toMatchObject({ type: 'planifiee', etat: 'prevu', date: null });
    expect(etapes.at(-2)).toMatchObject({ type: 'commande', etat: 'actuel' });
  });

  it('un problème signalé apparaît en alerte, sans date', () => {
    const etapes = construireChronologie(lot([{ statut: 'probleme', date_depart: '2026-06-12 08:00:00' }]));
    expect(etapes.at(-1)).toMatchObject({ type: 'probleme', etat: 'alerte', date: null });
  });

  it('ne fabrique aucune étape : lot seulement récolté', () => {
    const t = { ...lot([]), etapes_stockage: [] };
    const etapes = construireChronologie(t);
    expect(etapes).toHaveLength(1);
    expect(resumerLot(t, etapes)).toMatchObject({ etatActuel: 'Récolte', dernierLieu: 'Thiès', livraisonEnCoursId: null });
  });

  it('respecte le masquage serveur (R7) : pas de nom ni de destination si l\'API ne les donne pas', () => {
    const etapes = construireChronologie(lot([
      { statut: 'livree', date_depart: '2026-06-10 08:00:00', date_livraison: null, destination: null, transporteur: null },
    ]));
    expect(etapes.find(e => e.type === 'livree')).toMatchObject({ lieu: null, date: null });
    expect(etapes.some(e => (e.detail ?? '').includes('Transporteur'))).toBe(false);
  });

  it('résume le lot : dernier lieu déclaré et dernière date', () => {
    const t = lot([{ id: 3, statut: 'en_cours', date_depart: '2026-06-12 08:00:00', origine: 'Entrepôt Nord' }]);
    const r = resumerLot(t, construireChronologie(t));
    expect(r).toMatchObject({
      code: 'LOT-2026-TEST', produit: 'riz', producteur: 'Awa Ndiaye', etatActuel: 'En cours de livraison',
      dernierLieu: 'Entrepôt Nord', dateDerniereEtape: '2026-06-12 08:00:00', livraisonEnCoursId: 3,
    });
  });
});
