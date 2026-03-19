"""Tool: Get full details about a person."""

TOOL_DEF = {
    "name": "person_detail",
    "description": "Get full details about a person including their related commitments and events.",
    "input_schema": {
        "type": "object",
        "properties": {
            "uuid": {
                "type": "string",
                "description": "The person's UUID",
            },
        },
        "required": ["uuid"],
    },
}


def execute(api, args: dict) -> dict:
    return api.get(f"/api/internal/persons/{args['uuid']}")
