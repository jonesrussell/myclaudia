"""Tool: Update a commitment."""

TOOL_DEF = {
    "name": "commitment_update",
    "description": "Update a commitment's status or add notes.",
    "input_schema": {
        "type": "object",
        "properties": {
            "uuid": {
                "type": "string",
                "description": "The commitment UUID",
            },
            "status": {
                "type": "string",
                "description": "New status",
                "enum": ["active", "pending", "completed", "overdue"],
            },
            "notes": {
                "type": "string",
                "description": "Notes to add",
            },
        },
        "required": ["uuid"],
    },
}


def execute(api, args: dict) -> dict:
    uuid = args["uuid"]
    data = {}
    if "status" in args:
        data["status"] = args["status"]
    if "notes" in args:
        data["notes"] = args["notes"]
    return api.post(f"/api/internal/commitments/{uuid}/update", json_data=data)
