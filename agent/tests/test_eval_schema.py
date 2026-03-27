"""Tests for eval YAML schema validation."""

from eval_schema import ValidationError, discover_eval_files, validate_eval_file


def test_valid_basic_eval():
    """A well-formed basic eval file passes validation."""
    data = {
        "schema_version": "1.0",
        "skill": "commitment",
        "entity_type": "commitment",
        "tests": [
            {
                "name": "create-simple",
                "operation": "create",
                "input": "I owe Sarah a proposal",
                "assertions": [{"type": "confirmation_shown"}],
            }
        ],
    }
    errors = validate_eval_file(data, "basic.yaml")
    assert errors == []


def test_missing_tests_and_prompts():
    """Missing both tests and prompts fails."""
    data = {"skill": "test"}
    errors = validate_eval_file(data, "bad.yaml")
    assert any("tests or prompts" in e.message for e in errors)


def test_invalid_assertion_type():
    """Unknown assertion type is flagged."""
    data = {
        "schema_version": "1.0",
        "skill": "commitment",
        "entity_type": "commitment",
        "tests": [
            {
                "name": "bad-assertion",
                "operation": "create",
                "input": "test",
                "assertions": [{"type": "nonexistent_type"}],
            }
        ],
    }
    errors = validate_eval_file(data, "test.yaml")
    assert any("nonexistent_type" in e.message for e in errors)


def test_duplicate_test_names():
    """Duplicate test names within a file are flagged."""
    data = {
        "schema_version": "1.0",
        "skill": "commitment",
        "entity_type": "commitment",
        "tests": [
            {"name": "dupe", "operation": "create", "input": "a", "assertions": []},
            {"name": "dupe", "operation": "list", "input": "b", "assertions": []},
        ],
    }
    errors = validate_eval_file(data, "test.yaml")
    assert any("duplicate" in e.message.lower() for e in errors)


def test_test_missing_required_fields():
    """Test case missing name, operation, or input is flagged."""
    data = {
        "schema_version": "1.0",
        "skill": "commitment",
        "entity_type": "commitment",
        "tests": [{"assertions": []}],
    }
    errors = validate_eval_file(data, "test.yaml")
    assert any("name" in e.message for e in errors)


def test_discover_eval_files(tmp_path):
    """discover_eval_files finds YAML files in skill eval dirs."""
    # Create a fixture skills directory with eval files
    skill_dir = tmp_path / "commitment" / "evals"
    skill_dir.mkdir(parents=True)
    (skill_dir / "basic.yaml").write_text("schema_version: '1.0'\n")
    (skill_dir / "advanced.yml").write_text("schema_version: '1.0'\n")

    # Also create a non-eval dir that should be ignored
    (tmp_path / "commitment" / "other").mkdir()
    (tmp_path / "commitment" / "other" / "ignore.yaml").write_text("")

    files = discover_eval_files(tmp_path)
    assert len(files) == 2
    assert all(f.suffix in (".yaml", ".yml") for f in files)
