"""Tool: List Gmail messages."""

from claude_agent_sdk import Tool


def create_tool(api):
    """Create the gmail_list tool bound to the PHP API client."""

    def gmail_list(query: str = "is:unread", max_results: int = 10) -> dict:
        """List Gmail messages matching a query.

        Args:
            query: Gmail search query (default: "is:unread")
            max_results: Maximum number of messages to return (default: 10)
        """
        return api.get("/api/internal/gmail/list", params={"q": query, "max_results": max_results})

    return Tool.from_function(gmail_list)
