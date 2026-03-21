"""Tool: List unread GitHub notifications."""

TOOL_DEF = {
    "name": "github_notifications",
    "description": "List unread GitHub notifications (mentions, review requests, CI status, etc.).",
    "input_schema": {
        "type": "object",
        "properties": {},
        "required": [],
    },
}


def execute(api, args: dict) -> dict:
    return api.get("/api/internal/github/notifications")
