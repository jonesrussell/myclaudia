"""Tool: List issues for a GitHub repository."""

TOOL_DEF = {
    "name": "github_list_issues",
    "description": "List issues for a GitHub repository.",
    "input_schema": {
        "type": "object",
        "properties": {
            "repo": {"type": "string", "description": "Repository in owner/repo format"},
            "state": {"type": "string", "description": "Filter by state: open, closed, all", "default": "open"},
            "labels": {"type": "string", "description": "Comma-separated label names", "default": ""},
        },
        "required": ["repo"],
    },
}


def execute(api, args: dict) -> dict:
    params = {"repo": args["repo"], "state": args.get("state", "open")}
    labels = args.get("labels", "")
    if labels:
        params["labels"] = labels
    return api.get("/api/internal/github/issues", params=params)
