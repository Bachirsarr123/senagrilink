// Génère src/environments/environment.prod.ts à partir de la variable
// d'environnement API_URL (fournie par Render au moment du build).
// Repli sur '/api' si absente (déploiement où front et back partagent un domaine).
const fs = require('fs');
const path = require('path');

const apiUrl = process.env.API_URL || '/api';

const targetPath = path.join(__dirname, '..', 'src', 'environments', 'environment.prod.ts');

const content = `export const environment = {
  production: true,
  apiUrl: '${apiUrl}',
};
`;

fs.writeFileSync(targetPath, content);
console.log(`[set-env] environment.prod.ts généré avec apiUrl="${apiUrl}"`);
