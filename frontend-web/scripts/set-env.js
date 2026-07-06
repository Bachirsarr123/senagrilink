// Génère src/environments/environment.prod.ts à partir des variables
// d'environnement fournies par Render au moment du build (API_URL, REVERB_*).
// Replis sur des valeurs par défaut si absentes.
const fs = require('fs');
const path = require('path');

const apiUrl = process.env.API_URL || '/api';
const reverbKey = process.env.REVERB_APP_KEY || 'agriplatform-key';
const reverbHost = process.env.REVERB_HOST || '';
const reverbPort = process.env.REVERB_PORT || '443';
const reverbScheme = process.env.REVERB_SCHEME || 'https';

const targetPath = path.join(__dirname, '..', 'src', 'environments', 'environment.prod.ts');

const content = `export const environment = {
  production: true,
  apiUrl: '${apiUrl}',
  reverb: {
    key: '${reverbKey}',
    host: '${reverbHost}',
    port: ${reverbPort},
    scheme: '${reverbScheme}' as const,
  },
};
`;

fs.writeFileSync(targetPath, content);
console.log(`[set-env] environment.prod.ts généré avec apiUrl="${apiUrl}" reverbHost="${reverbHost}"`);
