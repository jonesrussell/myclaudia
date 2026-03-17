"""Tool: List upcoming calendar events."""

from claude_agent_sdk import Tool


def create_tool(api):
    def calendar_list(days_ahead: int = 7, max_results: int = 20) -> dict:
        """List upcoming calendar events.

        Args:
            days_ahead: Number of days to look ahead (default: 7)
            max_results: Maximum number of events to return (default: 20)
        """
        return api.get("/api/internal/calendar/list", params={"days_ahead": days_ahead, "max_results": max_results})

    return Tool.from_function(calendar_list)
