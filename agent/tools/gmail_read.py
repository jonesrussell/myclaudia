"""Tool: Read a specific Gmail message."""

TOOL_DEF = {
    "name": "gmail_read",
    "description": "Read a specific Gmail message by ID.",
    "input_schema": {
        "type": "object",
        "properties": {
            "message_id": {
                "type": "string",
                "description": "The Gmail message ID to read",
            },
        },
        "required": ["message_id"],
    },
}


def execute(api, args: dict) -> dict:
    return api.get(f"/api/internal/gmail/read/{args['message_id']}")
