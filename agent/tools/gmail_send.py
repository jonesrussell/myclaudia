"""Tool: Send or reply to an email."""

from claude_agent_sdk import Tool


def create_tool(api):
    def gmail_send(to: str, subject: str, body: str, reply_to_message_id: str = "") -> dict:
        """Send an email or reply to an existing message.

        Args:
            to: Recipient email address
            subject: Email subject line
            body: Email body text
            reply_to_message_id: If replying, the original message ID (optional)
        """
        payload = {"to": to, "subject": subject, "body": body}
        if reply_to_message_id:
            payload["reply_to_message_id"] = reply_to_message_id
        return api.post("/api/internal/gmail/send", json_data=payload)

    return Tool.from_function(gmail_send)
