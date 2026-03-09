#!/usr/bin/env node
/**
 * Claudriel Memory MCP server.
 *
 * Wraps the Claudriel HTTP API as memory.* tools so Claudia skills
 * can read/write the dashboard's entity storage as their memory backend.
 *
 * Required env vars:
 *   CLAUDRIEL_API_URL  — e.g. http://localhost:8088
 *   CLAUDRIEL_API_KEY  — bearer token for /api/ingest
 */
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import fs from 'fs';
import path from 'path';

const API_URL = process.env.CLAUDRIEL_API_URL || 'http://localhost:8088';
const API_KEY = process.env.CLAUDRIEL_API_KEY || '';

// Resolve project root (two levels up from tools/memory-mcp/)
const PROJECT_ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

async function apiGet(endpoint) {
  const res = await fetch(`${API_URL}${endpoint}`, {
    headers: { 'Accept': 'application/json' },
  });
  if (!res.ok) {
    throw new Error(`GET ${endpoint} failed: ${res.status} ${await res.text()}`);
  }
  return res.json();
}

async function apiPost(endpoint, body) {
  const res = await fetch(`${API_URL}${endpoint}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${API_KEY}`,
    },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    throw new Error(`POST ${endpoint} failed: ${res.status} ${await res.text()}`);
  }
  return res.json();
}

async function apiGetEntities(entityType, filter = {}) {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(filter)) {
    params.set(`filter[${k}]`, v);
  }
  const qs = params.toString();
  const endpoint = `/jsonapi/${entityType}${qs ? '?' + qs : ''}`;
  return apiGet(endpoint);
}

// ---------------------------------------------------------------------------
// Context file helpers
// ---------------------------------------------------------------------------

function resolveContextPath(filename) {
  // Only allow files under context/, people/, projects/
  const allowed = ['context', 'people', 'projects'];
  const normalized = filename.replace(/\\/g, '/');
  const firstDir = normalized.split('/')[0];
  if (!allowed.includes(firstDir)) {
    throw new Error(`File path must start with ${allowed.join(', ')}. Got: ${filename}`);
  }
  return path.join(PROJECT_ROOT, normalized);
}

function readContextFile(filename) {
  const fullPath = resolveContextPath(filename);
  if (!fs.existsSync(fullPath)) return null;
  return fs.readFileSync(fullPath, 'utf8');
}

function writeContextFile(filename, content) {
  const fullPath = resolveContextPath(filename);
  const dir = path.dirname(fullPath);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(fullPath, content, 'utf8');
}

// ---------------------------------------------------------------------------
// MCP Server
// ---------------------------------------------------------------------------

const server = new McpServer({
  name: 'claudriel-memory',
  version: '1.0.0',
});

