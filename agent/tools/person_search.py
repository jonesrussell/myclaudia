"""Tool: Search for people in the user's contacts."""

TOOL_DEF = {
    "name": "person_search",
    "description": "Search for people in the user's contacts by name or email.",
    "input_schema": {
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "description": "Name to search for (partial match)",
            },
            "email": {
                "type": "string",
                "description": "Email to search for (partial match)",
            },
            "limit": {
                "type": "integer",
                "description": "Max results (default: 20)",
                "default": 20,
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    params = {"limit": args.get("limit", 20)}
    if "name" in args:
        params["name"] = args["name"]
    if "email" in args:
        params["email"] = args["email"]
    return api.get("/api/internal/persons/search", params=params)
