import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { App } from './app';
import { routes } from './app.routes';

describe('App', () => {
  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideRouter(routes), provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(App);
    const app = fixture.componentInstance;
    expect(app).toBeTruthy();
  });

  // Le composant racine ne contient que le routeur (la page de bienvenue a été
  // supprimée) : on vérifie qu'il rend bien la page de l'URL demandée.
  it('should render the routed page (login for an anonymous visitor)', async () => {
    const fixture = TestBed.createComponent(App);
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('router-outlet')).not.toBeNull();

    await TestBed.inject(Router).navigateByUrl('/');
    fixture.detectChanges();
    await fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/login');
    expect(compiled.querySelector('app-login')).not.toBeNull();
    expect(compiled.textContent).toContain('Connexion');
  });

  it('should redirect an anonymous visitor away from protected pages', async () => {
    const fixture = TestBed.createComponent(App);

    await TestBed.inject(Router).navigateByUrl('/admin/dashboard');
    fixture.detectChanges();
    await fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/login');
  });
});
