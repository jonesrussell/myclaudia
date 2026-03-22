"""Tool: Create a new workspace."""

TOOL_DEF = {
    "name": "workspace_create",
    "description": (
        "Create a new workspace. Extract a short, descriptive name from the "
        "user's request (prefer repo name, project name, or client name). "
        "NEVER use the full user sentence as the name. The name should be "
        "1-3 words maximum."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "description": (
                    "Short workspace name (1-3 words). Examples: 'me' from "
                    "repo jonesrussell/me, 'Acme Corp' from a client project."
                ),
            },
            "description": {
                "type": "string",
                "description": "Optional description of the workspace purpose.",
            },
        },
        "required": ["name"],
    },
}


def execute(api, args: dict) -> dict:
    return api.post("/api/internal/workspaces/create", json_data={
        "name": args["name"],
        "description": args.get("description", ""),
        "mode": args.get("mode", "persistent"),
    })
