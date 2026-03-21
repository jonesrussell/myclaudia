"""Tool: Read a single GitHub issue with its comments."""

TOOL_DEF = {
    "name": "github_read_issue",
    "description": "Read a single GitHub issue with its comments.",
    "input_schema": {
        "type": "object",
        "properties": {
            "owner": {"type": "string", "description": "Repository owner"},
            "repo": {"type": "string", "description": "Repository name"},
            "number": {"type": "integer", "description": "Issue number"},
        },
        "required": ["owner", "repo", "number"],
    },
}


def execute(api, args: dict) -> dict:
    owner = args["owner"]
    repo = args["repo"]
    number = args["number"]
    return api.get(f"/api/internal/github/issue/{owner}/{repo}/{number}")
