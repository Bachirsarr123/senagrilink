import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { LoginComponent } from './login.component';

describe('LoginComponent — motif de fin de session (R4)', () => {
  function creer(raison: string | null) {
    TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap(raison ? { raison } : {}) } } },
      ],
    });
    const fixture = TestBed.createComponent(LoginComponent);
    fixture.detectChanges();
    return (fixture.nativeElement as HTMLElement).textContent ?? '';
  }

  it('explique une session expirée', () => {
    expect(creer('session_expiree')).toContain('Votre session a expiré');
  });

  it('distingue le compte bloqué', () => {
    expect(creer('compte_bloque')).toContain('Votre compte a été bloqué');
  });

  it('n\'affiche rien sans motif', () => {
    const texte = creer(null);
    expect(texte).not.toContain('session a expiré');
    expect(texte).not.toContain('compte a été bloqué');
  });
});

describe('LoginComponent — erreur de connexion', () => {
  it('affiche le message de l\'API dès la réponse, sans autre interaction', async () => {
    TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });
    const fixture = TestBed.createComponent(LoginComponent);
    fixture.detectChanges();

    fixture.componentInstance.form.setValue({ email: 'inconnu@demo.sn', mot_de_passe: 'mauvais' });
    fixture.componentInstance.onSubmit();
    TestBed.inject(HttpTestingController)
      .expectOne(req => req.url.endsWith('/login'))
      .flush({ message: 'Identifiants incorrects.' }, { status: 401, statusText: 'Unauthorized' });
    await fixture.whenStable();

    const alerte = (fixture.nativeElement as HTMLElement).querySelector('[role=alert]');
    expect(alerte?.textContent).toContain('Identifiants incorrects.');
    expect((fixture.nativeElement as HTMLElement).querySelector('button[type=submit]')?.hasAttribute('disabled')).toBe(false);
  });
});
