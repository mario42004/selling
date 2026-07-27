import { writeFileSync } from 'node:fs';

const outputPath = process.argv[2] ?? '/tmp/mariadb-credential.json';
const required = ['MARIADB_DATABASE', 'MARIADB_USER', 'MARIADB_PASSWORD'];
const missing = required.filter((name) => !process.env[name]);

if (missing.length) {
  throw new Error(`Faltan variables: ${missing.join(', ')}`);
}

const credentials = [
  {
    id: 'mariadb-orders',
    name: 'MariaDB Pedidos',
    type: 'mySql',
    data: {
      host: 'mariadb',
      database: process.env.MARIADB_DATABASE,
      user: process.env.MARIADB_USER,
      password: process.env.MARIADB_PASSWORD,
      port: 3306,
      ssl: false,
    },
    nodesAccess: [{ nodeType: 'n8n-nodes-base.mySql' }],
  },
];

writeFileSync(outputPath, JSON.stringify(credentials), { mode: 0o600 });
console.log(`Credencial preparada en ${outputPath}`);

