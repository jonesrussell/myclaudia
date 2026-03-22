#!/usr/bin/env python3
"""Claudriel agent entrypoint.

Reads a JSON request from stdin, runs an agentic tool-use loop via the
Anthropic API, and writes JSON-lines events to stdout.

Usage:
    echo '{"messages": [...], "system": "...", ...}' | python agent/main.py
"""

import json
import importlib
import os
from pathlib import Path
import sys
import time
from typing import Callable

import anthropic

from util.http import PhpApiClient

# Max characters for tool results stored in conversation history.
# Full results are still emitted via tool_result events to the frontend.
TOOL_RESULT_MAX_CHARS = 2000
GMAIL_BODY_MAX_CHARS = 500

DEFAULT_TURN_LIMITS = {
    "quick_lookup": 5,
    "email_compose": 15,
    "brief_generation": 10,
    "research": 40,
    "general": 25,
    "onboarding": 30,
}

RATE_LIMIT_MAX_RETRIES = 3
RATE_LIMIT_INITIAL_BACKOFF = 5  # seconds
RATE_LIMIT_MAX_BACKOFF = 60  # seconds


def parse_enabled_tool_names(raw_value: str | None) -> set[str] | None:
    """Parse a comma-separated allowlist from config."""
    if raw_value is None:
        return None

    names = {name.strip() for name in raw_value.split(",") if name.strip()}

    return names or None


def discover_tools(
    package_name: str = "tools",
    package_path: Path | None = None,
    enabled_tool_names: set[str] | None = None,
) -> tuple[list[dict], dict[str, Callable]]:
    """Discover tool modules that export TOOL_DEF and execute()."""
    tools_dir = package_path or (Path(__file__).resolve().parent / package_name)
    tool_defs: list[dict] = []
    executors: dict[str, Callable] = {}

    importlib.invalidate_caches()

    for module_path in sorted(tools_dir.glob("*.py")):
        if module_path.name == "__init__.py":
            continue

        module_name = module_path.stem
        module = importlib.import_module(f"{package_name}.{module_name}")

        tool_def = getattr(module, "TOOL_DEF", None)
        executor = getattr(module, "execute", None)
        if tool_def is None or executor is None or not callable(executor):
            continue

        tool_name = tool_def.get("name")
        if not isinstance(tool_name, str) or tool_name == "":
            continue
        if enabled_tool_names is not None and tool_name not in enabled_tool_names:
            continue
        if tool_name in executors:
            raise ValueError(f"Duplicate tool name: {tool_name}")

        tool_defs.append(tool_def)
        executors[tool_name] = executor

    if enabled_tool_names is not None:
        missing = sorted(enabled_tool_names - executors.keys())
        if missing:
            raise ValueError(f"Configured tools not found: {', '.join(missing)}")

    return tool_defs, executors


def load_configured_tools() -> tuple[list[dict], dict[str, Callable]]:
    enabled_tool_names = parse_enabled_tool_names(
        os.environ.get("CLAUDRIEL_AGENT_TOOLS"),
    )
    return discover_tools(enabled_tool_names=enabled_tool_names)


TOOLS, EXECUTORS = load_configured_tools()


def classify_task_type(messages: list) -> str:
    """Classify task type from first user message."""
    first_msg = ""
    for msg in messages:
        if msg.get("role") == "user":
            content = msg.get("content", "")
            if isinstance(content, str):
                first_msg = content.lower()
            break

    if any(w in first_msg for w in ["send", "email", "reply", "compose", "draft"]):
        return "email_compose"
    if any(w in first_msg for w in ["brief", "summary", "morning", "digest"]):
        return "brief_generation"
    if any(w in first_msg for w in ["check", "what time", "calendar", "schedule", "who is"]):
        return "quick_lookup"
    if any(w in first_msg for w in ["research", "find out", "look into", "analyze"]):
        return "research"
    return "general"


def emit(event: str, **kwargs) -> None:
    """Write a JSON-line event to stdout."""
    line = json.dumps({"event": event, **kwargs}, ensure_ascii=False)
    print(line, flush=True)


def truncate_tool_result(tool_name: str, result: dict) -> str:
    """Truncate a tool result for conversation history to control token growth."""
    result_json = json.dumps(result, ensure_ascii=False)

    if tool_name == "gmail_read":
        # Gmail bodies are the biggest offender; truncate the body field
        truncated = dict(result)
        if "body" in truncated and len(truncated["body"]) > GMAIL_BODY_MAX_CHARS:
            truncated["body"] = truncated["body"][:GMAIL_BODY_MAX_CHARS] + " [truncated]"
        return json.dumps(truncated, ensure_ascii=False)

    if len(result_json) > TOOL_RESULT_MAX_CHARS:
        return result_json[:TOOL_RESULT_MAX_CHARS] + " [truncated]"

    return result_json


