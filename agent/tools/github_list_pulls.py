"""Tool: List pull requests for a GitHub repository."""

TOOL_DEF = {
    "name": "github_list_pulls",
    "description": "List pull requests for a GitHub repository.",
    "input_schema": {
        "type": "object",
        "properties": {
            "repo": {"type": "string", "description": "Repository in owner/repo format"},
            "state": {"type": "string", "description": "Filter by state: open, closed, all", "default": "open"},
        },
        "required": ["repo"],
    },
}


def execute(api, args: dict) -> dict:
    params = {"repo": args["repo"], "state": args.get("state", "open")}
    return api.get("/api/internal/github/pulls", params=params)
