"""Tool: Create a code task to make changes to a GitHub repository."""

import re

TOOL_DEF = {
    "name": "code_task_create",
    "description": (
        "Create a code task that spawns Claude Code to make changes to a GitHub repository. "
        "Claudriel will clone the repo (if not already in a workspace), create a branch, "
        "run Claude Code with your prompt, and create a pull request. "
        "Use code_task_status to check progress."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "repo": {
                "type": "string",
                "description": "Repository in owner/name format (e.g., 'jonesrussell/my-repo').",
                "pattern": "^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$",
            },
            "prompt": {
                "type": "string",
                "description": "Instructions for what changes to make (e.g., 'Fix the login bug in the auth handler').",
            },
            "branch_name": {
                "type": "string",
                "description": "Optional branch name override. Auto-generated from prompt if not provided.",
            },
        },
        "required": ["repo", "prompt"],
    },
}


def execute(api, args: dict) -> dict:
    repo = args["repo"]
    if not re.match(r"^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$", repo):
        return {"error": f"Invalid repo format: {repo!r}. Expected owner/name."}

    prompt = args.get("prompt", "")
    if not prompt.strip():
        return {"error": "prompt is required."}

    payload = {"repo": repo, "prompt": prompt}

    branch_name = args.get("branch_name")
    if branch_name:
        payload["branch_name"] = branch_name

    return api.post("/api/internal/code-tasks/create", json_data=payload)
