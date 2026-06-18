export interface Utilisateur {
  id: number;
  nom: string;
  prenom: string;
  email: string;
  telephone?: string;
  adresse?: string;
  photo?: string;
  role: Role;
  statut: 'actif' | 'bloque';
  producteur?: any;
  entrepot?: any;
  acheteurGros?: any;
  transporteur?: any;
}

export type Role =
  | 'producteur'
  | 'gestionnaire_entrepot'
  | 'acheteur_gros'
  | 'transporteur'
  | 'administrateur';

export interface AuthResponse {
  message: string;
  utilisateur: Utilisateur;
  access_token: string;
  token_type: string;
}

export interface LoginPayload {
  email: string;
  mot_de_passe: string;
}

export interface RegisterPayload {
  nom: string;
  prenom: string;
  email: string;
  mot_de_passe: string;
  mot_de_passe_confirmation: string;
  role: Role;
  telephone?: string;
  adresse?: string;
  // Profil producteur
  superficie?: number;
  types_cultures?: string;
  region?: string;
  // Profil gestionnaire
  nom_entrepot?: string;
  capacite?: number;
  localisation?: string;
  // Profil acheteur
  type_activite?: string;
  volume_achat_mensuel?: number;
}
