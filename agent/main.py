#!/usr/bin/env python3
"""Claudriel agent entrypoint.

Reads a JSON request from stdin, runs an agentic tool-use loop via the
Anthropic API, and writes JSON-lines events to stdout.

Usage:
    echo '{"messages": [...], "system": "...", ...}' | python agent/main.py
"""

import json
import sys

import anthropic

from tools.gmail_list import TOOL_DEF as GMAIL_LIST_DEF, execute as gmail_list_exec
from tools.gmail_read import TOOL_DEF as GMAIL_READ_DEF, execute as gmail_read_exec
from tools.gmail_send import TOOL_DEF as GMAIL_SEND_DEF, execute as gmail_send_exec
from tools.calendar_list import TOOL_DEF as CALENDAR_LIST_DEF, execute as calendar_list_exec
from tools.calendar_create import TOOL_DEF as CALENDAR_CREATE_DEF, execute as calendar_create_exec
from util.http import PhpApiClient

TOOLS = [GMAIL_LIST_DEF, GMAIL_READ_DEF, GMAIL_SEND_DEF, CALENDAR_LIST_DEF, CALENDAR_CREATE_DEF]

EXECUTORS = {
    "gmail_list": gmail_list_exec,
    "gmail_read": gmail_read_exec,
    "gmail_send": gmail_send_exec,
    "calendar_list": calendar_list_exec,
    "calendar_create": calendar_create_exec,
}

MAX_TURNS = 25


def emit(event: str, **kwargs) -> None:
    """Write a JSON-line event to stdout."""
    line = json.dumps({"event": event, **kwargs}, ensure_ascii=False)
    print(line, flush=True)


def main() -> None:
    try:
        request = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        emit("error", message=f"Invalid JSON input: {e}")
        sys.exit(1)

    messages = request.get("messages", [])
    system_prompt = request.get("system", "")
    account_id = request.get("account_id", "")
    api_base = request.get("api_base", "http://localhost:8000")
    api_token = request.get("api_token", "")
    model = request.get("model", "claude-sonnet-4-6")

    api = PhpApiClient(api_base, api_token, account_id)
    client = anthropic.Anthropic()

    try:
        for _ in range(MAX_TURNS):
            response = client.messages.create(
                model=model,
                max_tokens=4096,
                system=system_prompt,
                messages=messages,
                tools=TOOLS,
            )

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
                    "content": json.dumps(result, ensure_ascii=False),
                })

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
