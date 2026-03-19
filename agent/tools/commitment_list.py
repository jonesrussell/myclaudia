"""Tool: List commitments."""

TOOL_DEF = {
    "name": "commitment_list",
    "description": "List the user's commitments, optionally filtered by status.",
    "input_schema": {
        "type": "object",
        "properties": {
            "status": {
                "type": "string",
                "description": "Filter: active, pending, completed, overdue",
                "enum": ["active", "pending", "completed", "overdue"],
            },
            "limit": {
                "type": "integer",
                "description": "Max results (default: 20)",
                "default": 20,
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    params = {"limit": args.get("limit", 20)}
    item_status = args.get("status")
    if item_status:
        params["status"] = item_status
    return api.get("/api/internal/commitments/list", params=params)
