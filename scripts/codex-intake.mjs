#!/usr/bin/env node

const baseUrl = (process.env.GEOFLOW_API_BASE_URL || 'http://localhost:18080/api/v1').replace(/\/+$/, '');
const token = process.env.GEOFLOW_API_TOKEN || '';

function usage(exitCode = 0) {
  const text = `
GEOFlow Codex Intake helper

Usage:
  node scripts/codex-intake.mjs search "SJ4060 Spain"
  node scripts/codex-intake.mjs create draft.json
  node scripts/codex-intake.mjs show 12

Environment:
  GEOFLOW_API_BASE_URL  default: http://localhost:18080/api/v1
  GEOFLOW_API_TOKEN     required Bearer token
  GEOFLOW_IDEMPOTENCY_KEY optional key for create
`;
  console.log(text.trim());
  process.exit(exitCode);
}

async function request(path, options = {}) {
  if (!token) {
    throw new Error('Missing GEOFLOW_API_TOKEN environment variable.');
  }

  const response = await fetch(`${baseUrl}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok || payload.success === false) {
    const message = payload?.error?.message || response.statusText || 'Request failed';
    throw new Error(`${response.status} ${message}`);
  }

  return payload.data;
}

function summarizeDraft(data) {
  const draft = data.draft || {};
  const actions = data.actions || [];
  console.log(`Draft #${draft.id} · ${draft.status} · ${draft.source || 'unknown source'}`);
  console.log(`Collection: ${draft.collection_name || draft.collection_id || 'unspecified'}`);
  console.log(`Summary: ${draft.normalized_summary || draft.raw_input || ''}`);
  console.log(`Actions: ${actions.length}`);
  for (const action of actions) {
    console.log(`- #${action.id || '-'} ${action.action_label || `${action.action_type}/${action.target_type}`} · risk=${action.risk_level} · status=${action.status}`);
  }
}

const [command, ...args] = process.argv.slice(2);

try {
  if (!command || command === '-h' || command === '--help') {
    usage(0);
  }

  if (command === 'search') {
    const query = args.join(' ').trim();
    if (!query) usage(1);
    const data = await request(`/assistant/context/search?q=${encodeURIComponent(query)}`);
    console.log(JSON.stringify(data, null, 2));
  } else if (command === 'create') {
    const file = args[0];
    if (!file) usage(1);
    const fs = await import('node:fs/promises');
    const raw = await fs.readFile(file, 'utf8');
    const body = JSON.parse(raw);
    const idempotencyKey = process.env.GEOFLOW_IDEMPOTENCY_KEY || `codex-intake:${Date.now()}`;
    const data = await request('/assistant/intake-drafts', {
      method: 'POST',
      headers: { 'X-Idempotency-Key': idempotencyKey },
      body: JSON.stringify(body),
    });
    summarizeDraft(data);
  } else if (command === 'show') {
    const id = Number.parseInt(args[0] || '', 10);
    if (!Number.isInteger(id) || id <= 0) usage(1);
    const data = await request(`/assistant/intake-drafts/${id}`);
    summarizeDraft(data);
  } else {
    usage(1);
  }
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
}
