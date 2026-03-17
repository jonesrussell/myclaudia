"""Tool: Create a calendar event."""

from claude_agent_sdk import Tool


def create_tool(api):
    def calendar_create(title: str, start_time: str, end_time: str, description: str = "", attendees: str = "") -> dict:
        """Create a new calendar event.

        Args:
            title: Event title
            start_time: Start time in ISO 8601 format (e.g., "2026-03-18T09:00:00-04:00")
            end_time: End time in ISO 8601 format
            description: Optional event description
            attendees: Comma-separated email addresses of attendees (optional)
        """
        payload = {"title": title, "start_time": start_time, "end_time": end_time}
        if description:
            payload["description"] = description
        if attendees:
            payload["attendees"] = [a.strip() for a in attendees.split(",")]
        return api.post("/api/internal/calendar/create", json_data=payload)

    return Tool.from_function(calendar_create)
