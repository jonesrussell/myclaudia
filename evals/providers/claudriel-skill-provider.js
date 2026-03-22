const Anthropic = require("@anthropic-ai/sdk");
const fs = require("fs");
const path = require("path");
const yaml = require("yaml");

const MODEL_ALIASES = {
  sonnet: "claude-sonnet-4-6",
  haiku: "claude-haiku-4-5-20251001",
  opus: "claude-opus-4-6",
};

const MAX_API_CALLS_PER_TURN = 3;

function resolveModel(alias) {
  return MODEL_ALIASES[alias] || alias;
}

function loadSkillContent(skillName, projectRoot) {
  const skillPath = path.join(
    projectRoot,
    ".claude",
    "skills",
    skillName,
    "SKILL.md"
  );
  if (!fs.existsSync(skillPath)) {
    throw new Error(`Skill file not found: ${skillPath}`);
  }
  return fs.readFileSync(skillPath, "utf-8");
}

function loadToolSchemas(skillName, evalsRoot) {
  const schemaPath = path.join(evalsRoot, "schemas", `${skillName}.json`);
  if (!fs.existsSync(schemaPath)) {
    throw new Error(`Tool schema not found: ${schemaPath}`);
  }
  return JSON.parse(fs.readFileSync(schemaPath, "utf-8"));
}

function loadEntityCrudTemplate(projectRoot) {
  const templatePath = path.join(
    projectRoot,
    ".claude",
    "skills",
    "_templates",
    "entity-crud.md"
  );
  if (fs.existsSync(templatePath)) {
    return fs.readFileSync(templatePath, "utf-8");
  }
  return "";
}

function loadDefaultMocks(skillName, evalsRoot) {
  const mockPath = path.join(evalsRoot, "mocks", `${skillName}.json`);
  if (fs.existsSync(mockPath)) {
    return JSON.parse(fs.readFileSync(mockPath, "utf-8"));
  }
  return {};
}

function loadRubric(skillName, evalsRoot) {
  const basePath = path.join(evalsRoot, "rubrics", "_base.yaml");
  const skillPath = path.join(evalsRoot, "rubrics", `${skillName}.yaml`);

  let criteria = [];

  if (fs.existsSync(basePath)) {
    const base = yaml.parse(fs.readFileSync(basePath, "utf-8"));
    criteria = base.criteria || [];
  }

  if (fs.existsSync(skillPath)) {
    const skill = yaml.parse(fs.readFileSync(skillPath, "utf-8"));
    const skillCriteria = skill.criteria || [];
    for (const sc of skillCriteria) {
      const idx = criteria.findIndex((c) => c.name === sc.name);
      if (idx >= 0) {
        criteria[idx] = sc; // override
      } else {
        criteria.push(sc); // append
      }
    }
  }

  return criteria;
}

function getMockResponse(turn, toolName, defaultMocks) {
  if (turn.mock_response && turn.mock_response[toolName]) {
    return turn.mock_response[toolName];
  }
  if (defaultMocks[toolName]) {
    return defaultMocks[toolName];
  }
  return { success: true };
}

async function callApi(prompt, options, context) {
  const evalsRoot = path.resolve(__dirname, "..");
  const projectRoot = path.resolve(evalsRoot, "..");

  // Parse test config from context
  const testConfig = context.vars || {};
  const skillName = testConfig._skill || context.prompt?.skill || "";
  const subjectModel = resolveModel(
    testConfig._subject_model ||
      options.config?.defaults?.subject_model ||
      "sonnet"
  );
  const maxTurns =
    testConfig._max_turns || options.config?.defaults?.max_turns || 10;

  if (!skillName) {
    return { error: "No skill name provided in test vars._skill" };
  }

  // Load skill content and tools
  const skillContent = loadSkillContent(skillName, projectRoot);
  const entityCrudTemplate = loadEntityCrudTemplate(projectRoot);
  const tools = loadToolSchemas(skillName, evalsRoot);
  const defaultMocks = loadDefaultMocks(skillName, evalsRoot);

  const systemPrompt = [entityCrudTemplate, skillContent]
    .filter(Boolean)
    .join("\n\n---\n\n");

  const client = new Anthropic();
  const messages = [];
  const allToolCalls = [];

  // promptfoo passes eval YAML fields via context.vars.
  // The provider reads skill-specific fields prefixed with _ to avoid conflicts.
  // If turns aren't in vars, fall back to single-turn mode with the raw prompt.
  const turns = testConfig._turns || testConfig.turns || [{ input: prompt }];

  for (
    let turnIdx = 0;
    turnIdx < Math.min(turns.length, maxTurns);
    turnIdx++
  ) {
    const turn = turns[turnIdx];
    messages.push({ role: "user", content: turn.input });

    let apiCallsThisTurn = 0;
    let continueLoop = true;

    while (continueLoop && apiCallsThisTurn < MAX_API_CALLS_PER_TURN) {
      apiCallsThisTurn++;

      const response = await client.messages.create({
        model: subjectModel,
        max_tokens: 2048,
        system: systemPrompt,
        tools: tools,
        messages: messages,
      });

      // Collect assistant message
      const assistantContent = response.content;
      messages.push({ role: "assistant", content: assistantContent });

      // Check for tool calls
      const toolUses = assistantContent.filter((c) => c.type === "tool_use");

      if (toolUses.length > 0) {
        const toolResults = [];
        for (const toolUse of toolUses) {
          allToolCalls.push({
            name: toolUse.name,
            arguments: toolUse.input,
          });

          const mockResult = getMockResponse(turn, toolUse.name, defaultMocks);
          toolResults.push({
            type: "tool_result",
            tool_use_id: toolUse.id,
            content: JSON.stringify(mockResult),
          });
        }
        // Bundle all tool results into a single user message
        messages.push({ role: "user", content: toolResults });
      } else {
        // No tool calls, this turn is done
        continueLoop = false;
      }
    }
  }

  // Extract final text output
  const textBlocks = messages
    .filter((m) => m.role === "assistant")
    .flatMap((m) => (Array.isArray(m.content) ? m.content : []))
    .filter((c) => c.type === "text")
    .map((c) => c.text);

  const output = textBlocks.join("\n\n");

  return {
    output: output,
    tool_calls: allToolCalls,
    metadata: {
      model: subjectModel,
      skill: skillName,
      turns: turns.length,
    },
  };
}

module.exports = { callApi };
