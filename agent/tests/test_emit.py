"""Tests for the emit() function and JSON-lines contract."""

import json

import pytest

from claudriel_agent.emit import ALLOWED_EMIT_EVENTS, emit


def _minimal_kwargs_for_event(event: str) -> dict:
    if event == "message":
        return {"content": ""}
    if event == "done":
        return {}
    if event == "error":
        return {"message": "x"}
    if event == "tool_call":
        return {"tool": "t", "args": {}}
    if event == "tool_result":
        return {"tool": "t", "result": {}}
    if event == "progress":
        return {"phase": "p", "summary": "s", "level": "warning"}
    if event == "needs_continuation":
        return {"turns_consumed": 1, "task_type": "general", "message": "m"}
    raise AssertionError(event)


def test_emit_writes_json_line_to_stdout(capsys):
    emit("message", content="Hello")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "message"
    assert line["content"] == "Hello"


def test_emit_done_event(capsys):
    emit("done")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "done"


def test_emit_error_event(capsys):
    emit("error", message="Something went wrong")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "error"
    assert line["message"] == "Something went wrong"


def test_emit_tool_call_event(capsys):
    emit("tool_call", tool="gmail_list", args={"query": "is:unread"})
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "tool_call"
    assert line["tool"] == "gmail_list"
    assert line["args"] == {"query": "is:unread"}


def test_emit_preserves_unicode(capsys):
    emit("message", content="Café résumé")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["content"] == "Café résumé"


def test_emit_strict_rejects_unknown_event(monkeypatch):
    monkeypatch.setenv("CLAUDRIEL_EMIT_STRICT", "1")
    with pytest.raises(ValueError, match="Unknown emit event"):
        emit("not_a_real_event", foo=1)


def test_emit_non_strict_allows_unknown_event(capsys, monkeypatch):
    monkeypatch.delenv("CLAUDRIEL_EMIT_STRICT", raising=False)
    emit("experimental_future_event", preview=True)
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "experimental_future_event"


def test_emit_rejects_nan():
    with pytest.raises(ValueError, match="JSON-serializable"):
        emit("message", content=float("nan"))


def test_each_allowlisted_event_emits_one_json_line(capsys):
    for event in sorted(ALLOWED_EMIT_EVENTS):
        kwargs = _minimal_kwargs_for_event(event)
        emit(event, **kwargs)
        captured = capsys.readouterr()
        lines = [ln for ln in captured.out.splitlines() if ln.strip()]
        assert len(lines) == 1, event
        obj = json.loads(lines[0])
        assert obj["event"] == event
