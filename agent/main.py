#!/usr/bin/env python3
"""Claudriel agent entrypoint.

Reads a JSON request from stdin, runs the claude-agent-sdk agentic loop
with registered tools, and writes JSON-lines events to stdout.

Usage:
    echo '{"messages": [...], "system": "...", ...}' | python agent/main.py
"""

import json
import sys

from claude_agent_sdk import Agent, AgentConfig

from tools import gmail_list, gmail_read, gmail_send, calendar_list, calendar_create
from util.http import PhpApiClient


def emit(event: str, **kwargs) -> None:
    """Write a JSON-line event to stdout."""
    line = json.dumps({"event": event, **kwargs}, ensure_ascii=False)
    print(line, flush=True)


def main() -> None:
    try:
        request = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        print(json.dumps({"event": "error", "message": f"Invalid JSON input: {e}"}), flush=True)
        sys.exit(1)

    messages = request.get("messages", [])
    system_prompt = request.get("system", "")
    account_id = request.get("account_id", "")
    api_base = request.get("api_base", "http://localhost:8000")
    api_token = request.get("api_token", "")
    model = request.get("model", "claude-sonnet-4-6")

    api = PhpApiClient(api_base, api_token, account_id)

    try:
        # Register tools with API client context
        tools = [
            gmail_list.create_tool(api),
            gmail_read.create_tool(api),
            gmail_send.create_tool(api),
            calendar_list.create_tool(api),
            calendar_create.create_tool(api),
        ]

        config = AgentConfig(
            model=model,
            system_prompt=system_prompt,
            max_turns=25,
            tools=tools,
        )

        agent = Agent(config)

        # Stream the agentic loop
        for event in agent.stream(messages):
            if event.type == "text":
                emit("message", content=event.text)
            elif event.type == "tool_use":
                emit("tool_call", tool=event.tool_name, args=event.tool_input)
            elif event.type == "tool_result":
                emit("tool_result", tool=event.tool_name, result=event.result)

        emit("done")

    except Exception as e:
        print(f"Agent error: {e}", file=sys.stderr)
        emit("error", message=str(e))
        sys.exit(1)
    finally:
        api.close()


if __name__ == "__main__":
    main()
