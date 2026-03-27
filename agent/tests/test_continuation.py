"""Tests for turn tracking and task type classification."""

import sys
import types
from unittest.mock import MagicMock

from main import DEFAULT_TURN_LIMITS, classify_task_type

# Stub out modules that aren't available in the test environment
# before importing main, so the import chain doesn't fail.
_stubs = {}
for mod_name in [
    "anthropic",
    "tools.gmail_list",
    "tools.gmail_read",
    "tools.gmail_send",
    "tools.calendar_list",
    "tools.calendar_create",
    "tools.commitment_list",
    "tools.commitment_update",
    "util.http",
]:
    if mod_name not in sys.modules:
        stub = types.ModuleType(mod_name)
        # Each tool module needs TOOL_DEF and execute
        stub.TOOL_DEF = {
            "name": mod_name.split(".")[-1],
            "input_schema": {"type": "object", "properties": {}},
        }
        stub.execute = lambda api, args: {}
        # util.http needs PhpApiClient
        stub.PhpApiClient = MagicMock
        sys.modules[mod_name] = stub
        _stubs[mod_name] = stub


def test_classify_email():
    messages = [{"role": "user", "content": "send an email to Bob"}]
    assert classify_task_type(messages) == "email_compose"


def test_classify_brief():
    messages = [{"role": "user", "content": "give me my morning brief"}]
    assert classify_task_type(messages) == "brief_generation"


def test_classify_quick():
    messages = [{"role": "user", "content": "check my calendar"}]
    assert classify_task_type(messages) == "quick_lookup"


def test_classify_research():
    messages = [{"role": "user", "content": "research competitor pricing"}]
    assert classify_task_type(messages) == "research"


def test_classify_general():
    messages = [{"role": "user", "content": "hello how are you"}]
    assert classify_task_type(messages) == "general"


def test_default_turn_limits_has_all_types():
    expected_keys = {
        "quick_lookup",
        "email_compose",
        "brief_generation",
        "research",
        "general",
        "onboarding",
    }
    assert set(DEFAULT_TURN_LIMITS.keys()) == expected_keys
