import logging
import os
from collections.abc import AsyncIterator
from dataclasses import dataclass

from pathlib import Path

from claude_agent_sdk import AssistantMessage, ClaudeAgentOptions, ResultMessage, TextBlock, query

logger = logging.getLogger(__name__)

# Prefer the system-installed Claude CLI over the bundled one.
# The bundled CLI may not share the host's OAuth credentials.
CLAUDE_CLI_PATH: str | None = None
for _candidate in ["/usr/local/bin/claude", "/usr/bin/claude"]:
    if Path(_candidate).is_file():
        CLAUDE_CLI_PATH = _candidate
        break


@dataclass
class TokenEvent:
    text: str


@dataclass
class DoneEvent:
    full_text: str


@dataclass
class ErrorEvent:
    error: str


StreamEvent = TokenEvent | DoneEvent | ErrorEvent

# Restrict Claude to Gmail, Calendar, and Bash (for curl ingestion) tools.
# No file system or code editing access.
ALLOWED_TOOLS = [
    "mcp__claude_ai_Gmail__*",
    "mcp__claude_ai_Google_Calendar__*",
    "Bash",
]


async def stream_chat(
    system_prompt: str,
    messages: list[dict[str, str]],
) -> AsyncIterator[StreamEvent]:
    """Send messages to Claude Code SDK and yield streaming events."""
    model = os.environ.get("CLAUDE_MODEL", "claude-sonnet-4-6")

    prompt = _format_messages(messages)

    options = ClaudeAgentOptions(
        system_prompt=system_prompt,
        model=model,
        max_turns=25,
        allowed_tools=ALLOWED_TOOLS,
        cli_path=CLAUDE_CLI_PATH,
    )

    full_text = ""

    try:
        logger.info("Starting query with model=%s, prompt_len=%d", model, len(prompt))
        async for message in query(prompt=prompt, options=options):
            msg_type = type(message).__name__
            logger.info("Received message type: %s", msg_type)
            if isinstance(message, AssistantMessage):
                for block in message.content:
                    if isinstance(block, TextBlock):
                        logger.info("TextBlock: %s...", block.text[:80])
                        full_text += block.text
                        yield TokenEvent(text=block.text)
                    else:
                        logger.info("Skipping block type: %s", type(block).__name__)
            elif isinstance(message, ResultMessage):
                logger.info("ResultMessage received (query complete)")

        yield DoneEvent(full_text=full_text)
        logger.info("Stream complete, full_text_len=%d", len(full_text))

    except Exception as e:
        logger.error("Stream error: %s", e, exc_info=True)
        yield ErrorEvent(error=str(e))


def _format_messages(messages: list[dict[str, str]]) -> str:
    """Format conversation history as a prompt string for the SDK."""
    if not messages:
        return ""

    if len(messages) == 1:
        return messages[0]["content"]

    parts = []
    for msg in messages[:-1]:
        role = "User" if msg["role"] == "user" else "Assistant"
        parts.append(f"{role}: {msg['content']}")

    parts.append(f"\nUser: {messages[-1]['content']}")
    return "\n".join(parts)
