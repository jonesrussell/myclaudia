#!/usr/bin/env bash
# Drift detector: maps recent file changes to affected specs.
# Run after sessions that modify source files to find specs that may need updating.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

N_COMMITS="${1:-5}"

echo "Files changed in last ${N_COMMITS} commits:"

declare -A FILE_TO_SPEC=(
  ["src/Entity"]="docs/specs/entity.md"
  ["src/Ingestion"]="docs/specs/ingestion.md"
  ["src/Pipeline"]="docs/specs/pipeline.md"
  ["src/DayBrief"]="docs/specs/day-brief.md"
  ["src/Domain/DayBrief"]="docs/specs/day-brief.md"
  ["src/Domain/Memory"]="docs/specs/memory.md"
  ["src/Domain/Pipeline"]="docs/specs/pipeline.md"
  ["src/Domain/Agent"]="docs/specs/agent-subprocess.md"
  ["src/Domain/Git"]="docs/specs/web-cli.md"
  ["src/Domain/Infrastructure"]="docs/specs/infrastructure.md"
  ["src/Domain/Project"]="docs/specs/entity.md"
  ["src/Domain/Schedule"]="docs/specs/entity.md"
  ["src/Domain/Workspace"]="docs/specs/entity.md"
  ["src/Support"]="docs/specs/infrastructure.md"
  ["src/Provider"]="docs/specs/infrastructure.md"
  ["src/AI"]="docs/specs/pipeline.md"
  ["src/Admin"]="docs/specs/admin-spa.md"
  ["src/Service"]="docs/specs/infrastructure.md"
  ["agent/claudriel_agent"]="docs/specs/agent-subprocess.md"
  ["agent/tests"]="docs/specs/agent-subprocess.md"
  ["agent/pyproject.toml"]="docs/specs/agent-subprocess.md"
  ["agent/conftest.py"]="docs/specs/agent-subprocess.md"
  ["agent/main.py"]="docs/specs/agent-subprocess.md"
  ["agent/Dockerfile"]="docs/specs/agent-subprocess.md"
  ["agent/requirements"]="docs/specs/agent-subprocess.md"
  ["agent/.dockerignore"]="docs/specs/agent-subprocess.md"
  ["agent/.gitignore"]="docs/specs/agent-subprocess.md"
  ["agent/eval_"]="docs/specs/agent-subprocess.md"
  ["src/Domain/Chat"]="docs/specs/chat.md"
  ["src/Domain/CodeTask"]="docs/specs/web-cli.md"
  ["src/Controller"]="docs/specs/web-cli.md"
  ["src/Command"]="docs/specs/web-cli.md"
  ["frontend/admin"]="docs/specs/admin-spa.md"
  ["templates"]="docs/specs/web-cli.md"
  [".github/workflows"]="docs/specs/workflow.md"
  ["config"]="docs/specs/infrastructure.md"
  ["tests/Unit"]="docs/specs/infrastructure.md"
  ["tests/Integration"]="docs/specs/infrastructure.md"
  ["bin"]="docs/specs/workflow.md"
  ["composer.json"]="docs/specs/infrastructure.md"
  ["composer.lock"]="docs/specs/infrastructure.md"
  [".env"]="docs/specs/infrastructure.md"
  [".gitignore"]="docs/specs/infrastructure.md"
  ["CLAUDE.md"]="CLAUDE.md"
  ["AGENTS.md"]="CLAUDE.md"
  ["AGENT_LEARNINGS.md"]="CLAUDE.md"
  ["docs/smoke"]="docs/specs/workflow.md"
  ["docs/specs"]="CLAUDE.md"
)

declare -A FLAGGED_SPECS=()

while IFS= read -r file; do
  matched=false
  for pattern in "${!FILE_TO_SPEC[@]}"; do
    if [[ "$file" == *"$pattern"* ]]; then
      spec="${FILE_TO_SPEC[$pattern]}"
      echo "  $file -> $spec"
      FLAGGED_SPECS["$spec"]=1
      matched=true
      break
    fi
  done
  if ! $matched; then
    echo "  $file -> (no spec mapping)"
  fi
done < <(git diff --name-only "HEAD~${N_COMMITS}" HEAD 2>/dev/null || git diff --name-only HEAD 2>/dev/null || echo "")

echo ""
if [ ${#FLAGGED_SPECS[@]} -gt 0 ]; then
  echo "Warning: ${#FLAGGED_SPECS[@]} spec(s) may need review:"
  for spec in "${!FLAGGED_SPECS[@]}"; do
    echo "  - $spec"
  done
else
  echo "No specs flagged for review."
fi
