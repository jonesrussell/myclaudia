"""Tool: Delete a workspace by UUID."""

TOOL_DEF = {
    "name": "workspace_delete",
    "description": (
        "Delete a workspace. ALWAYS call workspace_list first to resolve "
        "the correct workspace UUID. ALWAYS ask the user to confirm by "
        "echoing the workspace name before calling this tool."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "uuid": {
                "type": "string",
                "description": "UUID of the workspace to delete.",
            },
        },
        "required": ["uuid"],
    },
}


def execute(api, args: dict) -> dict:
    uuid = args["uuid"]
    return api.post(f"/api/internal/workspaces/{uuid}/delete", json_data={})
