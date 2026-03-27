#!/usr/bin/env python3
"""Eval runner CLI for Claudriel skill evaluations.

Usage:
    python agent/eval_runner.py --deterministic
    python agent/eval_runner.py --llm-judge [--skill NAME] [--type TYPE]
"""

import argparse
import json
import sys
from pathlib import Path

import yaml

from eval_judge import judge_response
from eval_report import EvalTestResult, SkillResult, format_markdown, generate_report
from eval_schema import discover_eval_files, load_and_validate

PASS_THRESHOLD = 3.0


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Claudriel skill eval runner")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument(
        "--deterministic", action="store_true", help="Schema validation only"
    )
    mode.add_argument(
        "--llm-judge", action="store_true", help="Full LLM-judge evaluation"
    )
    parser.add_argument("--skill", type=str, help="Run evals for specific skill only")
    parser.add_argument(
        "--type",
        type=str,
        choices=["basic", "trajectory", "multi-turn"],
        help="Run specific eval type",
    )
    parser.add_argument(
        "--skills-dir", type=str, default=".claude/skills", help="Skills directory"
    )
    parser.add_argument("--output", type=str, help="Write JSON report to file")
    parser.add_argument(
        "--markdown", action="store_true", help="Print markdown summary to stdout"
    )
    return parser.parse_args(argv)


def run_deterministic(skills_dir: Path) -> dict:
    """Run deterministic validation on all eval files."""
    files = discover_eval_files(skills_dir)
    results: dict[str, SkillResult] = {}

    for eval_file in files:
        skill_name = eval_file.parent.parent.name
        errors = load_and_validate(eval_file)

        if skill_name not in results:
            results[skill_name] = SkillResult(
                tests_run=0, tests_passed=0, average_score=5.0, failures=[]
            )

        results[skill_name].tests_run += 1
        if errors:
            for error in errors:
                results[skill_name].failures.append(
                    EvalTestResult(
                        name=f"{eval_file.name}:{error.message}",
                        score=0.0,
                        reason=error.message,
                    )
                )
        else:
            results[skill_name].tests_passed += 1

    return generate_report(results, mode="deterministic")


def run_llm_judge(
    skills_dir: Path, skill_filter: str | None = None, type_filter: str | None = None
) -> dict:
    """Run LLM-judge evaluation on eval files."""
    import anthropic

    files = discover_eval_files(skills_dir)
    results: dict[str, SkillResult] = {}
    client = anthropic.Anthropic()

    for eval_file in files:
        skill_name = eval_file.parent.parent.name
        if skill_filter and skill_name != skill_filter:
            continue
        if type_filter and type_filter not in eval_file.stem:
            continue

        with open(eval_file) as f:
            data = yaml.safe_load(f)

        if not isinstance(data, dict):
            continue

        skill_md = eval_file.parent.parent / "SKILL.md"
        skill_context = (
            skill_md.read_text() if skill_md.exists() else f"Skill: {skill_name}"
        )
        subject_model = data.get("subject_model", "claude-sonnet-4-6")

        if skill_name not in results:
            results[skill_name] = SkillResult(
                tests_run=0, tests_passed=0, average_score=0.0, failures=[]
            )

        scores_sum = 0.0
        for test in data.get("tests", []):
            results[skill_name].tests_run += 1
            test_name = test.get("name", "unnamed")
            user_input = test.get("input", "")
            assertions = test.get("assertions", [])

            response = client.messages.create(
                model=subject_model,
                max_tokens=2048,
                system=skill_context,
                messages=[{"role": "user", "content": user_input}],
            )
            response_text = response.content[0].text

            score = judge_response(skill_name, user_input, response_text, assertions)
            scores_sum += score.overall

            if score.overall >= PASS_THRESHOLD:
                results[skill_name].tests_passed += 1
            else:
                results[skill_name].failures.append(
                    EvalTestResult(
                        name=test_name,
                        score=score.overall,
                        reason="; ".join(
                            s.reason for s in score.scores if s.score < PASS_THRESHOLD
                        ),
                    )
                )

        if results[skill_name].tests_run > 0:
            results[skill_name].average_score = (
                scores_sum / results[skill_name].tests_run
            )

    return generate_report(results, mode="llm-judge")


def main() -> None:
    args = parse_args()
    skills_dir = Path(args.skills_dir)

    if args.deterministic:
        report = run_deterministic(skills_dir)
    else:
        report = run_llm_judge(skills_dir, args.skill, args.type)

    if args.output:
        Path(args.output).parent.mkdir(parents=True, exist_ok=True)
        Path(args.output).write_text(json.dumps(report, indent=2))
        print(f"Report written to {args.output}", file=sys.stderr)

    if args.markdown:
        print(format_markdown(report))
    else:
        print(json.dumps(report, indent=2))

    if report["totals"]["tests_passed"] < report["totals"]["tests_run"]:
        sys.exit(1)


if __name__ == "__main__":
    main()
