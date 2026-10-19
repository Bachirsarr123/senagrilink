import { LibelleStatutPipe } from './libelle-statut.pipe';

describe('LibelleStatutPipe', () => {
  const pipe = new LibelleStatutPipe();

  it('traduit les codes connus', () => {
    expect(pipe.transform('en_attente')).toBe('En attente');
    expect(pipe.transform('arrivee_entrepot')).toBe('Arrivée à l\'entrepôt');
    expect(pipe.transform('acheteur_gros')).toBe('Acheteur en gros');
  });

  it('rend lisible un code inconnu sans le perdre', () => {
    expect(pipe.transform('nouveau_statut')).toBe('Nouveau statut');
  });

  it('renvoie une chaîne vide pour une valeur absente', () => {
    expect(pipe.transform(null)).toBe('');
    expect(pipe.transform(undefined)).toBe('');
  });
});