def build_cached_tools(tools: list) -> list:
    """Add cache_control to the last tool definition for prompt caching."""
    if not tools:
        return tools
    cached = [dict(t) for t in tools]
    cached[-1] = dict(cached[-1])
    cached[-1]["cache_control"] = {"type": "ephemeral"}
    return cached


def main() -> None:
    try:
        request = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        emit("error", message=f"Invalid JSON input: {e}")
        sys.exit(1)

    messages = request.get("messages", [])
    system_prompt = request.get("system", "")
    account_id = request.get("account_id", "")
    tenant_id = request.get("tenant_id", "")
    api_base = request.get("api_base", "http://localhost:8000")
    api_token = request.get("api_token", "")
    model = request.get("model", "claude-sonnet-4-6")

    api = PhpApiClient(api_base, api_token, account_id, tenant_id)
    client = anthropic.Anthropic()

    # Fetch turn limits from session endpoint, fall back to defaults
    try:
        limits_response = api.get("/api/internal/session/limits")
        turn_limits = limits_response.get("turn_limits", DEFAULT_TURN_LIMITS)
    except Exception:
        turn_limits = DEFAULT_TURN_LIMITS

    task_type = classify_task_type(messages)
    turn_limit = turn_limits.get(task_type, turn_limits.get("general", 25))

    # Support continuation: fresh budget on continued requests
    if request.get("continued", False):
        turn_limit = turn_limits.get(task_type, turn_limits.get("general", 25))

    turns_consumed = 0
    cached_tools = build_cached_tools(TOOLS)
    cached_system = [{"type": "text", "text": system_prompt, "cache_control": {"type": "ephemeral"}}]

    try:
        for _ in range(turn_limit):
            turns_consumed += 1

            response = None
            for attempt in range(RATE_LIMIT_MAX_RETRIES + 1):
                try:
                    response = client.messages.create(
                        model=model,
                        max_tokens=4096,
                        system=cached_system,
                        messages=messages,
                        tools=cached_tools,
                    )
                    break
                except anthropic.RateLimitError as e:
                    if attempt >= RATE_LIMIT_MAX_RETRIES:
                        raise
                    retry_after = getattr(e.response, "headers", {}).get("retry-after")
                    if retry_after is not None:
                        wait = min(float(retry_after), RATE_LIMIT_MAX_BACKOFF)
                    else:
                        wait = min(RATE_LIMIT_INITIAL_BACKOFF * (2 ** attempt), RATE_LIMIT_MAX_BACKOFF)
                    emit("progress", phase="rate_limit", summary=f"Rate limited, retrying in {int(wait)}s...", level="warning")
                    time.sleep(wait)

            if response is None:
                emit("error", message="Failed to get API response after retries")
                break

            # Collect text and tool_use blocks from the response
            text_parts = []
            tool_calls = []

            for block in response.content:
                if block.type == "text":
                    text_parts.append(block.text)
                elif block.type == "tool_use":
                    tool_calls.append(block)

            # Emit any text content
            if text_parts:
                combined = "".join(text_parts)
                emit("message", content=combined)

            # If no tool calls, we're done
            if not tool_calls:
                break

            # Append assistant message to history
            messages.append({"role": "assistant", "content": response.content})

            # Execute each tool call and collect results
            tool_results = []
            for tool_call in tool_calls:
                emit("tool_call", tool=tool_call.name, args=tool_call.input)

                executor = EXECUTORS.get(tool_call.name)
                if executor is None:
                    result = {"error": f"Unknown tool: {tool_call.name}"}
                else:
                    try:
                        result = executor(api, tool_call.input)
                    except Exception as e:
                        result = {"error": str(e)}

                emit("tool_result", tool=tool_call.name, result=result)
                tool_results.append({
                    "type": "tool_result",
                    "tool_use_id": tool_call.id,
                    "content": truncate_tool_result(tool_call.name, result),
                })

            # Check if approaching limit and still have tool calls
            if turns_consumed >= turn_limit - 1 and tool_calls:
                emit("needs_continuation",
                     turns_consumed=turns_consumed,
                     task_type=task_type,
                     message="I need more turns to complete this task. Continue?")
                break

            # Append tool results and loop
            messages.append({"role": "user", "content": tool_results})

        emit("done")

    except Exception as e:
        print(f"Agent error: {e}", file=sys.stderr)
        emit("error", message=str(e))
        sys.exit(1)
    finally:
        api.close()


if __name__ == "__main__":
    main()
