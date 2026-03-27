"""Tests for code_task_create and code_task_status tools."""

from unittest.mock import MagicMock

from tools.code_task_create import TOOL_DEF as CREATE_DEF, execute as create_execute
from tools.code_task_status import TOOL_DEF as STATUS_DEF, execute as status_execute


class TestCodeTaskCreateDef:
    def test_has_required_fields(self):
        assert CREATE_DEF["name"] == "code_task_create"
        schema = CREATE_DEF["input_schema"]
        assert "repo" in schema["properties"]
        assert "prompt" in schema["properties"]
        assert "repo" in schema["required"]
        assert "prompt" in schema["required"]


class TestCodeTaskStatusDef:
    def test_has_required_fields(self):
        assert STATUS_DEF["name"] == "code_task_status"
        schema = STATUS_DEF["input_schema"]
        assert "task_uuid" in schema["properties"]
        assert "task_uuid" in schema["required"]


class TestCodeTaskCreateExecute:
    def test_rejects_invalid_repo_format(self):
        api = MagicMock()
        result = create_execute(api, {"repo": "invalid", "prompt": "fix bug"})
        assert "error" in result
        api.post.assert_not_called()

    def test_calls_api(self):
        api = MagicMock()
        api.post.return_value = {"task_uuid": "abc-123", "status": "queued"}

        result = create_execute(api, {"repo": "owner/name", "prompt": "fix the bug"})
        api.post.assert_called_once_with(
            "/api/internal/code-tasks/create",
            json_data={"repo": "owner/name", "prompt": "fix the bug"},
        )
        assert result["task_uuid"] == "abc-123"


class TestCodeTaskStatusExecute:
    def test_calls_api(self):
        api = MagicMock()
        api.get.return_value = {"status": "completed", "pr_url": "https://github.com/test/pull/1"}

        result = status_execute(api, {"task_uuid": "abc-123"})
        api.get.assert_called_once_with("/api/internal/code-tasks/abc-123/status")
        assert result["status"] == "completed"
