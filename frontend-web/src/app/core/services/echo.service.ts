import { Injectable } from '@angular/core';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { environment } from '../../../environments/environment';
import { AuthService } from '../auth/auth.service';

// Requis par laravel-echo lorsque broadcaster: 'reverb' (protocole compatible Pusher).
(window as any).Pusher = Pusher;

@Injectable({ providedIn: 'root' })
export class EchoService {
  private echo: Echo<'reverb'> | null = null;

  constructor(private auth: AuthService) {}

  private connecter(): Echo<'reverb'> | null {
    if (this.echo) return this.echo;

    const token = this.auth.getToken();
    if (!token) return null;

    this.echo = new Echo({
      broadcaster: 'reverb',
      key: environment.reverb.key,
      wsHost: environment.reverb.host,
      wsPort: environment.reverb.port,
      wssPort: environment.reverb.port,
      forceTLS: environment.reverb.scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint: `${environment.apiUrl}/broadcasting/auth`,
      auth: {
        headers: { Authorization: `Bearer ${token}` },
      },
    });

    return this.echo;
  }

  /** Écoute les notifications temps réel de l'utilisateur connecté. */
  ecouterNotifications(utilisateurId: number, callback: (notification: any) => void): void {
    this.connecter()?.private(`utilisateur.${utilisateurId}`).notification(callback);
  }

  deconnecter(): void {
    this.echo?.disconnect();
    this.echo = null;
  }
}
