#!/usr/bin/env node
/**
 * Claudriel spec retrieval MCP server.
 * Exposes docs/specs/ as searchable cold memory for AI sessions.
 */
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SPECS_DIR = path.resolve(__dirname, '../../docs/specs');

function listSpecs() {
  return fs.readdirSync(SPECS_DIR)
    .filter(f => f.endsWith('.md'))
    .map(f => f.replace(/\.md$/, ''));
}

function readSpec(name) {
  const file = path.join(SPECS_DIR, `${name}.md`);
  if (!fs.existsSync(file)) return null;
  return fs.readFileSync(file, 'utf8');
}

function searchSpecs(query, maxResults = 10) {
  const lower = query.toLowerCase();
  const results = [];
  for (const name of listSpecs()) {
    const content = readSpec(name);
    if (!content) continue;
    const lines = content.split('\n');
    const matches = [];
    lines.forEach((line, i) => {
      if (line.toLowerCase().includes(lower)) {
        const start = Math.max(0, i - 1);
        const end = Math.min(lines.length - 1, i + 2);
        matches.push(lines.slice(start, end + 1).join('\n'));
      }
    });
    if (matches.length > 0) {
      results.push({ spec: name, matches: matches.slice(0, maxResults) });
    }
  }
  return results;
}

const server = new McpServer({
  name: 'claudriel-specs',
  version: '1.0.0',
});

server.tool(
  'claudriel_list_specs',
  'List all Claudriel subsystem specification documents. Use this to discover which specs are available before retrieving one.',
  {},
  async () => {
    const specs = listSpecs();
    const rows = specs.map(s => `| ${s} | docs/specs/${s}.md |`).join('\n');
    return {
      content: [{
        type: 'text',
        text: `# Available Claudriel Specs\n\n| Name | Path |\n|------|------|\n${rows}`,
      }],
    };
  },
);

server.tool(
  'claudriel_get_spec',
  'Retrieve the full content of a Claudriel subsystem spec. Use when you need deep implementation details for a subsystem.',
  { name: z.string().describe("Spec name without .md extension, e.g. 'entity', 'ingestion', 'day-brief', 'pipeline', 'web-cli', 'workflow'") },
  async ({ name }) => {
    const content = readSpec(name);
    if (!content) {
      const available = listSpecs().join(', ');
      return {
        content: [{
          type: 'text',
          text: `Spec '${name}' not found. Available specs: ${available}`,
        }],
        isError: true,
      };
    }
    return { content: [{ type: 'text', text: content }] };
  },
);

server.tool(
  'claudriel_search_specs',
  'Search all Claudriel specs for a keyword or phrase. Use when you need to find which spec covers a specific class, method, or concept.',
  {
    query: z.string().describe('Search term, e.g. class name, method name, or concept like "confidence threshold"'),
    max_results: z.number().optional().describe('Max matches per spec (default 10)'),
  },
  async ({ query, max_results = 10 }) => {
    const results = searchSpecs(query, max_results);
    if (results.length === 0) {
      return {
        content: [{
          type: 'text',
          text: `No matches for '${query}' in any spec. Try a shorter keyword.`,
        }],
      };
    }
    const text = results.map(r =>
      `## ${r.spec}\n\`\`\`\n${r.matches.join('\n---\n')}\n\`\`\``
    ).join('\n\n');
    return { content: [{ type: 'text', text: `# Search results for '${query}'\n\n${text}` }] };
  },
);

const transport = new StdioServerTransport();
await server.connect(transport);
