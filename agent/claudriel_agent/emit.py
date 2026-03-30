"""JSONL event emission to stdout."""

from __future__ import annotations

import json
import os
from typing import Any

# Keep in sync with docs/specs/agent-subprocess.md and all emit() call sites.
ALLOWED_EMIT_EVENTS = frozenset(
    {
        "message",
        "done",
        "error",
        "tool_call",
        "tool_result",
        "progress",
        "needs_continuation",
    }
)


def _emit_strict_enabled() -> bool:
    return os.environ.get("CLAUDRIEL_EMIT_STRICT", "").strip().lower() in ("1", "true", "yes")


def emit(event: str, **kwargs: object) -> None:
    """Write a JSON-line event to stdout.

    Uses ``allow_nan=False`` so payloads are strict JSON (no NaN/Infinity), which
    keeps the PHP consumer safe.

    Set ``CLAUDRIEL_EMIT_STRICT=1`` to reject unknown ``event`` strings (helps catch
    typos during development). When unset, unknown events still emit for backward
    compatibility.
    """
    if _emit_strict_enabled() and event not in ALLOWED_EMIT_EVENTS:
        raise ValueError(
            f"Unknown emit event {event!r}; allowed: {sorted(ALLOWED_EMIT_EVENTS)}",
        )
    payload: dict[str, Any] = {"event": event, **kwargs}
    try:
        line = json.dumps(payload, ensure_ascii=False, allow_nan=False)
    except (TypeError, ValueError) as e:
        raise ValueError(f"emit payload is not JSON-serializable: {e}") from e
    print(line, flush=True)
