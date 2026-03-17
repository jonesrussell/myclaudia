"""Tool: Send or reply to an email."""

TOOL_DEF = {
    "name": "gmail_send",
    "description": "Send an email or reply to an existing message.",
    "input_schema": {
        "type": "object",
        "properties": {
            "to": {
                "type": "string",
                "description": "Recipient email address",
            },
            "subject": {
                "type": "string",
                "description": "Email subject line",
            },
            "body": {
                "type": "string",
                "description": "Email body text",
            },
            "reply_to_message_id": {
                "type": "string",
                "description": "If replying, the original message ID (optional)",
                "default": "",
            },
        },
        "required": ["to", "subject", "body"],
    },
}


def execute(api, args: dict) -> dict:
    payload = {"to": args["to"], "subject": args["subject"], "body": args["body"]}
    reply_id = args.get("reply_to_message_id", "")
    if reply_id:
        payload["reply_to_message_id"] = reply_id
    return api.post("/api/internal/gmail/send", json_data=payload)
