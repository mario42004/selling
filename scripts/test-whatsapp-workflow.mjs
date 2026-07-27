import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const workflow = JSON.parse(
  await readFile(new URL('../n8n/workflows/04-whatsapp-order-intake.json', import.meta.url)),
);

const nodeCode = (name) => {
  const node = workflow.nodes.find((candidate) => candidate.name === name);
  assert.ok(node, `No existe el nodo ${name}`);
  return node.parameters.jsCode;
};

const normalize = new Function('$json', nodeCode('Normalizar mensaje de WhatsApp'));
const normalized = normalize({
  contacts: [{ profile: { name: 'Ana' }, wa_id: '34622222222' }],
  messages: [
    {
      from: '34622222222',
      id: 'wamid.test-001',
      type: 'text',
      text: { body: 'dos aguas grandes. Dirección: Calle Mayor 3' },
    },
  ],
});

assert.equal(normalized[0].json.customer_name, 'Ana');
assert.equal(normalized[0].json.phone, '+34622222222');
assert.equal(normalized[0].json.address, 'Calle Mayor 3');
assert.equal(normalized[0].json.address_pending, false);
assert.equal(normalized[0].json.external_message_id, 'wamid.test-001');

const pendingAddress = normalize({
  contacts: [{ profile: { name: 'Luis' } }],
  messages: [
    {
      from: '34611111111',
      id: 'wamid.test-002',
      type: 'text',
      text: { body: 'una bolsa de hielo' },
    },
  ],
});
assert.equal(pendingAddress[0].json.address, 'Pendiente de confirmar');
assert.equal(pendingAddress[0].json.address_pending, true);

assert.deepEqual(normalize({ statuses: [{ status: 'read' }] }), []);

const parseOrder = new Function(
  '$',
  '$input',
  'Buffer',
  nodeCode('Interpretar pedido'),
);
const parsed = parseOrder(
  () => ({ first: () => normalized[0] }),
  {
    all: () => [
      {
        json: {
          sku: 'AGUA-15L',
          name: 'Agua mineral 1,5 L',
          aliases: ['agua grande', 'aguas grandes'],
        },
      },
    ],
  },
  Buffer,
);

assert.equal(parsed[0].json.recognized_items, 1);
assert.deepEqual(parsed[0].json.payload.items, [
  { sku: 'AGUA-15L', quantity: 2, matched_alias: 'aguas grandes' },
]);

console.log('Workflow de WhatsApp validado.');
