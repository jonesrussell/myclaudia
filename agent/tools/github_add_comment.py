"""Tool: Add a comment to a GitHub issue or pull request."""

TOOL_DEF = {
    "name": "github_add_comment",
    "description": "Add a comment to a GitHub issue or pull request. Requires user confirmation before executing.",
    "input_schema": {
        "type": "object",
        "properties": {
            "owner": {"type": "string", "description": "Repository owner"},
            "repo": {"type": "string", "description": "Repository name"},
            "number": {"type": "integer", "description": "Issue or PR number"},
            "body": {"type": "string", "description": "Comment body (markdown)"},
        },
        "required": ["owner", "repo", "number", "body"],
    },
}


def execute(api, args: dict) -> dict:
    owner = args["owner"]
    repo = args["repo"]
    number = args["number"]
    return api.post(f"/api/internal/github/comment/{owner}/{repo}/{number}", json_data={"body": args["body"]})
