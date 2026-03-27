"""Tests for eval report generation."""

from eval_report import EvalTestResult, SkillResult, format_markdown, generate_report


def test_generate_report_structure():
    results = {
        "commitment": SkillResult(
            tests_run=10,
            tests_passed=9,
            average_score=4.2,
            failures=[EvalTestResult(name="edge-case", score=1.0, reason="missed")],
        ),
    }
    report = generate_report(results)
    assert report["totals"]["tests_run"] == 10
    assert report["totals"]["tests_passed"] == 9
    assert report["totals"]["pass_rate"] == 0.9
    assert "commitment" in report["skills"]


def test_generate_report_empty():
    report = generate_report({})
    assert report["totals"]["tests_run"] == 0
    assert report["totals"]["pass_rate"] == 0.0


def test_format_markdown_includes_summary():
    results = {
        "commitment": SkillResult(
            tests_run=5, tests_passed=5, average_score=4.5, failures=[]
        ),
    }
    report = generate_report(results)
    md = format_markdown(report)
    assert "commitment" in md
    assert "5/5" in md or "100%" in md


def test_format_markdown_shows_failures():
    results = {
        "commitment": SkillResult(
            tests_run=3,
            tests_passed=1,
            average_score=2.0,
            failures=[
                EvalTestResult(name="test-a", score=1.0, reason="bad"),
                EvalTestResult(name="test-b", score=0.0, reason="wrong"),
            ],
        ),
    }
    report = generate_report(results)
    md = format_markdown(report)
    assert "test-a" in md
    assert "test-b" in md
