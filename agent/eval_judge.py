"""LLM judge for scoring skill eval outputs."""
import json
import re
from dataclasses import dataclass, field
from typing import Any

JUDGE_MODEL = "claude-haiku-4-5-20251001"


@dataclass
class AssertionScore:
    assertion: str
    score: float
    reason: str


@dataclass
class JudgeScore:
    scores: list[AssertionScore] = field(default_factory=list)
    overall: float = 0.0
    error: str | None = None


def build_judge_prompt(
    skill_name: str,
    user_input: str,
    response_text: str,
    assertions: list[dict[str, Any]],
) -> str:
    """Build the judge evaluation prompt."""
    rubric_lines = []
    for i, assertion in enumerate(assertions, 1):
        parts = [f"{i}. Type: {assertion['type']}"]
        for key, value in assertion.items():
            if key != "type":
                parts.append(f"   {key}: {value}")
        rubric_lines.append("\n".join(parts))

    rubric = "\n".join(rubric_lines)

    return f"""You are evaluating a Claudriel skill response for correctness and quality.

Skill: {skill_name}
User input: {user_input}

Skill response:
{response_text}

Evaluate against these criteria:
{rubric}

For each criterion, score 0-5:
0 = completely wrong or missing
1 = attempted but mostly wrong
2 = partially correct with significant issues
3 = mostly correct with minor issues
4 = correct with trivial issues
5 = perfect

Return ONLY valid JSON (no other text):
{{"scores": [{{"assertion": "<type>", "score": <N>, "reason": "<brief>"}}], "overall": <N.N>}}"""


def parse_judge_response(raw: str) -> JudgeScore:
    """Parse judge response, extracting JSON from potential surrounding text."""
    # Try direct parse first
    try:
        data = json.loads(raw.strip())
        return _build_score(data)
    except json.JSONDecodeError:
        pass

    # Try to find JSON in text
    match = re.search(r'\{.*"scores".*"overall".*\}', raw, re.DOTALL)
    if match:
        try:
            data = json.loads(match.group())
            return _build_score(data)
        except json.JSONDecodeError:
            pass

    return JudgeScore(error=f"Could not parse judge response: {raw[:200]}")


def _build_score(data: dict) -> JudgeScore:
    scores = []
    for s in data.get("scores", []):
        scores.append(AssertionScore(
            assertion=s.get("assertion", ""),
            score=float(s.get("score", 0)),
            reason=s.get("reason", ""),
        ))
    return JudgeScore(scores=scores, overall=float(data.get("overall", 0.0)))


def judge_response(
    skill_name: str,
    user_input: str,
    response_text: str,
    assertions: list[dict[str, Any]],
    model: str = JUDGE_MODEL,
) -> JudgeScore:
    """Send a skill response to the LLM judge for scoring."""
    import anthropic
    client = anthropic.Anthropic()
    prompt = build_judge_prompt(skill_name, user_input, response_text, assertions)

    response = client.messages.create(
        model=model,
        max_tokens=1024,
        messages=[{"role": "user", "content": prompt}],
    )

    raw = response.content[0].text
    return parse_judge_response(raw)
