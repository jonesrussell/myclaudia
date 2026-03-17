"""Tool: List Gmail messages."""

TOOL_DEF = {
    "name": "gmail_list",
    "description": "List Gmail messages matching a query.",
    "input_schema": {
        "type": "object",
        "properties": {
            "query": {
                "type": "string",
                "description": "Gmail search query (default: is:unread)",
                "default": "is:unread",
            },
            "max_results": {
                "type": "integer",
                "description": "Maximum number of messages to return (default: 10)",
                "default": 10,
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    return api.get("/api/internal/gmail/list", params={
        "q": args.get("query", "is:unread"),
        "max_results": args.get("max_results", 10),
    })
