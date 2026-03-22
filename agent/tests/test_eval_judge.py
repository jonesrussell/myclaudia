"""Tests for LLM judge prompt construction and score parsing."""
import json
from eval_judge import build_judge_prompt, parse_judge_response, JudgeScore


def test_build_judge_prompt_includes_skill_and_input():
    prompt = build_judge_prompt(
        skill_name="commitment",
        user_input="I owe Sarah a proposal",
        response_text="I'll create a commitment...",
        assertions=[{"type": "confirmation_shown"}, {"type": "direction_detected", "direction": "outbound"}],
    )
    assert "commitment" in prompt
    assert "I owe Sarah a proposal" in prompt
    assert "I'll create a commitment" in prompt
    assert "confirmation_shown" in prompt
    assert "direction_detected" in prompt


def test_build_judge_prompt_formats_assertions_as_rubric():
    prompt = build_judge_prompt(
        skill_name="test",
        user_input="test input",
        response_text="test response",
        assertions=[
            {"type": "field_extraction", "field": "title", "should_match": "Proposal"},
            {"type": "confirmation_shown"},
        ],
    )
    assert "field_extraction" in prompt
    assert "title" in prompt
    assert "Proposal" in prompt


def test_parse_judge_response_valid_json():
    raw = json.dumps({
        "scores": [
            {"assertion": "confirmation_shown", "score": 5, "reason": "Clear confirmation"},
            {"assertion": "direction_detected", "score": 4, "reason": "Correctly outbound"},
        ],
        "overall": 4.5,
    })
    result = parse_judge_response(raw)
    assert len(result.scores) == 2
    assert result.scores[0].score == 5
    assert result.overall == 4.5


def test_parse_judge_response_extracts_json_from_text():
    """Judge may include text around the JSON."""
    raw = 'Here is my evaluation:\n{"scores": [{"assertion": "a", "score": 3, "reason": "ok"}], "overall": 3.0}\nDone.'
    result = parse_judge_response(raw)
    assert result.overall == 3.0


def test_parse_judge_response_invalid():
    """Garbage input returns a zero-score result."""
    result = parse_judge_response("this is not json at all")
    assert result.overall == 0.0
    assert result.error is not None
