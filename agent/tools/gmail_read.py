"""Tool: Read a specific Gmail message."""

from claude_agent_sdk import Tool


def create_tool(api):
    def gmail_read(message_id: str) -> dict:
        """Read a specific Gmail message by ID.

        Args:
            message_id: The Gmail message ID to read
        """
        return api.get(f"/api/internal/gmail/read/{message_id}")

    return Tool.from_function(gmail_read)
