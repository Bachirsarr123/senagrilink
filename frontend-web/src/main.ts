import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';

bootstrapApplication(App, appConfig)
  .catch((err) => console.error(err));

// Demande au navigateur de ne pas évincer le stockage du site (session comprise)
// en cas de manque d'espace. Accordé d'office aux applications installées.
navigator.storage?.persist?.().catch(() => undefined);
