"""Eval report generation in JSON and markdown formats."""
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any


@dataclass
class EvalTestResult:
    name: str
    score: float
    reason: str


@dataclass
class SkillResult:
    tests_run: int
    tests_passed: int
    average_score: float
    failures: list[EvalTestResult] = field(default_factory=list)


def generate_report(results: dict[str, SkillResult], mode: str = "llm-judge") -> dict[str, Any]:
    """Generate a structured report from skill results."""
    total_run = sum(r.tests_run for r in results.values())
    total_passed = sum(r.tests_passed for r in results.values())

    skills_data = {}
    for name, result in sorted(results.items()):
        skills_data[name] = {
            "tests_run": result.tests_run,
            "tests_passed": result.tests_passed,
            "average_score": round(result.average_score, 2),
            "failures": [
                {"test": f.name, "score": f.score, "reason": f.reason}
                for f in result.failures
            ],
        }

    return {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "mode": mode,
        "skills": skills_data,
        "totals": {
            "tests_run": total_run,
            "tests_passed": total_passed,
            "pass_rate": round(total_passed / total_run, 3) if total_run > 0 else 0.0,
        },
    }


def format_markdown(report: dict[str, Any]) -> str:
    """Format a report as markdown for PR comments."""
    lines = [
        f"## Skill Eval Report ({report['mode']})",
        "",
        f"**Total:** {report['totals']['tests_passed']}/{report['totals']['tests_run']} passed "
        f"({report['totals']['pass_rate'] * 100:.1f}%)",
        "",
        "| Skill | Passed | Score | Status |",
        "|-------|--------|-------|--------|",
    ]

    for name, data in report["skills"].items():
        status = "pass" if data["tests_passed"] == data["tests_run"] else "FAIL"
        lines.append(
            f"| {name} | {data['tests_passed']}/{data['tests_run']} "
            f"| {data['average_score']:.1f} | {status} |"
        )

    # Show failures
    all_failures = []
    for name, data in report["skills"].items():
        for f in data["failures"]:
            all_failures.append((name, f))

    if all_failures:
        lines.extend(["", "### Failures", ""])
        for skill, failure in all_failures:
            lines.append(f"- **{skill}/{failure['test']}** (score: {failure['score']}): {failure['reason']}")

    return "\n".join(lines)