// -- memory.session_context --------------------------------------------------
server.tool(
  'memory_session_context',
  'Load session context: day brief (recent events, pending/drifting commitments) plus personal context files. Call at session start.',
  {},
  async () => {
    try {
      const data = await apiGet('/api/context');
      return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error loading context: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.morning_context --------------------------------------------------
server.tool(
  'memory_morning_context',
  'Load full morning briefing context. Same as session_context but formatted for morning review.',
  {},
  async () => {
    try {
      const data = await apiGet('/api/context');
      const brief = data.brief || {};
      const ctx = data.context_files || {};

      const sections = [];
      if (ctx.me) sections.push(`## About Me\n${ctx.me}`);

      if (brief.recent_events?.length) {
        sections.push(`## Recent Events (last 24h)\n${brief.recent_events.map(e =>
          `- **${e.source || 'unknown'}**: ${e.subject || e.summary || 'No subject'}`
        ).join('\n')}`);
      }

      if (brief.pending_commitments?.length) {
        sections.push(`## Pending Commitments\n${brief.pending_commitments.map(c =>
          `- ${c.title || c.description || 'Untitled'} (due: ${c.due_date || 'none'})`
        ).join('\n')}`);
      }

      if (brief.drifting_commitments?.length) {
        sections.push(`## Drifting Commitments (stale >48h)\n${brief.drifting_commitments.map(c =>
          `- ${c.title || c.description || 'Untitled'} — last updated: ${c.updated_at || 'unknown'}`
        ).join('\n')}`);
      }

      if (ctx.patterns) sections.push(`## Behavioral Patterns\n${ctx.patterns}`);
      if (ctx.commitments) sections.push(`## Commitments Context\n${ctx.commitments}`);

      return { content: [{ type: 'text', text: sections.join('\n\n') || 'No context data available.' }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.briefing ---------------------------------------------------------
server.tool(
  'memory_briefing',
  'Get the day brief summary: recent events, pending commitments, drifting commitments.',
  {},
  async () => {
    try {
      const data = await apiGet('/api/context');
      return { content: [{ type: 'text', text: JSON.stringify(data.brief || {}, null, 2) }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.remember ---------------------------------------------------------
server.tool(
  'memory_remember',
  'Store a fact, observation, or event into memory. Ingested as an event into the Claudriel pipeline.',
  {
    type: z.string().describe('Event type: "observation", "fact", "preference", "commitment", "person", "event"'),
    content: z.string().describe('The content to remember'),
    source: z.string().optional().describe('Source of this memory, e.g. "chat", "skill:daily-reflection"'),
    metadata: z.record(z.string()).optional().describe('Additional key-value metadata'),
  },
  async ({ type, content, source, metadata }) => {
    try {
      const payload = { content, ...(metadata || {}) };
      const result = await apiPost('/api/ingest', {
        source: source || 'claudia-memory',
        type,
        payload,
      });
      return { content: [{ type: 'text', text: `Remembered. ${JSON.stringify(result)}` }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error storing memory: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.recall -----------------------------------------------------------
server.tool(
  'memory_recall',
  'Recall memories by querying stored entities. Searches events, commitments, and people.',
  {
    query: z.string().describe('What to recall — a topic, person name, or keyword'),
    entity_type: z.string().optional().describe('Limit to entity type: "mc_event", "commitment", "person", "skill"'),
  },
  async ({ query, entity_type }) => {
    try {
      const types = entity_type ? [entity_type] : ['mc_event', 'commitment', 'person'];
      const results = {};

      for (const t of types) {
        try {
          const data = await apiGetEntities(t);
          const entities = data.data || [];
          // Client-side filter by query match in any string field
          const matches = entities.filter(e => {
            const attrs = e.attributes || {};
            return Object.values(attrs).some(v =>
              typeof v === 'string' && v.toLowerCase().includes(query.toLowerCase())
            );
          });
          if (matches.length > 0) {
            results[t] = matches.slice(0, 10);
          }
        } catch {
          // Entity type may not have JSON:API route; skip
        }
      }

      if (Object.keys(results).length === 0) {
        return { content: [{ type: 'text', text: `No memories found matching "${query}".` }] };
      }

      return { content: [{ type: 'text', text: JSON.stringify(results, null, 2) }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.multi_recall -----------------------------------------------------
server.tool(
  'memory_multi_recall',
  'Recall multiple topics at once. Returns results grouped by query.',
  {
    queries: z.array(z.string()).describe('List of topics/keywords to recall'),
  },
  async ({ queries }) => {
    const results = {};
    for (const q of queries) {
      try {
        const types = ['mc_event', 'commitment', 'person'];
        const matches = {};
        for (const t of types) {
          try {
            const data = await apiGetEntities(t);
            const entities = (data.data || []).filter(e => {
              const attrs = e.attributes || {};
              return Object.values(attrs).some(v =>
                typeof v === 'string' && v.toLowerCase().includes(q.toLowerCase())
              );
            });
            if (entities.length > 0) matches[t] = entities.slice(0, 5);
          } catch { /* skip */ }
        }
        results[q] = Object.keys(matches).length > 0 ? matches : 'No matches';
      } catch (e) {
        results[q] = `Error: ${e.message}`;
      }
    }
    return { content: [{ type: 'text', text: JSON.stringify(results, null, 2) }] };
  },
);

// -- memory.file -------------------------------------------------------------
server.tool(
  'memory_file',
  'Read or write a context file (under context/, people/, or projects/ directories).',
  {
    action: z.enum(['read', 'write']).describe('"read" or "write"'),
    path: z.string().describe('Relative path, e.g. "context/me.md", "people/john.md"'),
    content: z.string().optional().describe('Content to write (required for write action)'),
  },
  async ({ action, path: filePath, content }) => {
    try {
      if (action === 'read') {
        const text = readContextFile(filePath);
        if (text === null) {
          return { content: [{ type: 'text', text: `File not found: ${filePath}` }] };
        }
        return { content: [{ type: 'text', text }] };
      } else {
        if (!content) {
          return { content: [{ type: 'text', text: 'Content is required for write action.' }], isError: true };
        }
        writeContextFile(filePath, content);
        return { content: [{ type: 'text', text: `Written: ${filePath}` }] };
      }
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.entity -----------------------------------------------------------
server.tool(
  'memory_entity',
  'Query entities from Claudriel storage by type, with optional filters.',
  {
    entity_type: z.string().describe('Entity type: "mc_event", "commitment", "person", "skill", "account", "integration"'),
    filter: z.record(z.string()).optional().describe('Key-value filters, e.g. {"status": "active"}'),
  },
  async ({ entity_type, filter }) => {
    try {
      const data = await apiGetEntities(entity_type, filter || {});
      return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.search_entities --------------------------------------------------
server.tool(
  'memory_search_entities',
  'Search across all entity types for a keyword. Returns matching entities grouped by type.',
  {
    query: z.string().describe('Search keyword'),
    max_results: z.number().optional().describe('Max results per entity type (default 10)'),
  },
  async ({ query, max_results = 10 }) => {
    const allTypes = ['mc_event', 'commitment', 'person', 'skill'];
    const results = {};

    for (const t of allTypes) {
      try {
        const data = await apiGetEntities(t);
        const matches = (data.data || []).filter(e => {
          const attrs = e.attributes || {};
          return Object.values(attrs).some(v =>
            typeof v === 'string' && v.toLowerCase().includes(query.toLowerCase())
          );
        }).slice(0, max_results);
        if (matches.length > 0) results[t] = matches;
      } catch { /* skip unavailable types */ }
    }

    if (Object.keys(results).length === 0) {
      return { content: [{ type: 'text', text: `No entities matching "${query}".` }] };
    }
    return { content: [{ type: 'text', text: JSON.stringify(results, null, 2) }] };
  },
);

// -- memory.end_session ------------------------------------------------------
server.tool(
  'memory_end_session',
  'Record a session summary at end of conversation. Stores key observations and decisions.',
  {
    summary: z.string().describe('Session summary — key topics, decisions, and observations'),
    observations: z.array(z.string()).optional().describe('List of individual observations to store'),
  },
  async ({ summary, observations }) => {
    try {
      await apiPost('/api/ingest', {
        source: 'claudia-session',
        type: 'session_summary',
        payload: { summary, observations: observations || [], ended_at: new Date().toISOString() },
      });
      return { content: [{ type: 'text', text: 'Session summary recorded.' }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.about ------------------------------------------------------------
server.tool(
  'memory_about',
  'Get stored information about a specific topic or person from context files and entities.',
  {
    topic: z.string().describe('Person name, project, or topic to look up'),
  },
  async ({ topic }) => {
    const results = [];

    // Check people/ directory
    const peoplePath = `people/${topic.toLowerCase().replace(/\s+/g, '-')}.md`;
    try {
      const personFile = readContextFile(peoplePath);
      if (personFile) results.push(`## Context File: ${peoplePath}\n${personFile}`);
    } catch { /* not found */ }

    // Check person entities
    try {
      const personData = await apiGetEntities('person');
      const matches = (personData.data || []).filter(e => {
        const attrs = e.attributes || {};
        return Object.values(attrs).some(v =>
          typeof v === 'string' && v.toLowerCase().includes(topic.toLowerCase())
        );
      });
      if (matches.length > 0) {
        results.push(`## Person Entities\n${JSON.stringify(matches, null, 2)}`);
      }
    } catch { /* skip */ }

    // Check context files for mentions
    const contextFiles = ['context/me.md', 'context/commitments.md', 'context/patterns.md'];
    for (const cf of contextFiles) {
      try {
        const content = readContextFile(cf);
        if (content && content.toLowerCase().includes(topic.toLowerCase())) {
          results.push(`## Mentioned in ${cf}\n${content}`);
        }
      } catch { /* skip */ }
    }

    if (results.length === 0) {
      return { content: [{ type: 'text', text: `No information found about "${topic}".` }] };
    }
    return { content: [{ type: 'text', text: results.join('\n\n') }] };
  },
);

// -- memory.reflections ------------------------------------------------------
server.tool(
  'memory_reflections',
  'Store or retrieve behavioral reflections and patterns. Write mode stores a reflection; read mode returns stored patterns.',
  {
    action: z.enum(['read', 'write']).describe('"read" to get patterns, "write" to store a reflection'),
    content: z.string().optional().describe('Reflection content (required for write)'),
  },
  async ({ action, content }) => {
    if (action === 'read') {
      const patterns = readContextFile('context/patterns.md');
      return { content: [{ type: 'text', text: patterns || 'No patterns file found.' }] };
    } else {
      if (!content) {
        return { content: [{ type: 'text', text: 'Content required for write.' }], isError: true };
      }
      // Append to patterns file
      const existing = readContextFile('context/patterns.md') || '# Behavioral Patterns\n';
      const timestamp = new Date().toISOString().split('T')[0];
      writeContextFile('context/patterns.md', `${existing}\n\n## ${timestamp}\n${content}`);
      return { content: [{ type: 'text', text: 'Reflection stored in context/patterns.md.' }] };
    }
  },
);

// -- memory.deep_context -----------------------------------------------------
server.tool(
  'memory_deep_context',
  'Load comprehensive context for a topic — combines entity search, context files, and related people.',
  {
    topic: z.string().describe('Topic to build deep context for'),
  },
  async ({ topic }) => {
    const sections = [];

    // Entity search across all types
    const allTypes = ['mc_event', 'commitment', 'person', 'skill'];
    for (const t of allTypes) {
      try {
        const data = await apiGetEntities(t);
        const matches = (data.data || []).filter(e => {
          const attrs = e.attributes || {};
          return Object.values(attrs).some(v =>
            typeof v === 'string' && v.toLowerCase().includes(topic.toLowerCase())
          );
        }).slice(0, 5);
        if (matches.length > 0) {
          sections.push(`## ${t} entities\n${JSON.stringify(matches, null, 2)}`);
        }
      } catch { /* skip */ }
    }

    // Context files
    const contextDirs = ['context', 'people', 'projects'];
    for (const dir of contextDirs) {
      const fullDir = path.join(PROJECT_ROOT, dir);
      if (!fs.existsSync(fullDir)) continue;
      try {
        const files = fs.readdirSync(fullDir).filter(f => f.endsWith('.md'));
        for (const f of files) {
          const content = fs.readFileSync(path.join(fullDir, f), 'utf8');
          if (content.toLowerCase().includes(topic.toLowerCase())) {
            sections.push(`## ${dir}/${f}\n${content}`);
          }
        }
      } catch { /* skip */ }
    }

    if (sections.length === 0) {
      return { content: [{ type: 'text', text: `No deep context found for "${topic}".` }] };
    }
    return { content: [{ type: 'text', text: `# Deep Context: ${topic}\n\n${sections.join('\n\n')}` }] };
  },
);

// -- memory.relate -----------------------------------------------------------
server.tool(
  'memory_relate',
  'Store a relationship between two entities or concepts by recording it as an event.',
  {
    subject: z.string().describe('First entity/concept'),
    relation: z.string().describe('Relationship type, e.g. "works_with", "interested_in", "committed_to"'),
    object: z.string().describe('Second entity/concept'),
    notes: z.string().optional().describe('Additional context about the relationship'),
  },
  async ({ subject, relation, object, notes }) => {
    try {
      await apiPost('/api/ingest', {
        source: 'claudia-memory',
        type: 'relationship',
        payload: { subject, relation, object, notes: notes || '' },
      });
      return { content: [{ type: 'text', text: `Relationship stored: ${subject} —[${relation}]→ ${object}` }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.batch ------------------------------------------------------------
server.tool(
  'memory_batch',
  'Store multiple memories at once. Each item is ingested as a separate event.',
  {
    items: z.array(z.object({
      type: z.string(),
      content: z.string(),
      source: z.string().optional(),
    })).describe('Array of items to remember'),
  },
  async ({ items }) => {
    const results = [];
    for (const item of items) {
      try {
        await apiPost('/api/ingest', {
          source: item.source || 'claudia-memory',
          type: item.type,
          payload: { content: item.content },
        });
        results.push(`✓ ${item.type}: ${item.content.slice(0, 50)}...`);
      } catch (e) {
        results.push(`✗ ${item.type}: ${e.message}`);
      }
    }
    return { content: [{ type: 'text', text: results.join('\n') }] };
  },
);

// -- memory.buffer_turn ------------------------------------------------------
server.tool(
  'memory_buffer_turn',
  'Buffer a conversation turn for later consolidation. Stores as a session event.',
  {
    role: z.enum(['user', 'assistant']).describe('Who said this'),
    content: z.string().describe('The message content'),
    session_id: z.string().optional().describe('Session identifier'),
  },
  async ({ role, content, session_id }) => {
    try {
      await apiPost('/api/ingest', {
        source: 'claudia-session',
        type: 'conversation_turn',
        payload: { role, content, session_id: session_id || 'unknown' },
      });
      return { content: [{ type: 'text', text: 'Turn buffered.' }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.correct ----------------------------------------------------------
server.tool(
  'memory_correct',
  'Record a correction to previously stored information.',
  {
    original: z.string().describe('What was originally stored (approximate)'),
    correction: z.string().describe('The corrected information'),
    reason: z.string().optional().describe('Why the correction was made'),
  },
  async ({ original, correction, reason }) => {
    try {
      await apiPost('/api/ingest', {
        source: 'claudia-memory',
        type: 'correction',
        payload: { original, correction, reason: reason || '' },
      });
      return { content: [{ type: 'text', text: 'Correction recorded.' }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.invalidate -------------------------------------------------------
server.tool(
  'memory_invalidate',
  'Mark a previously stored memory as invalid or outdated.',
  {
    description: z.string().describe('Description of what to invalidate'),
    reason: z.string().optional().describe('Why this is being invalidated'),
  },
  async ({ description, reason }) => {
    try {
      await apiPost('/api/ingest', {
        source: 'claudia-memory',
        type: 'invalidation',
        payload: { description, reason: reason || '' },
      });
      return { content: [{ type: 'text', text: `Invalidated: ${description}` }] };
    } catch (e) {
      return { content: [{ type: 'text', text: `Error: ${e.message}` }], isError: true };
    }
  },
);

// -- memory.documents --------------------------------------------------------
server.tool(
  'memory_documents',
  'List available context documents across context/, people/, and projects/ directories.',
  {},
  async () => {
    const dirs = ['context', 'people', 'projects'];
    const results = {};

    for (const dir of dirs) {
      const fullDir = path.join(PROJECT_ROOT, dir);
      if (!fs.existsSync(fullDir)) {
        results[dir] = [];
        continue;
      }
      try {
        results[dir] = fs.readdirSync(fullDir).filter(f => !f.startsWith('.'));
      } catch {
        results[dir] = [];
      }
    }

    const lines = Object.entries(results).flatMap(([dir, files]) =>
      files.length > 0
        ? [`## ${dir}/`, ...files.map(f => `  - ${f}`)]
        : [`## ${dir}/ (empty)`]
    );

    return { content: [{ type: 'text', text: lines.join('\n') }] };
  },
);

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

const transport = new StdioServerTransport();
await server.connect(transport);
