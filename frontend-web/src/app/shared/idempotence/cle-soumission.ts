/**
 * Clé d'idempotence d'un formulaire de création (P-25).
 *
 * La même clé accompagne la soumission et ses nouveaux essais (erreur réseau,
 * réponse perdue, double clic) : le serveur renvoie alors le résultat déjà
 * enregistré au lieu de créer un doublon. Elle est renouvelée après un succès
 * ou à l'ouverture d'un nouveau formulaire.
 */
export class CleSoumission {
  private cle = CleSoumission.generer();

  get valeur(): string { return this.cle; }

  renouveler(): void { this.cle = CleSoumission.generer(); }

  /** Format accepté par le serveur : [A-Za-z0-9_-]{8,100}. */
  static generer(): string {
    const c: Crypto = globalThis.crypto;
    if (typeof c.randomUUID === 'function') return c.randomUUID();
    // Contexte non sécurisé (test en HTTP sur le réseau local) : randomUUID indisponible.
    const octets = new Uint8Array(16);
    c.getRandomValues(octets);
    return Array.from(octets, o => o.toString(16).padStart(2, '0')).join('');
  }
}
