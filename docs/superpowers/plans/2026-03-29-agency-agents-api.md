# Agency-Agents API Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a TypeScript/Node API layer to the agency-agents repo that indexes 144 specialist prompts and executes them via Claude API, exposed through REST (Hono + SSE) and MCP interfaces.

**Architecture:** Prompt catalog scans markdown files at startup into an in-memory index. REST endpoints serve the catalog and stream agent executions via SSE. MCP server wraps the same catalog and execution engine, returning summary-only results. Unified error model across all interfaces.

**Tech Stack:** TypeScript, Hono (REST + SSE), @anthropic-ai/sdk (Claude API), @modelcontextprotocol/sdk (MCP), Zod (validation), Vitest (testing), gray-matter (frontmatter parsing)

---

## File Structure

```
api/
  package.json
  tsconfig.json
  vitest.config.ts
  config.default.yaml
  src/
    index.ts                    # entry point, wires catalog + REST + MCP
    config.ts                   # config loader (yaml + env)
    types.ts                    # shared types (AgentEntry, ApiError, Summary)
    catalog/
      scanner.ts                # scans markdown files, extracts metadata
      catalog.ts                # in-memory index with search/filter
      scanner.test.ts
      catalog.test.ts
    execution/
      engine.ts                 # Claude API execution with streaming
      result-extractor.ts       # extracts <result> JSON from completion
      engine.test.ts
      result-extractor.test.ts
    rest/
      app.ts                    # Hono app with all routes
      agents.ts                 # GET /v1/agents, GET /v1/agents/:slug
      execute.ts                # POST /v1/agents/:slug/execute (SSE)
      health.ts                 # GET /v1/health
      error-handler.ts          # unified error middleware
      agents.test.ts
      execute.test.ts
      health.test.ts
    mcp/
      server.ts                 # MCP server with 3 tools
      server.test.ts
    observability/
      logger.ts                 # structured JSON logger
      metrics.ts                # MetricsEmitter interface + console impl
  Dockerfile
```

---

### Task 1: Fork and Scaffold

**Files:**
- Create: `api/package.json`
- Create: `api/tsconfig.json`
- Create: `api/vitest.config.ts`

- [ ] **Step 1: Fork the repo on GitHub**

```bash
gh repo fork msitarzewski/agency-agents --clone=true --remote=true
cd agency-agents
```

- [ ] **Step 2: Create api/ directory and initialize**

```bash
mkdir -p api/src/{catalog,execution,rest,mcp,observability}
cd api
```

- [ ] **Step 3: Create package.json**

```json
{
  "name": "agency-agents-api",
  "version": "0.1.0",
  "type": "module",
  "scripts": {
    "dev": "tsx watch src/index.ts",
    "build": "tsc",
    "start": "node dist/index.js",
    "test": "vitest run",
    "test:watch": "vitest"
  },
  "dependencies": {
    "@anthropic-ai/sdk": "^0.52.0",
    "@modelcontextprotocol/sdk": "^1.27.0",
    "hono": "^4.11.0",
    "@hono/node-server": "^1.14.0",
    "gray-matter": "^4.0.3",
    "yaml": "^2.7.0",
    "zod": "^3.24.0"
  },
  "devDependencies": {
    "tsx": "^4.19.0",
    "typescript": "^5.7.0",
    "vitest": "^3.0.0",
    "@types/node": "^22.0.0"
  }
}
```

- [ ] **Step 4: Create tsconfig.json**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "outDir": "dist",
    "rootDir": "src",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "resolveJsonModule": true,
    "declaration": true,
    "sourceMap": true
  },
  "include": ["src/**/*"],
  "exclude": ["node_modules", "dist"]
}
```

- [ ] **Step 5: Create vitest.config.ts**

```typescript
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: true,
    include: ['src/**/*.test.ts'],
  },
});
```

- [ ] **Step 6: Install dependencies and verify**

```bash
npm install
npx tsc --noEmit
```

Expected: Clean install, no type errors.

- [ ] **Step 7: Commit**

```bash
git add api/
git commit -m "feat(api): scaffold TypeScript project with Hono, MCP SDK, Anthropic SDK"
```

---

### Task 2: Shared Types

**Files:**
- Create: `api/src/types.ts`

- [ ] **Step 1: Write types.ts**

```typescript
export interface AgentEntry {
  slug: string;
  name: string;
  division: string;
  specialty: string;
  whenToUse: string;
  emoji: string;
  promptPath: string;
  promptContent: string;
}

export interface AgentSummary {
  slug: string;
  name: string;
  division: string;
  specialty: string;
  whenToUse: string;
  emoji: string;
}

export interface ApiError {
  code: string;
  message: string;
  details: Record<string, unknown>;
}

export class AgentApiError extends Error {
  constructor(
    public code: string,
    public statusCode: number,
    message: string,
    public details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'AgentApiError';
  }

  toJSON(): { error: ApiError } {
    return {
      error: {
        code: this.code,
        message: this.message,
        details: this.details,
      },
    };
  }
}

export interface ExecutionResult {
  version: 'v1';
  agent: string;
  task: string;
  result: Record<string, unknown>;
  metadata: ExecutionMetadata;
}

export interface ExecutionMetadata {
  model: string;
  tokens_in: number;
  tokens_out: number;
  duration_ms: number;
  execution_id: string;
}

export interface ExecutionConfig {
  default_model: string;
  max_tokens: number;
  temperature: number;
  timeout_ms: number;
  agent_overrides: Record<string, Partial<Omit<ExecutionConfig, 'agent_overrides'>>>;
}

export interface AppConfig {
  port: number;
  log_level: 'debug' | 'info' | 'warn' | 'error';
  prompts_dir: string;
  execution: ExecutionConfig;
}
```

- [ ] **Step 2: Verify types compile**

```bash
cd api && npx tsc --noEmit
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add api/src/types.ts
git commit -m "feat(api): add shared types for catalog, execution, errors, config"
```

---

### Task 3: Prompt Scanner

**Files:**
- Create: `api/src/catalog/scanner.ts`
- Create: `api/src/catalog/scanner.test.ts`

- [ ] **Step 1: Create a test fixture**

Create a minimal test prompt file that mirrors real agency-agents format:

```bash
mkdir -p api/src/catalog/__fixtures__/sales
```

Write `api/src/catalog/__fixtures__/sales/sales-deal-strategist.md`:

```markdown
---
name: Deal Strategist
description: MEDDPICC qualification and win planning
color: blue
emoji: ♟️
vibe: strategic and analytical
---

## Role

You are a Deal Strategist specializing in MEDDPICC qualification.

## When to Use

Scoring deals, exposing pipeline risk, building win strategies.

## Core Framework

Apply MEDDPICC to every deal review.
```

- [ ] **Step 2: Write the failing test**

Write `api/src/catalog/scanner.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { scanPrompts } from './scanner.js';
import { join } from 'path';

