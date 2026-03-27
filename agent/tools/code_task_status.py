"""Tool: Check the status of a code task."""

TOOL_DEF = {
    "name": "code_task_status",
    "description": (
        "Check the status of a previously created code task. "
        "Returns the current status (queued, running, completed, failed), "
        "and when completed: a summary of changes, diff preview, and PR URL."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "task_uuid": {
                "type": "string",
                "description": "UUID of the code task to check.",
            },
        },
        "required": ["task_uuid"],
    },
}


def execute(api, args: dict) -> dict:
    task_uuid = args["task_uuid"]
    return api.get(f"/api/internal/code-tasks/{task_uuid}/status")
