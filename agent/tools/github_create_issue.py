"""Tool: Create a new GitHub issue."""

TOOL_DEF = {
    "name": "github_create_issue",
    "description": "Create a new GitHub issue. Requires user confirmation before executing.",
    "input_schema": {
        "type": "object",
        "properties": {
            "owner": {"type": "string", "description": "Repository owner"},
            "repo": {"type": "string", "description": "Repository name"},
            "title": {"type": "string", "description": "Issue title"},
            "body": {"type": "string", "description": "Issue body (markdown)", "default": ""},
            "labels": {"type": "array", "items": {"type": "string"}, "description": "Labels to apply", "default": []},
        },
        "required": ["owner", "repo", "title"],
    },
}


def execute(api, args: dict) -> dict:
    owner = args["owner"]
    repo = args["repo"]
    payload = {"title": args["title"]}
    if args.get("body"):
        payload["body"] = args["body"]
    if args.get("labels"):
        payload["labels"] = args["labels"]
    return api.post(f"/api/internal/github/issue/{owner}/{repo}", json_data=payload)
