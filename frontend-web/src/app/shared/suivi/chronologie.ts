import { versMs } from './geo';

export type EtatEtape = 'termine' | 'actuel' | 'prevu' | 'alerte';

/** Une étape réellement enregistrée dans le parcours d'un lot (aucune n'est inventée). */
export interface EtapeChronologie {
  cle: string;
  type: 'recolte' | 'entree' | 'sortie' | 'commande' | 'depart' | 'livree' | 'planifiee' | 'probleme';
  titre: string;
  date: string | null;
  /** Lieu déclaré lors de l'opération (jamais une position GPS). */
  lieu: string | null;
  detail: string | null;
  statut: string | null;
  etat: EtatEtape;
  /** Livraison en cours, consultable sur la carte de suivi. */
  livraisonId: number | null;
}

export interface ResumeLot {
  code: string;
  produit: string | null;
  producteur: string | null;
  region: string | null;
  etatActuel: string | null;
  dernierLieu: string | null;
  dateDerniereEtape: string | null;
  livraisonEnCoursId: number | null;
}

const joindre = (...parts: (string | null | undefined)[]) =>
  parts.filter(p => p != null && `${p}`.trim() !== '').join(' — ') || null;

const kg = (q: unknown) => (q == null ? null : `${Number(q).toLocaleString('fr-FR')} kg`);

/** Nom d'une personne si l'API l'a fourni (masqué par le serveur aux tiers, cf. R7). */
const nom = (p: any) => (p ? `${p.prenom ?? ''} ${p.nom ?? ''}`.trim() || null : null);

/**
 * Chronologie d'un lot à partir de la réponse de GET /tracabilite/{code} :
 * récolte, entrées/sorties d'entrepôt, commandes, départs et arrivées de livraison.
 * Les étapes datées sont triées par date ; une livraison planifiée (sans départ)
 * est « prévue » en fin de liste ; l'étape actuelle est la livraison en cours,
 * sinon la dernière étape enregistrée.
 */
export function construireChronologie(t: any): EtapeChronologie[] {
  if (!t) return [];
  const etapes: EtapeChronologie[] = [];
  const ajouter = (e: Omit<EtapeChronologie, 'etat' | 'livraisonId' | 'statut'> &
    Partial<Pick<EtapeChronologie, 'etat' | 'livraisonId' | 'statut'>>) =>
    etapes.push({ etat: 'termine', livraisonId: null, statut: null, ...e });

  const prod = t.etape_production;
  if (prod) {
    const quantite = prod.quantite_reelle ?? prod.quantite_estimee;
    const producteur = prod.producteur ? `${prod.producteur.prenom ?? ''} ${prod.producteur.nom ?? ''}`.trim() : null;
    ajouter({
      cle: 'recolte', type: 'recolte', titre: 'Récolte',
      date: prod.date_recolte ?? null,
      lieu: prod.producteur?.region ?? null,
      detail: joindre(prod.type_culture, quantite != null
        ? `${kg(quantite)}${prod.quantite_reelle == null ? ' estimés' : ''}` : null, producteur && `par ${producteur}`),
    });
  }

  (t.etapes_stockage ?? []).forEach((s: any, i: number) => {
    const entrepot = joindre(s.entrepot?.nom, s.entrepot?.localisation);
    ajouter({
      cle: `entree-${i}`, type: 'entree', titre: 'Entrée en entrepôt', date: s.date_entree ?? null,
      lieu: entrepot, detail: joindre(s.produit, kg(s.quantite)), statut: s.statut ?? null,
    });
    if (s.date_sortie) {
      ajouter({ cle: `sortie-${i}`, type: 'sortie', titre: 'Sortie d’entrepôt', date: s.date_sortie, lieu: entrepot, detail: null });
    }

    (s.commandes ?? []).forEach((c: any, j: number) => {
      ajouter({
        cle: `commande-${i}-${j}`, type: 'commande', titre: `Commande ${c.numero_commande ?? ''}`.trim(),
        date: c.date_commande ?? null, lieu: null,
        detail: joindre(c.produit, kg(c.quantite), nom(c.acheteur) && `pour ${nom(c.acheteur)}`), statut: c.statut ?? null,
      });

      const l = c.livraison;
      if (!l) return;
      const versDestination = joindre(l.destination && `Vers ${l.destination}`, nom(l.transporteur) && `Transporteur : ${nom(l.transporteur)}`);
      if (l.statut === 'en_attente' && !l.date_depart) {
        ajouter({
          cle: `livraison-${i}-${j}`, type: 'planifiee', titre: 'Livraison planifiée', date: null,
          lieu: l.origine ?? null, detail: versDestination, statut: l.statut, etat: 'prevu',
        });
        return;
      }
      if (l.date_depart || l.statut === 'en_cours') {
        ajouter({
          cle: `depart-${i}-${j}`, type: 'depart',
          titre: l.statut === 'en_cours' ? 'En cours de livraison' : 'Départ en livraison',
          date: l.date_depart ?? null, lieu: l.origine ?? null, detail: versDestination, statut: l.statut,
          etat: l.statut === 'en_cours' ? 'actuel' : 'termine',
          livraisonId: l.statut === 'en_cours' ? (l.id ?? null) : null,
        });
      }
      if (l.statut === 'livree') {
        ajouter({
          cle: `livree-${i}-${j}`, type: 'livree', titre: 'Livrée', date: l.date_livraison ?? null,
          lieu: l.destination ?? null, detail: l.numero_livraison ?? null, statut: l.statut,
        });
      }
      if (l.statut === 'probleme') {
        ajouter({
          cle: `probleme-${i}-${j}`, type: 'probleme', titre: 'Problème signalé sur la livraison', date: null,
          lieu: null, detail: l.numero_livraison ?? null, statut: l.statut, etat: 'alerte',
        });
      }
    });
  });

  // Tri stable : étapes datées par date, puis alertes non datées, puis étapes prévues.
  const rang = (e: EtapeChronologie) => (e.etat === 'prevu' ? 2 : versMs(e.date) == null ? 1 : 0);
  const ordonnees = etapes
    .map((e, index) => ({ e, index }))
    .sort((a, b) => rang(a.e) - rang(b.e)
      || (versMs(a.e.date) ?? 0) - (versMs(b.e.date) ?? 0)
      || a.index - b.index)
    .map(x => x.e);

  if (!ordonnees.some(e => e.etat === 'actuel' || e.etat === 'alerte')) {
    const derniere = [...ordonnees].reverse().find(e => e.etat === 'termine');
    if (derniere) derniere.etat = 'actuel';
  }
  return ordonnees;
}

export function resumerLot(t: any, etapes: EtapeChronologie[]): ResumeLot {
  const prod = t?.etape_production;
  const enregistrees = etapes.filter(e => e.etat !== 'prevu');
  const actuelle = etapes.find(e => e.etat === 'actuel' || e.etat === 'alerte') ?? enregistrees.at(-1) ?? null;
  const avecLieu = [...enregistrees].reverse().find(e => e.lieu);
  return {
    code: t?.code_tracabilite ?? '',
    produit: prod?.type_culture ?? null,
    producteur: prod?.producteur ? `${prod.producteur.prenom ?? ''} ${prod.producteur.nom ?? ''}`.trim() || null : null,
    region: prod?.producteur?.region ?? null,
    etatActuel: actuelle?.titre ?? null,
    dernierLieu: avecLieu?.lieu ?? null,
    dateDerniereEtape: [...enregistrees].reverse().find(e => e.date)?.date ?? null,
    livraisonEnCoursId: etapes.find(e => e.livraisonId != null)?.livraisonId ?? null,
  };
}
