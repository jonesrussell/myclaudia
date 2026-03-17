"""Tool: Create a calendar event."""

TOOL_DEF = {
    "name": "calendar_create",
    "description": "Create a new calendar event.",
    "input_schema": {
        "type": "object",
        "properties": {
            "title": {
                "type": "string",
                "description": "Event title",
            },
            "start_time": {
                "type": "string",
                "description": "Start time in ISO 8601 format (e.g., 2026-03-18T09:00:00-04:00)",
            },
            "end_time": {
                "type": "string",
                "description": "End time in ISO 8601 format",
            },
            "description": {
                "type": "string",
                "description": "Optional event description",
                "default": "",
            },
            "attendees": {
                "type": "string",
                "description": "Comma-separated email addresses of attendees (optional)",
                "default": "",
            },
        },
        "required": ["title", "start_time", "end_time"],
    },
}


def execute(api, args: dict) -> dict:
    payload = {
        "title": args["title"],
        "start_time": args["start_time"],
        "end_time": args["end_time"],
    }
    if args.get("description"):
        payload["description"] = args["description"]
    if args.get("attendees"):
        payload["attendees"] = [a.strip() for a in args["attendees"].split(",")]
    return api.post("/api/internal/calendar/create", json_data=payload)
