"""Tool: Read a single GitHub pull request with reviews."""

TOOL_DEF = {
    "name": "github_read_pull",
    "description": "Read a single GitHub pull request with its reviews.",
    "input_schema": {
        "type": "object",
        "properties": {
            "owner": {"type": "string", "description": "Repository owner"},
            "repo": {"type": "string", "description": "Repository name"},
            "number": {"type": "integer", "description": "PR number"},
        },
        "required": ["owner", "repo", "number"],
    },
}


def execute(api, args: dict) -> dict:
    owner = args["owner"]
    repo = args["repo"]
    number = args["number"]
    return api.get(f"/api/internal/github/pull/{owner}/{repo}/{number}")
