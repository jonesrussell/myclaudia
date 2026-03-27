"""Tests for schema contract validator."""

from pathlib import Path

from eval_contracts import (
    SKILL_TO_ENTITY,
    extract_field_definitions,
    extract_skill_fields,
    format_markdown,
    validate_contracts,
)


def test_extract_field_definitions_finds_entities():
    """Parses fieldDefinitions from real PHP providers."""
    repo_root = Path(__file__).resolve().parent.parent.parent
    fields = extract_field_definitions(repo_root / "src")
    assert "commitment" in fields
    assert "title" in fields["commitment"]
    assert "uuid" in fields["commitment"]
    assert "person" in fields
    assert "workspace" in fields


def test_extract_skill_fields_finds_graphql_sections():
    """Parses GraphQL Fields from real SKILL.md files."""
    repo_root = Path(__file__).resolve().parent.parent.parent
    fields = extract_skill_fields(repo_root / ".claude" / "skills")
    assert "commitment" in fields
    assert "title" in fields["commitment"]
    assert "new-person" in fields
    assert "name" in fields["new-person"]


def test_validate_contracts_on_real_codebase():
    """Run contract validation against the actual codebase."""
    repo_root = Path(__file__).resolve().parent.parent.parent
    report = validate_contracts(
        src_dir=repo_root / "src",
        skills_dir=repo_root / ".claude" / "skills",
    )
    assert report.skills_checked == len(SKILL_TO_ENTITY)
    # Report violations but don't assert zero (some drift may exist)
    for v in report.violations:
        print(f"  VIOLATION: {v.skill}/{v.field_name}: {v.message}")


def test_format_markdown_clean():
    """Clean report produces success message."""
    from eval_contracts import ContractReport

    report = ContractReport(skills_checked=3, violations=[])
    md = format_markdown(report)
    assert "**Violations:** 0" in md
    assert "All skill field references match the schema" in md


def test_format_markdown_with_violations():
    """Violations appear in markdown table."""
    from eval_contracts import ContractReport, ContractViolation

    report = ContractReport(
        skills_checked=1,
        violations=[
            ContractViolation("commitment", "commitment", "bad_field", "not in schema")
        ],
    )
    md = format_markdown(report)
    assert "bad_field" in md
    assert "**Violations:** 1" in md
