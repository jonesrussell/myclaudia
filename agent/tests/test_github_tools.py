"""Tests for GitHub agent tools."""
from unittest.mock import MagicMock
import importlib

GITHUB_TOOLS = [
    "github_notifications",
    "github_list_issues",
    "github_read_issue",
    "github_list_pulls",
    "github_read_pull",
    "github_create_issue",
    "github_add_comment",
]


# -----------------------------------------------------------------------
# Tool definitions have required fields
# -----------------------------------------------------------------------

def test_all_github_tools_have_valid_tool_def():
    for tool_name in GITHUB_TOOLS:
        mod = importlib.import_module(f"tools.{tool_name}")
        assert hasattr(mod, "TOOL_DEF"), f"{tool_name} missing TOOL_DEF"
        td = mod.TOOL_DEF
        assert "name" in td
        assert "description" in td
        assert "input_schema" in td
        assert td["input_schema"]["type"] == "object"


def test_all_github_tools_have_execute():
    for tool_name in GITHUB_TOOLS:
        mod = importlib.import_module(f"tools.{tool_name}")
        assert hasattr(mod, "execute"), f"{tool_name} missing execute"
        assert callable(mod.execute)


def test_github_tool_names_are_unique():
    names = []
    for tool_name in GITHUB_TOOLS:
        mod = importlib.import_module(f"tools.{tool_name}")
        names.append(mod.TOOL_DEF["name"])
    assert len(names) == len(set(names))


def test_write_tools_mention_confirmation():
    for tool_name in ["github_create_issue", "github_add_comment"]:
        mod = importlib.import_module(f"tools.{tool_name}")
        desc = mod.TOOL_DEF["description"].lower()
        assert "confirm" in desc, f"{tool_name} should mention confirmation in description"


# -----------------------------------------------------------------------
# github_notifications
# -----------------------------------------------------------------------

def test_github_notifications_calls_correct_endpoint():
    from tools.github_notifications import execute
    api = MagicMock()
    api.get.return_value = []
    execute(api, {})
    api.get.assert_called_once_with("/api/internal/github/notifications")


# -----------------------------------------------------------------------
# github_list_issues
# -----------------------------------------------------------------------

def test_github_list_issues_passes_repo_and_state():
    from tools.github_list_issues import execute
    api = MagicMock()
    api.get.return_value = []
    execute(api, {"repo": "octocat/hello", "state": "closed"})
    api.get.assert_called_once()
    call_args = api.get.call_args
    assert call_args[1]["params"]["repo"] == "octocat/hello"
    assert call_args[1]["params"]["state"] == "closed"


def test_github_list_issues_defaults_to_open():
    from tools.github_list_issues import execute
    api = MagicMock()
    api.get.return_value = []
    execute(api, {"repo": "octocat/hello"})
    assert api.get.call_args[1]["params"]["state"] == "open"


def test_github_list_issues_passes_labels():
    from tools.github_list_issues import execute
    api = MagicMock()
    api.get.return_value = []
    execute(api, {"repo": "octocat/hello", "labels": "bug,urgent"})
    assert api.get.call_args[1]["params"]["labels"] == "bug,urgent"


# -----------------------------------------------------------------------
# github_read_issue
# -----------------------------------------------------------------------

def test_github_read_issue_calls_correct_endpoint():
    from tools.github_read_issue import execute
    api = MagicMock()
    api.get.return_value = {"number": 42}
    execute(api, {"owner": "octocat", "repo": "hello", "number": 42})
    api.get.assert_called_once_with("/api/internal/github/issue/octocat/hello/42")


# -----------------------------------------------------------------------
# github_list_pulls
# -----------------------------------------------------------------------

def test_github_list_pulls_passes_repo():
    from tools.github_list_pulls import execute
    api = MagicMock()
    api.get.return_value = []
    execute(api, {"repo": "octocat/hello"})
    assert api.get.call_args[1]["params"]["repo"] == "octocat/hello"


# -----------------------------------------------------------------------
# github_read_pull
# -----------------------------------------------------------------------

def test_github_read_pull_calls_correct_endpoint():
    from tools.github_read_pull import execute
    api = MagicMock()
    api.get.return_value = {"number": 7}
    execute(api, {"owner": "octocat", "repo": "hello", "number": 7})
    api.get.assert_called_once_with("/api/internal/github/pull/octocat/hello/7")


# -----------------------------------------------------------------------
# github_create_issue
# -----------------------------------------------------------------------

def test_github_create_issue_posts_to_correct_endpoint():
    from tools.github_create_issue import execute
    api = MagicMock()
    api.post.return_value = {"number": 99}
    execute(api, {"owner": "octocat", "repo": "hello", "title": "Bug report"})
    api.post.assert_called_once()
    call_args = api.post.call_args
    assert call_args[0][0] == "/api/internal/github/issue/octocat/hello"
    assert call_args[1]["json_data"]["title"] == "Bug report"


def test_github_create_issue_includes_body_and_labels():
    from tools.github_create_issue import execute
    api = MagicMock()
    api.post.return_value = {"number": 100}
    execute(api, {"owner": "o", "repo": "r", "title": "T", "body": "B", "labels": ["bug"]})
    payload = api.post.call_args[1]["json_data"]
    assert payload["body"] == "B"
    assert payload["labels"] == ["bug"]


# -----------------------------------------------------------------------
# github_add_comment
# -----------------------------------------------------------------------

def test_github_add_comment_posts_to_correct_endpoint():
    from tools.github_add_comment import execute
    api = MagicMock()
    api.post.return_value = {"id": 1}
    execute(api, {"owner": "octocat", "repo": "hello", "number": 42, "body": "LGTM"})
    api.post.assert_called_once()
    call_args = api.post.call_args
    assert call_args[0][0] == "/api/internal/github/comment/octocat/hello/42"
    assert call_args[1]["json_data"]["body"] == "LGTM"
