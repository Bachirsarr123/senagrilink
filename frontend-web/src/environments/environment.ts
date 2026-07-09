export const environment = {
  production: false,
  // Dérivé du host courant : fonctionne aussi bien sur localhost que
  // depuis un autre appareil du même réseau (ex. téléphone via l'IP LAN).
  apiUrl: `${location.protocol}//${location.hostname}:8000/api`,
  reverb: {
    key: 'agriplatform-key',
    host: location.hostname,
    port: 8080,
    scheme: 'http' as const,
  },
};
