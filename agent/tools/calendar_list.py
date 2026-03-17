"""Tool: List upcoming calendar events."""

TOOL_DEF = {
    "name": "calendar_list",
    "description": "List upcoming calendar events.",
    "input_schema": {
        "type": "object",
        "properties": {
            "days_ahead": {
                "type": "integer",
                "description": "Number of days to look ahead (default: 7)",
                "default": 7,
            },
            "max_results": {
                "type": "integer",
                "description": "Maximum number of events to return (default: 20)",
                "default": 20,
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    return api.get("/api/internal/calendar/list", params={
        "days_ahead": args.get("days_ahead", 7),
        "max_results": args.get("max_results", 20),
    })
