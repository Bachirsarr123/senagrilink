import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, UrlTree, provideRouter } from '@angular/router';
import { inviteGuard } from './invite.guard';

describe('inviteGuard — page de connexion avec une session enregistrée', () => {
  function evaluer(): boolean | UrlTree {
    TestBed.configureTestingModule({ providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()] });
    return TestBed.runInInjectionContext(() => inviteGuard({} as any, {} as any)) as boolean | UrlTree;
  }

  beforeEach(() => localStorage.clear());

  it('sans session : la page de connexion s\'affiche', () => {
    expect(evaluer()).toBe(true);
  });

  it.each([
    ['transporteur', '/a-faire'],
    ['producteur', '/a-faire'],
    ['administrateur', '/admin/dashboard'],
  ])('session %s encore enregistrée (réouverture de la PWA sur /login) : renvoi vers %s', (role, attendu) => {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'T', prenom: 'A', role }));
    const resultat = evaluer();
    expect(TestBed.inject(Router).serializeUrl(resultat as UrlTree)).toBe(attendu);
  });

  it('profil sans jeton : pas de redirection (session non valide)', () => {
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'T', prenom: 'A', role: 'transporteur' }));
    expect(evaluer()).toBe(true);
  });
});