const FIXTURES = join(import.meta.dirname, '__fixtures__');

describe('scanPrompts', () => {
  it('scans markdown files and extracts metadata', async () => {
    const entries = await scanPrompts(FIXTURES);

    expect(entries).toHaveLength(1);
    expect(entries[0]).toMatchObject({
      slug: 'sales-deal-strategist',
      name: 'Deal Strategist',
      division: 'sales',
      emoji: '♟️',
    });
    expect(entries[0].promptContent).toContain('MEDDPICC');
    expect(entries[0].promptPath).toContain('sales/sales-deal-strategist.md');
  });

  it('extracts specialty from description frontmatter', async () => {
    const entries = await scanPrompts(FIXTURES);
    expect(entries[0].specialty).toBe('MEDDPICC qualification and win planning');
  });

  it('extracts whenToUse from ## When to Use section', async () => {
    const entries = await scanPrompts(FIXTURES);
    expect(entries[0].whenToUse).toContain('Scoring deals');
  });

  it('returns empty array for directory with no markdown', async () => {
    const entries = await scanPrompts('/tmp');
    expect(entries).toEqual([]);
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

```bash
cd api && npx vitest run src/catalog/scanner.test.ts
```

Expected: FAIL — `scanPrompts` not found.

- [ ] **Step 4: Implement scanner**

Write `api/src/catalog/scanner.ts`:

```typescript
import { readdir, readFile } from 'fs/promises';
import { join, relative, basename, dirname } from 'path';
import matter from 'gray-matter';
import type { AgentEntry } from '../types.js';

const SKIP_DIRS = new Set([
  'api', 'node_modules', '.git', '.github', 'scripts',
  'integrations', 'examples',
]);

export async function scanPrompts(baseDir: string): Promise<AgentEntry[]> {
  const entries: AgentEntry[] = [];
  await walkDir(baseDir, baseDir, entries);
  return entries.sort((a, b) => a.slug.localeCompare(b.slug));
}

async function walkDir(
  dir: string,
  baseDir: string,
  entries: AgentEntry[],
): Promise<void> {
  let items: string[];
  try {
    items = await readdir(dir);
  } catch {
    return;
  }

  for (const item of items) {
    const fullPath = join(dir, item);
    const stat = await import('fs/promises').then((fs) => fs.stat(fullPath));

    if (stat.isDirectory()) {
      if (!SKIP_DIRS.has(item)) {
        await walkDir(fullPath, baseDir, entries);
      }
      continue;
    }

    if (!item.endsWith('.md') || item.toUpperCase() === 'README.MD') {
      continue;
    }

    const raw = await readFile(fullPath, 'utf-8');
    const { data: frontmatter, content } = matter(raw);

    const relPath = relative(baseDir, fullPath);
    const division = dirname(relPath).split('/')[0];
    const stem = basename(item, '.md');
    const slug = division === '.' ? stem : `${division}-${stem}`;

    entries.push({
      slug,
      name: frontmatter.name ?? stem,
      division: division === '.' ? 'uncategorized' : division,
      specialty: frontmatter.description ?? '',
      whenToUse: extractSection(content, 'When to Use'),
      emoji: frontmatter.emoji ?? '',
      promptPath: relPath,
      promptContent: raw,
    });
  }
}

function extractSection(content: string, heading: string): string {
  const pattern = new RegExp(
    `^##\\s+(?:\\S+\\s+)?${heading}\\s*$`,
    'im',
  );
  const match = pattern.exec(content);
  if (!match) return '';

  const start = match.index + match[0].length;
  const nextHeading = content.indexOf('\n## ', start);
  const section =
    nextHeading === -1
      ? content.slice(start)
      : content.slice(start, nextHeading);

  return section.trim();
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd api && npx vitest run src/catalog/scanner.test.ts
```

Expected: All 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add api/src/catalog/
git commit -m "feat(api): prompt scanner — walks directories, extracts frontmatter and metadata"
```

---

### Task 4: Catalog Index

**Files:**
- Create: `api/src/catalog/catalog.ts`
- Create: `api/src/catalog/catalog.test.ts`

- [ ] **Step 1: Write the failing test**

Write `api/src/catalog/catalog.test.ts`:

```typescript
import { describe, it, expect, beforeEach } from 'vitest';
import { Catalog } from './catalog.js';
import type { AgentEntry } from '../types.js';

const AGENTS: AgentEntry[] = [
  {
    slug: 'sales-deal-strategist',
    name: 'Deal Strategist',
    division: 'sales',
    specialty: 'MEDDPICC qualification',
    whenToUse: 'Scoring deals, pipeline risk',
    emoji: '♟️',
    promptPath: 'sales/sales-deal-strategist.md',
    promptContent: '# Deal Strategist\nYou are...',
  },
  {
    slug: 'product-feedback-synthesizer',
    name: 'Feedback Synthesizer',
    division: 'product',
    specialty: 'User feedback analysis',
    whenToUse: 'Feedback analysis, user insights',
    emoji: '💬',
    promptPath: 'product/product-feedback-synthesizer.md',
    promptContent: '# Feedback Synthesizer\nYou are...',
  },
  {
    slug: 'sales-pipeline-analyst',
    name: 'Pipeline Analyst',
    division: 'sales',
    specialty: 'Forecasting, pipeline health',
    whenToUse: 'Pipeline reviews, forecast accuracy',
    emoji: '📊',
    promptPath: 'sales/sales-pipeline-analyst.md',
    promptContent: '# Pipeline Analyst\nYou are...',
  },
];

describe('Catalog', () => {
  let catalog: Catalog;

  beforeEach(() => {
    catalog = new Catalog(AGENTS);
  });

  it('returns all agents', () => {
    const result = catalog.list({});
    expect(result.agents).toHaveLength(3);
    expect(result.total).toBe(3);
  });

  it('filters by division', () => {
    const result = catalog.list({ division: 'sales' });
    expect(result.agents).toHaveLength(2);
    expect(result.agents.every((a) => a.division === 'sales')).toBe(true);
  });

  it('searches by keyword', () => {
    const result = catalog.list({ q: 'feedback' });
    expect(result.agents).toHaveLength(1);
    expect(result.agents[0].slug).toBe('product-feedback-synthesizer');
  });

  it('paginates with limit and offset', () => {
    const result = catalog.list({ limit: 1, offset: 1 });
    expect(result.agents).toHaveLength(1);
    expect(result.total).toBe(3);
  });

  it('gets agent by slug', () => {
    const agent = catalog.get('sales-deal-strategist');
    expect(agent).toBeDefined();
    expect(agent!.name).toBe('Deal Strategist');
    expect(agent!.promptContent).toContain('Deal Strategist');
  });

  it('returns undefined for unknown slug', () => {
    expect(catalog.get('nonexistent')).toBeUndefined();
  });

  it('reports count', () => {
    expect(catalog.count).toBe(3);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && npx vitest run src/catalog/catalog.test.ts
```

Expected: FAIL — `Catalog` not found.

- [ ] **Step 3: Implement Catalog**

Write `api/src/catalog/catalog.ts`:

```typescript
import type { AgentEntry, AgentSummary } from '../types.js';

interface ListOptions {
  division?: string;
  q?: string;
  limit?: number;
  offset?: number;
}

interface ListResult {
  agents: AgentSummary[];
  total: number;
  limit: number;
  offset: number;
}

export class Catalog {
  private readonly agents: Map<string, AgentEntry>;
  private readonly sorted: AgentEntry[];

  constructor(entries: AgentEntry[]) {
    this.agents = new Map(entries.map((e) => [e.slug, e]));
    this.sorted = [...entries].sort((a, b) => a.slug.localeCompare(b.slug));
  }

  get count(): number {
    return this.agents.size;
  }

  list(options: ListOptions): ListResult {
    const { division, q, limit = 20, offset = 0 } = options;

    let filtered = this.sorted;

    if (division) {
      filtered = filtered.filter((a) => a.division === division);
    }

    if (q) {
      const lower = q.toLowerCase();
      filtered = filtered.filter(
        (a) =>
          a.name.toLowerCase().includes(lower) ||
          a.specialty.toLowerCase().includes(lower) ||
          a.whenToUse.toLowerCase().includes(lower),
      );
    }

    const total = filtered.length;
    const page = filtered.slice(offset, offset + limit);

    return {
      agents: page.map(toSummary),
      total,
      limit,
      offset,
    };
  }

  get(slug: string): AgentEntry | undefined {
    return this.agents.get(slug);
  }
}

function toSummary(entry: AgentEntry): AgentSummary {
  return {
    slug: entry.slug,
    name: entry.name,
    division: entry.division,
    specialty: entry.specialty,
    whenToUse: entry.whenToUse,
    emoji: entry.emoji,
  };
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd api && npx vitest run src/catalog/catalog.test.ts
```

Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add api/src/catalog/catalog.ts api/src/catalog/catalog.test.ts
git commit -m "feat(api): catalog index — in-memory search, filter, pagination"
```

---

### Task 5: Result Extractor

**Files:**
- Create: `api/src/execution/result-extractor.ts`
- Create: `api/src/execution/result-extractor.test.ts`

- [ ] **Step 1: Write the failing test**

Write `api/src/execution/result-extractor.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { extractResult } from './result-extractor.js';

describe('extractResult', () => {
  it('extracts JSON from <result> tags', () => {
    const text = `Here is my analysis.

<result>
{"score": 0.8, "risks": ["no champion"]}
</result>`;

    const result = extractResult(text);
    expect(result).toEqual({ score: 0.8, risks: ['no champion'] });
  });

  it('returns raw text wrapper when no <result> tags found', () => {
    const text = 'Just a plain response with no structured output.';
    const result = extractResult(text);
    expect(result).toEqual({ analysis: text });
  });

  it('handles malformed JSON inside <result> tags', () => {
    const text = '<result>\nnot valid json\n</result>';
    const result = extractResult(text);
    expect(result).toEqual({ analysis: text });
  });

  it('extracts only the first <result> block', () => {
    const text = `First block:
<result>{"a": 1}</result>
Second block:
<result>{"b": 2}</result>`;

    const result = extractResult(text);
    expect(result).toEqual({ a: 1 });
  });

  it('handles multiline JSON in <result> tags', () => {
    const text = `Analysis complete.

<result>
{
  "qualification_score": 0.65,
  "risks": [
    "No champion identified",
    "Budget unclear"
  ],
  "next_steps": ["Schedule discovery call"]
}
</result>`;

    const result = extractResult(text);
    expect(result.qualification_score).toBe(0.65);
    expect(result.risks).toHaveLength(2);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && npx vitest run src/execution/result-extractor.test.ts
```

Expected: FAIL — `extractResult` not found.

- [ ] **Step 3: Implement result extractor**

Write `api/src/execution/result-extractor.ts`:

```typescript
const RESULT_PATTERN = /<result>\s*([\s\S]*?)\s*<\/result>/i;

export function extractResult(text: string): Record<string, unknown> {
  const match = RESULT_PATTERN.exec(text);

  if (!match) {
    return { analysis: text };
  }

  try {
    return JSON.parse(match[1]) as Record<string, unknown>;
  } catch {
    return { analysis: text };
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd api && npx vitest run src/execution/result-extractor.test.ts
```

Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add api/src/execution/
git commit -m "feat(api): result extractor — parses <result> JSON from agent completions"
```

---

### Task 6: Config Loader

**Files:**
- Create: `api/src/config.ts`
- Create: `api/config.default.yaml`

- [ ] **Step 1: Create default config**

Write `api/config.default.yaml`:

```yaml
port: 3100
log_level: info
prompts_dir: ..

execution:
  default_model: claude-sonnet-4-6
  max_tokens: 4096
  temperature: 0.7
  timeout_ms: 60000
  agent_overrides: {}
```

- [ ] **Step 2: Implement config loader**

Write `api/src/config.ts`:

```typescript
import { readFileSync } from 'fs';
import { parse } from 'yaml';
import { join } from 'path';
import type { AppConfig } from './types.js';

export function loadConfig(): AppConfig {
  const configPath =
    process.env.CONFIG_PATH ?? join(import.meta.dirname, '..', 'config.default.yaml');

  const raw = readFileSync(configPath, 'utf-8');
  const file = parse(raw) as Partial<AppConfig>;

  return {
    port: parseInt(process.env.PORT ?? String(file.port ?? 3100), 10),
    log_level: (process.env.LOG_LEVEL ?? file.log_level ?? 'info') as AppConfig['log_level'],
    prompts_dir: process.env.PROMPTS_DIR ?? file.prompts_dir ?? '..',
    execution: {
      default_model:
        process.env.DEFAULT_MODEL ?? file.execution?.default_model ?? 'claude-sonnet-4-6',
      max_tokens: file.execution?.max_tokens ?? 4096,
      temperature: file.execution?.temperature ?? 0.7,
      timeout_ms: file.execution?.timeout_ms ?? 60000,
      agent_overrides: file.execution?.agent_overrides ?? {},
    },
  };
}
```

- [ ] **Step 3: Verify it compiles**

```bash
cd api && npx tsc --noEmit
```

Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add api/src/config.ts api/config.default.yaml
git commit -m "feat(api): config loader — yaml + env var overrides"
```

---

### Task 7: Logger

**Files:**
- Create: `api/src/observability/logger.ts`
- Create: `api/src/observability/metrics.ts`

- [ ] **Step 1: Implement structured logger**

Write `api/src/observability/logger.ts`:

```typescript
type LogLevel = 'debug' | 'info' | 'warn' | 'error';

const LEVELS: Record<LogLevel, number> = {
  debug: 0,
  info: 1,
  warn: 2,
  error: 3,
};

let minLevel: LogLevel = 'info';

export function setLogLevel(level: LogLevel): void {
  minLevel = level;
}

export function log(
  level: LogLevel,
  event: string,
  data: Record<string, unknown> = {},
): void {
  if (LEVELS[level] < LEVELS[minLevel]) return;

  const entry = {
    level,
    event,
    timestamp: new Date().toISOString(),
    ...data,
  };

  const output = JSON.stringify(entry);

  if (level === 'error') {
    process.stderr.write(output + '\n');
  } else {
    process.stdout.write(output + '\n');
  }
}
```

- [ ] **Step 2: Implement metrics interface**

Write `api/src/observability/metrics.ts`:

```typescript
export interface MetricsEmitter {
  executionComplete(data: {
    agent: string;
    model: string;
    tokens_in: number;
    tokens_out: number;
    duration_ms: number;
    status: 'success' | 'error';
  }): void;
}

export class ConsoleMetricsEmitter implements MetricsEmitter {
  executionComplete(data: {
    agent: string;
    model: string;
    tokens_in: number;
    tokens_out: number;
    duration_ms: number;
    status: 'success' | 'error';
  }): void {
    const entry = {
      level: 'info',
      event: 'execution_complete',
      timestamp: new Date().toISOString(),
      ...data,
    };
    process.stdout.write(JSON.stringify(entry) + '\n');
  }
}
```

- [ ] **Step 3: Verify compilation**

```bash
cd api && npx tsc --noEmit
```

Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add api/src/observability/
git commit -m "feat(api): observability — structured logger + metrics emitter interface"
```

---

### Task 8: Execution Engine

**Files:**
- Create: `api/src/execution/engine.ts`
- Create: `api/src/execution/engine.test.ts`

- [ ] **Step 1: Write the failing test**

Write `api/src/execution/engine.test.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { ExecutionEngine } from './engine.js';
import type { ExecutionConfig, AgentEntry } from '../types.js';
import { ConsoleMetricsEmitter } from '../observability/metrics.js';

const MOCK_AGENT: AgentEntry = {
  slug: 'sales-deal-strategist',
  name: 'Deal Strategist',
  division: 'sales',
  specialty: 'MEDDPICC',
  whenToUse: 'Deal scoring',
  emoji: '♟️',
  promptPath: 'sales/sales-deal-strategist.md',
  promptContent: '---\nname: Deal Strategist\n---\n\nYou are a deal strategist.',
};

const CONFIG: ExecutionConfig = {
  default_model: 'claude-sonnet-4-6',
  max_tokens: 4096,
  temperature: 0.7,
  timeout_ms: 60000,
  agent_overrides: {},
};

describe('ExecutionEngine', () => {
  it('constructs the system prompt with result extraction instruction', () => {
    const engine = new ExecutionEngine(CONFIG, new ConsoleMetricsEmitter());
    const prompt = engine.buildSystemPrompt(MOCK_AGENT);

    expect(prompt).toContain('You are a deal strategist');
    expect(prompt).toContain('<result>');
    expect(prompt).toContain('JSON');
  });

  it('resolves model from config with agent override', () => {
    const config: ExecutionConfig = {
      ...CONFIG,
      agent_overrides: {
        'sales-deal-strategist': { default_model: 'claude-opus-4-6' },
      },
    };
    const engine = new ExecutionEngine(config, new ConsoleMetricsEmitter());
    const model = engine.resolveModel('sales-deal-strategist');
    expect(model).toBe('claude-opus-4-6');
  });

  it('falls back to default model when no override', () => {
    const engine = new ExecutionEngine(CONFIG, new ConsoleMetricsEmitter());
    const model = engine.resolveModel('sales-deal-strategist');
    expect(model).toBe('claude-sonnet-4-6');
  });

  it('resolves max_tokens from agent override', () => {
    const config: ExecutionConfig = {
      ...CONFIG,
      agent_overrides: {
        'sales-deal-strategist': { max_tokens: 8192 },
      },
    };
    const engine = new ExecutionEngine(config, new ConsoleMetricsEmitter());
    const tokens = engine.resolveMaxTokens('sales-deal-strategist');
    expect(tokens).toBe(8192);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && npx vitest run src/execution/engine.test.ts
```

Expected: FAIL — `ExecutionEngine` not found.

- [ ] **Step 3: Implement execution engine**

Write `api/src/execution/engine.ts`:

```typescript
import Anthropic from '@anthropic-ai/sdk';
import { extractResult } from './result-extractor.js';
import { log } from '../observability/logger.js';
import type {
  AgentEntry,
  ExecutionConfig,
  ExecutionResult,
  ExecutionMetadata,
} from '../types.js';
import type { MetricsEmitter } from '../observability/metrics.js';

const RESULT_INSTRUCTION = `

---

After completing the task, end your response with a JSON block wrapped in <result>...</result> tags containing your key findings in a structured format. Example:

<result>
{"key_finding": "value", "recommendations": ["item1", "item2"]}
</result>`;

export class ExecutionEngine {
  private readonly client: Anthropic;
  private readonly config: ExecutionConfig;
  private readonly metrics: MetricsEmitter;

  constructor(config: ExecutionConfig, metrics: MetricsEmitter) {
    this.config = config;
    this.metrics = metrics;
    this.client = new Anthropic();
  }

  buildSystemPrompt(agent: AgentEntry): string {
    return agent.promptContent + RESULT_INSTRUCTION;
  }

  resolveModel(slug: string): string {
    return this.config.agent_overrides[slug]?.default_model ?? this.config.default_model;
  }

  resolveMaxTokens(slug: string): number {
    return this.config.agent_overrides[slug]?.max_tokens ?? this.config.max_tokens;
  }

  async *executeStream(
    agent: AgentEntry,
    task: string,
    context: Record<string, unknown> = {},
    modelOverride?: string,
  ): AsyncGenerator<
    | { type: 'token'; data: string }
    | { type: 'log'; data: Record<string, unknown> }
    | { type: 'error'; data: { code: string; message: string; details: Record<string, unknown> } }
    | { type: 'summary'; data: ExecutionResult }
  > {
    const model = modelOverride ?? this.resolveModel(agent.slug);
    const maxTokens = this.resolveMaxTokens(agent.slug);
    const executionId = `exec_${Date.now().toString(36)}`;
    const startTime = Date.now();

    yield {
      type: 'log',
      data: { type: 'started', agent: agent.slug, model, execution_id: executionId },
    };

    let fullText = '';
    let tokensIn = 0;
    let tokensOut = 0;

    try {
      const userMessage = context && Object.keys(context).length > 0
        ? `Context:\n${JSON.stringify(context, null, 2)}\n\nTask:\n${task}`
        : task;

      const stream = this.client.messages.stream({
        model,
        max_tokens: maxTokens,
        temperature: this.config.temperature,
        system: this.buildSystemPrompt(agent),
        messages: [{ role: 'user', content: userMessage }],
      });

      for await (const event of stream) {
        if (
          event.type === 'content_block_delta' &&
          event.delta.type === 'text_delta'
        ) {
          fullText += event.delta.text;
          yield { type: 'token', data: event.delta.text };
        }
      }

      const finalMessage = await stream.finalMessage();
      tokensIn = finalMessage.usage.input_tokens;
      tokensOut = finalMessage.usage.output_tokens;

      const durationMs = Date.now() - startTime;
      const result = extractResult(fullText);

      const metadata: ExecutionMetadata = {
        model,
        tokens_in: tokensIn,
        tokens_out: tokensOut,
        duration_ms: durationMs,
        execution_id: executionId,
      };

      this.metrics.executionComplete({
        agent: agent.slug,
        model,
        tokens_in: tokensIn,
        tokens_out: tokensOut,
        duration_ms: durationMs,
        status: 'success',
      });

      yield {
        type: 'summary',
        data: {
          version: 'v1',
          agent: agent.slug,
          task,
          result,
          metadata,
        },
      };
    } catch (err) {
      const durationMs = Date.now() - startTime;
      const message = err instanceof Error ? err.message : 'Unknown error';
      const code =
        err instanceof Anthropic.RateLimitError ? 'RATE_LIMITED' :
        err instanceof Anthropic.APIError ? 'MODEL_ERROR' :
        'INTERNAL_ERROR';

      log('error', 'execution_failed', {
        agent: agent.slug,
        execution_id: executionId,
        code,
        message,
      });

      this.metrics.executionComplete({
        agent: agent.slug,
        model,
        tokens_in: tokensIn,
        tokens_out: tokensOut,
        duration_ms: durationMs,
        status: 'error',
      });

      yield {
        type: 'error',
        data: { code, message, details: { execution_id: executionId } },
      };
    }
  }

  async execute(
    agent: AgentEntry,
    task: string,
    context: Record<string, unknown> = {},
    modelOverride?: string,
  ): Promise<ExecutionResult> {
    for await (const event of this.executeStream(agent, task, context, modelOverride)) {
      if (event.type === 'summary') return event.data;
      if (event.type === 'error') {
        throw new Error(`${event.data.code}: ${event.data.message}`);
      }
    }
    throw new Error('INTERNAL_ERROR: Stream ended without summary or error');
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd api && npx vitest run src/execution/engine.test.ts
```

Expected: All 4 tests PASS (these test the pure functions, not the API calls).

- [ ] **Step 5: Commit**

```bash
git add api/src/execution/engine.ts api/src/execution/engine.test.ts
git commit -m "feat(api): execution engine — Claude API streaming with result extraction"
```

---

### Task 9: REST API — Health + Agents Endpoints

**Files:**
- Create: `api/src/rest/app.ts`
- Create: `api/src/rest/health.ts`
- Create: `api/src/rest/agents.ts`
- Create: `api/src/rest/error-handler.ts`
- Create: `api/src/rest/agents.test.ts`
- Create: `api/src/rest/health.test.ts`

- [ ] **Step 1: Write health test**

Write `api/src/rest/health.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { createApp } from './app.js';
import { Catalog } from '../catalog/catalog.js';

describe('GET /v1/health', () => {
  it('returns ok with agent count', async () => {
    const catalog = new Catalog([]);
    const app = createApp(catalog, null as any);

    const res = await app.request('/v1/health');
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.status).toBe('ok');
    expect(body.agents_indexed).toBe(0);
  });
});
```

- [ ] **Step 2: Write agents test**

Write `api/src/rest/agents.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { createApp } from './app.js';
import { Catalog } from '../catalog/catalog.js';
import type { AgentEntry } from '../types.js';

const AGENTS: AgentEntry[] = [
  {
    slug: 'sales-deal-strategist',
    name: 'Deal Strategist',
    division: 'sales',
    specialty: 'MEDDPICC',
    whenToUse: 'Deal scoring',
    emoji: '♟️',
    promptPath: 'sales/sales-deal-strategist.md',
    promptContent: '# Deal Strategist\nYou are...',
  },
  {
    slug: 'product-feedback-synthesizer',
    name: 'Feedback Synthesizer',
    division: 'product',
    specialty: 'User feedback',
    whenToUse: 'Feedback analysis',
    emoji: '💬',
    promptPath: 'product/product-feedback-synthesizer.md',
    promptContent: '# Feedback Synthesizer\nYou are...',
  },
];

describe('GET /v1/agents', () => {
  const catalog = new Catalog(AGENTS);
  const app = createApp(catalog, null as any);

  it('lists all agents', async () => {
    const res = await app.request('/v1/agents');
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.version).toBe('v1');
    expect(body.agents).toHaveLength(2);
    expect(body.total).toBe(2);
  });

  it('filters by division', async () => {
    const res = await app.request('/v1/agents?division=sales');
    const body = await res.json();

    expect(body.agents).toHaveLength(1);
    expect(body.agents[0].slug).toBe('sales-deal-strategist');
  });

  it('searches by keyword', async () => {
    const res = await app.request('/v1/agents?q=feedback');
    const body = await res.json();

    expect(body.agents).toHaveLength(1);
    expect(body.agents[0].slug).toBe('product-feedback-synthesizer');
  });
});

describe('GET /v1/agents/:slug', () => {
  const catalog = new Catalog(AGENTS);
  const app = createApp(catalog, null as any);

  it('returns agent with prompt', async () => {
    const res = await app.request('/v1/agents/sales-deal-strategist');
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.version).toBe('v1');
    expect(body.agent.slug).toBe('sales-deal-strategist');
    expect(body.agent.prompt).toContain('Deal Strategist');
  });

  it('returns 404 for unknown slug', async () => {
    const res = await app.request('/v1/agents/nonexistent');
    const body = await res.json();

    expect(res.status).toBe(404);
    expect(body.error.code).toBe('AGENT_NOT_FOUND');
  });
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
cd api && npx vitest run src/rest/
```

Expected: FAIL — modules not found.

- [ ] **Step 4: Implement error handler**

Write `api/src/rest/error-handler.ts`:

```typescript
import type { Context } from 'hono';
import { AgentApiError } from '../types.js';

export function errorHandler(err: Error, c: Context): Response {
  if (err instanceof AgentApiError) {
    return c.json(err.toJSON(), err.statusCode as any);
  }

  return c.json(
    {
      error: {
        code: 'INTERNAL_ERROR',
        message: err.message,
        details: {},
      },
    },
    500,
  );
}
```

- [ ] **Step 5: Implement health route**

Write `api/src/rest/health.ts`:

```typescript
import { Hono } from 'hono';
import type { Catalog } from '../catalog/catalog.js';

const startTime = Date.now();

export function healthRoutes(catalog: Catalog): Hono {
  const app = new Hono();

  app.get('/v1/health', (c) => {
    return c.json({
      status: 'ok',
      agents_indexed: catalog.count,
      uptime_seconds: Math.floor((Date.now() - startTime) / 1000),
    });
  });

  return app;
}
```

- [ ] **Step 6: Implement agents routes**

Write `api/src/rest/agents.ts`:

```typescript
import { Hono } from 'hono';
import type { Catalog } from '../catalog/catalog.js';
import { AgentApiError } from '../types.js';

export function agentRoutes(catalog: Catalog): Hono {
  const app = new Hono();

  app.get('/v1/agents', (c) => {
    const division = c.req.query('division');
    const q = c.req.query('q');
    const limit = parseInt(c.req.query('limit') ?? '20', 10);
    const offset = parseInt(c.req.query('offset') ?? '0', 10);

    const result = catalog.list({ division, q, limit, offset });

    return c.json({
      version: 'v1' as const,
      ...result,
    });
  });

  app.get('/v1/agents/:slug', (c) => {
    const slug = c.req.param('slug');
    const agent = catalog.get(slug);

    if (!agent) {
      throw new AgentApiError(
        'AGENT_NOT_FOUND',
        404,
        `No agent with slug '${slug}'`,
      );
    }

    return c.json({
      version: 'v1' as const,
      agent: {
        slug: agent.slug,
        name: agent.name,
        division: agent.division,
        specialty: agent.specialty,
        when_to_use: agent.whenToUse,
        emoji: agent.emoji,
        prompt: agent.promptContent,
      },
    });
  });

  return app;
}
```

- [ ] **Step 7: Implement app.ts (wires routes together)**

Write `api/src/rest/app.ts`:

```typescript
import { Hono } from 'hono';
import { cors } from 'hono/cors';
import type { Catalog } from '../catalog/catalog.js';
import type { ExecutionEngine } from '../execution/engine.js';
import { healthRoutes } from './health.js';
import { agentRoutes } from './agents.js';
import { errorHandler } from './error-handler.js';

export function createApp(catalog: Catalog, engine: ExecutionEngine): Hono {
  const app = new Hono();

  app.use('*', cors());
  app.onError(errorHandler);

  app.route('/', healthRoutes(catalog));
  app.route('/', agentRoutes(catalog));

  return app;
}
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
cd api && npx vitest run src/rest/
```

Expected: All tests PASS.

- [ ] **Step 9: Commit**

```bash
git add api/src/rest/
git commit -m "feat(api): REST endpoints — health, list agents, get agent with error handling"
```

---

### Task 10: REST API — Execute Endpoint (SSE)

**Files:**
- Create: `api/src/rest/execute.ts`
- Create: `api/src/rest/execute.test.ts`

- [ ] **Step 1: Write the failing test**

Write `api/src/rest/execute.test.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { createApp } from './app.js';
import { Catalog } from '../catalog/catalog.js';
import type { AgentEntry, ExecutionResult } from '../types.js';

const AGENT: AgentEntry = {
  slug: 'sales-deal-strategist',
  name: 'Deal Strategist',
  division: 'sales',
  specialty: 'MEDDPICC',
  whenToUse: 'Deal scoring',
  emoji: '♟️',
  promptPath: 'sales/sales-deal-strategist.md',
  promptContent: '# Deal Strategist\nYou are...',
};

const MOCK_SUMMARY: ExecutionResult = {
  version: 'v1',
  agent: 'sales-deal-strategist',
  task: 'test task',
  result: { score: 0.8 },
  metadata: {
    model: 'claude-sonnet-4-6',
    tokens_in: 100,
    tokens_out: 200,
    duration_ms: 1000,
    execution_id: 'exec_test',
  },
};

function createMockEngine() {
  return {
    async *executeStream() {
      yield { type: 'token' as const, data: 'Hello ' };
      yield { type: 'token' as const, data: 'world' };
      yield { type: 'summary' as const, data: MOCK_SUMMARY };
    },
    buildSystemPrompt: vi.fn(),
    resolveModel: vi.fn(),
    resolveMaxTokens: vi.fn(),
    execute: vi.fn(),
  };
}

describe('POST /v1/agents/:slug/execute', () => {
  it('returns SSE stream with token and summary events', async () => {
    const catalog = new Catalog([AGENT]);
    const engine = createMockEngine();
    const app = createApp(catalog, engine as any);

    const res = await app.request('/v1/agents/sales-deal-strategist/execute', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ task: 'test task' }),
    });

    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toContain('text/event-stream');

    const text = await res.text();
    expect(text).toContain('event: token');
    expect(text).toContain('Hello ');
    expect(text).toContain('event: summary');
    expect(text).toContain('"score":0.8');
  });

  it('returns 404 for unknown agent', async () => {
    const catalog = new Catalog([]);
    const engine = createMockEngine();
    const app = createApp(catalog, engine as any);

    const res = await app.request('/v1/agents/nonexistent/execute', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ task: 'test' }),
    });

    expect(res.status).toBe(404);
  });

  it('returns 400 when task is missing', async () => {
    const catalog = new Catalog([AGENT]);
    const engine = createMockEngine();
    const app = createApp(catalog, engine as any);

    const res = await app.request('/v1/agents/sales-deal-strategist/execute', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    });

    expect(res.status).toBe(400);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && npx vitest run src/rest/execute.test.ts
```

Expected: FAIL — execute route not registered.

- [ ] **Step 3: Implement execute route**

Write `api/src/rest/execute.ts`:

```typescript
import { Hono } from 'hono';
import { streamSSE } from 'hono/streaming';
import type { Catalog } from '../catalog/catalog.js';
import type { ExecutionEngine } from '../execution/engine.js';
import { AgentApiError } from '../types.js';

export function executeRoutes(catalog: Catalog, engine: ExecutionEngine): Hono {
  const app = new Hono();

  app.post('/v1/agents/:slug/execute', async (c) => {
    const slug = c.req.param('slug');
    const agent = catalog.get(slug);

    if (!agent) {
      throw new AgentApiError(
        'AGENT_NOT_FOUND',
        404,
        `No agent with slug '${slug}'`,
      );
    }

    const body = await c.req.json<{
      task?: string;
      context?: Record<string, unknown>;
      model_override?: string;
    }>();

    if (!body.task) {
      throw new AgentApiError(
        'INVALID_REQUEST',
        400,
        'Missing required field: task',
      );
    }

    return streamSSE(c, async (stream) => {
      const events = engine.executeStream(
        agent,
        body.task!,
        body.context ?? {},
        body.model_override ?? undefined,
      );

      for await (const event of events) {
        await stream.writeSSE({
          event: event.type,
          data: typeof event.data === 'string'
            ? event.data
            : JSON.stringify(event.data),
        });
      }
    });
  });

  return app;
}
```

- [ ] **Step 4: Wire execute routes into app.ts**

Edit `api/src/rest/app.ts` to add the import and route:

```typescript
import { Hono } from 'hono';
import { cors } from 'hono/cors';
import type { Catalog } from '../catalog/catalog.js';
import type { ExecutionEngine } from '../execution/engine.js';
import { healthRoutes } from './health.js';
import { agentRoutes } from './agents.js';
import { executeRoutes } from './execute.js';
import { errorHandler } from './error-handler.js';

export function createApp(catalog: Catalog, engine: ExecutionEngine): Hono {
  const app = new Hono();

  app.use('*', cors());
  app.onError(errorHandler);

  app.route('/', healthRoutes(catalog));
  app.route('/', agentRoutes(catalog));
  app.route('/', executeRoutes(catalog, engine));

  return app;
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
cd api && npx vitest run src/rest/
```

Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add api/src/rest/
git commit -m "feat(api): SSE execute endpoint — streams tokens + summary for agent execution"
```

---

### Task 11: MCP Server

**Files:**
- Create: `api/src/mcp/server.ts`
- Create: `api/src/mcp/server.test.ts`

- [ ] **Step 1: Write the failing test**

Write `api/src/mcp/server.test.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { createMcpTools } from './server.js';
import { Catalog } from '../catalog/catalog.js';
import type { AgentEntry, ExecutionResult } from '../types.js';

const AGENT: AgentEntry = {
  slug: 'sales-deal-strategist',
  name: 'Deal Strategist',
  division: 'sales',
  specialty: 'MEDDPICC',
  whenToUse: 'Deal scoring',
  emoji: '♟️',
  promptPath: 'sales/sales-deal-strategist.md',
  promptContent: '# Deal Strategist\nYou are...',
};

const MOCK_RESULT: ExecutionResult = {
  version: 'v1',
  agent: 'sales-deal-strategist',
  task: 'test',
  result: { score: 0.8 },
  metadata: {
    model: 'claude-sonnet-4-6',
    tokens_in: 100,
    tokens_out: 200,
    duration_ms: 1000,
    execution_id: 'exec_test',
  },
};

describe('createMcpTools', () => {
  it('returns three tool definitions', () => {
    const catalog = new Catalog([AGENT]);
    const mockEngine = { execute: vi.fn() };
    const tools = createMcpTools(catalog, mockEngine as any);

    expect(tools).toHaveLength(3);
    expect(tools.map((t) => t.name)).toEqual([
      'list_agents',
      'execute_agent',
      'get_agent_prompt',
    ]);
  });

  it('list_agents handler returns catalog entries', async () => {
    const catalog = new Catalog([AGENT]);
    const mockEngine = { execute: vi.fn() };
    const tools = createMcpTools(catalog, mockEngine as any);

    const listTool = tools.find((t) => t.name === 'list_agents')!;
    const result = await listTool.handler({});

    expect(result.content[0].text).toContain('sales-deal-strategist');
  });

  it('execute_agent handler calls engine and returns summary', async () => {
    const catalog = new Catalog([AGENT]);
    const mockEngine = { execute: vi.fn().mockResolvedValue(MOCK_RESULT) };
    const tools = createMcpTools(catalog, mockEngine as any);

    const executeTool = tools.find((t) => t.name === 'execute_agent')!;
    const result = await executeTool.handler({
      agent: 'sales-deal-strategist',
      task: 'test task',
    });

    expect(mockEngine.execute).toHaveBeenCalledWith(AGENT, 'test task', {}, undefined);
    expect(result.content[0].text).toContain('"score":0.8');
  });

  it('execute_agent returns error for unknown agent', async () => {
    const catalog = new Catalog([]);
    const mockEngine = { execute: vi.fn() };
    const tools = createMcpTools(catalog, mockEngine as any);

    const executeTool = tools.find((t) => t.name === 'execute_agent')!;
    const result = await executeTool.handler({
      agent: 'nonexistent',
      task: 'test',
    });

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('AGENT_NOT_FOUND');
  });

  it('get_agent_prompt returns raw prompt', async () => {
    const catalog = new Catalog([AGENT]);
    const mockEngine = { execute: vi.fn() };
    const tools = createMcpTools(catalog, mockEngine as any);

    const promptTool = tools.find((t) => t.name === 'get_agent_prompt')!;
    const result = await promptTool.handler({ agent: 'sales-deal-strategist' });

    expect(result.content[0].text).toContain('Deal Strategist');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && npx vitest run src/mcp/server.test.ts
```

Expected: FAIL — `createMcpTools` not found.

- [ ] **Step 3: Implement MCP server**

Write `api/src/mcp/server.ts`:

```typescript
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import type { Catalog } from '../catalog/catalog.js';
import type { ExecutionEngine } from '../execution/engine.js';

interface ToolDefinition {
  name: string;
  handler: (args: any) => Promise<{
    content: Array<{ type: 'text'; text: string }>;
    isError?: boolean;
  }>;
}

export function createMcpTools(
  catalog: Catalog,
  engine: ExecutionEngine,
): ToolDefinition[] {
  return [
    {
      name: 'list_agents',
      handler: async (args: { division?: string; query?: string }) => {
        const result = catalog.list({
          division: args.division,
          q: args.query,
          limit: 50,
        });
        return {
          content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }],
        };
      },
    },
    {
      name: 'execute_agent',
      handler: async (args: {
        agent: string;
        task: string;
        context?: Record<string, unknown>;
      }) => {
        const agent = catalog.get(args.agent);
        if (!agent) {
          return {
            content: [
              {
                type: 'text' as const,
                text: JSON.stringify({
                  error: {
                    code: 'AGENT_NOT_FOUND',
                    message: `No agent with slug '${args.agent}'`,
                    details: {},
                  },
                }),
              },
            ],
            isError: true,
          };
        }

        try {
          const result = await engine.execute(
            agent,
            args.task,
            args.context ?? {},
            undefined,
          );
          return {
            content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }],
          };
        } catch (err) {
          const message = err instanceof Error ? err.message : 'Unknown error';
          return {
            content: [
              {
                type: 'text' as const,
                text: JSON.stringify({
                  error: { code: 'EXECUTION_ERROR', message, details: {} },
                }),
              },
            ],
            isError: true,
          };
        }
      },
    },
    {
      name: 'get_agent_prompt',
      handler: async (args: { agent: string }) => {
        const agent = catalog.get(args.agent);
        if (!agent) {
          return {
            content: [
              {
                type: 'text' as const,
                text: JSON.stringify({
                  error: {
                    code: 'AGENT_NOT_FOUND',
                    message: `No agent with slug '${args.agent}'`,
                    details: {},
                  },
                }),
              },
            ],
            isError: true,
          };
        }
        return {
          content: [{ type: 'text' as const, text: agent.promptContent }],
        };
      },
    },
  ];
}

export function createMcpServer(
  catalog: Catalog,
  engine: ExecutionEngine,
): McpServer {
  const server = new McpServer({
    name: 'agency-agents',
    version: '0.1.0',
  });

  server.tool(
    'list_agents',
    'List available specialist agents with optional filtering by division or keyword',
    {
      division: z.string().optional().describe('Filter by division (e.g., sales, product)'),
      query: z.string().optional().describe('Search agents by keyword'),
    },
    async (args) => {
      const tools = createMcpTools(catalog, engine);
      return tools[0].handler(args);
    },
  );

  server.tool(
    'execute_agent',
    'Execute a specialist agent with a task and return structured results',
    {
      agent: z.string().describe('Agent slug (e.g., sales-deal-strategist)'),
      task: z.string().describe('The task for the specialist to perform'),
      context: z.record(z.unknown()).optional().describe('Optional context for the specialist'),
    },
    async (args) => {
      const tools = createMcpTools(catalog, engine);
      return tools[1].handler(args);
    },
  );

  server.tool(
    'get_agent_prompt',
    'Get the raw system prompt for an agent',
    {
      agent: z.string().describe('Agent slug'),
    },
    async (args) => {
      const tools = createMcpTools(catalog, engine);
      return tools[2].handler(args);
    },
  );

  return server;
}

export async function startMcpServer(
  catalog: Catalog,
  engine: ExecutionEngine,
): Promise<void> {
  const server = createMcpServer(catalog, engine);
  const transport = new StdioServerTransport();
  await server.connect(transport);
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd api && npx vitest run src/mcp/server.test.ts
```

Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add api/src/mcp/
git commit -m "feat(api): MCP server — list_agents, execute_agent, get_agent_prompt tools"
```

---

### Task 12: Entry Point

**Files:**
- Create: `api/src/index.ts`

- [ ] **Step 1: Implement entry point**

Write `api/src/index.ts`:

```typescript
import { serve } from '@hono/node-server';
import { resolve } from 'path';
import { loadConfig } from './config.js';
import { scanPrompts } from './catalog/scanner.js';
import { Catalog } from './catalog/catalog.js';
import { ExecutionEngine } from './execution/engine.js';
import { createApp } from './rest/app.js';
import { startMcpServer } from './mcp/server.js';
import { setLogLevel, log } from './observability/logger.js';
import { ConsoleMetricsEmitter } from './observability/metrics.js';

const mode = process.argv[2]; // 'rest', 'mcp', or undefined (defaults to rest)

async function main(): Promise<void> {
  const config = loadConfig();
  setLogLevel(config.log_level);

  const promptsDir = resolve(import.meta.dirname, '..', config.prompts_dir);
  log('info', 'scanning_prompts', { dir: promptsDir });

  const entries = await scanPrompts(promptsDir);
  const catalog = new Catalog(entries);
  log('info', 'catalog_ready', { agents: catalog.count });

  const metrics = new ConsoleMetricsEmitter();
  const engine = new ExecutionEngine(config.execution, metrics);

  if (mode === 'mcp') {
    log('info', 'starting_mcp_server');
    await startMcpServer(catalog, engine);
  } else {
    const app = createApp(catalog, engine);
    log('info', 'starting_rest_server', { port: config.port });
    serve({ fetch: app.fetch, port: config.port });
    log('info', 'server_ready', { port: config.port, agents: catalog.count });
  }
}

main().catch((err) => {
  log('error', 'startup_failed', { message: String(err) });
  process.exit(1);
});
```

- [ ] **Step 2: Verify compilation**

```bash
cd api && npx tsc --noEmit
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add api/src/index.ts
git commit -m "feat(api): entry point — REST or MCP mode via CLI arg"
```

---

### Task 13: Dockerfile

**Files:**
- Create: `api/Dockerfile`
- Create: `api/.dockerignore`

- [ ] **Step 1: Create .dockerignore**

Write `api/.dockerignore`:

```
node_modules
dist
*.test.ts
__fixtures__
.env
```

- [ ] **Step 2: Create Dockerfile**

Write `api/Dockerfile`:

```dockerfile
FROM node:22-slim AS builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY tsconfig.json ./
COPY src/ src/
RUN npx tsc

FROM node:22-slim
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY --from=builder /app/dist/ dist/
COPY config.default.yaml ./

# Copy prompt files from repo root (build context must be repo root)
# Usage: docker build -f api/Dockerfile -t agency-agents-api .
COPY academic/ /prompts/academic/
COPY design/ /prompts/design/
COPY engineering/ /prompts/engineering/
COPY game-development/ /prompts/game-development/
COPY marketing/ /prompts/marketing/
COPY paid-media/ /prompts/paid-media/
COPY product/ /prompts/product/
COPY project-management/ /prompts/project-management/
COPY sales/ /prompts/sales/
COPY specialized/ /prompts/specialized/
COPY support/ /prompts/support/
COPY spatial-computing/ /prompts/spatial-computing/

ENV PROMPTS_DIR=/prompts
ENV PORT=3100
EXPOSE 3100

CMD ["node", "dist/index.js", "rest"]
```

- [ ] **Step 3: Commit**

```bash
git add api/Dockerfile api/.dockerignore
git commit -m "feat(api): Dockerfile — multi-stage build with prompt bundling"
```

---

### Task 14: Integration Smoke Test

**Files:**
- None (manual verification)

- [ ] **Step 1: Start the REST server against real prompts**

```bash
cd api && PROMPTS_DIR=.. npm run dev
```

Expected: Logs show `catalog_ready` with ~144 agents.

- [ ] **Step 2: Test health endpoint**

```bash
curl -s http://localhost:3100/v1/health | jq .
```

Expected:
```json
{
  "status": "ok",
  "agents_indexed": 144,
  "uptime_seconds": ...
}
```

- [ ] **Step 3: Test list agents**

```bash
curl -s 'http://localhost:3100/v1/agents?division=sales' | jq '.agents | length'
```

Expected: Returns the count of sales division agents.

- [ ] **Step 4: Test get agent**

```bash
curl -s http://localhost:3100/v1/agents/sales-deal-strategist | jq '.agent.name'
```

Expected: `"Deal Strategist"`

- [ ] **Step 5: Test 404**

```bash
curl -s http://localhost:3100/v1/agents/nonexistent | jq .
```

Expected: `{"error": {"code": "AGENT_NOT_FOUND", ...}}`

- [ ] **Step 6: Test execute (requires ANTHROPIC_API_KEY)**

```bash
curl -N -X POST http://localhost:3100/v1/agents/sales-deal-strategist/execute \
  -H 'Content-Type: application/json' \
  -d '{"task": "Qualify: Acme Corp, $50K ARR, no champion."}'
```

Expected: SSE stream with `token` events followed by `summary` event.

- [ ] **Step 7: Run full test suite**

```bash
cd api && npm test
```

Expected: All tests pass.

- [ ] **Step 8: Commit any fixes from smoke testing**

```bash
git add -A api/
git commit -m "fix(api): smoke test fixes"
```
