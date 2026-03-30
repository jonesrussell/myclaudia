# Repository Guidelines

## Project Structure & Module Organization
`src/` contains the main PHP application, organized by domain-oriented namespaces such as `Command/`, `Controller/`, `Domain/`, `Entity/`, and `Service/`. Twig templates live in `templates/`, public assets in `public/`, runtime data in `storage/`, and longer-form design or ops notes in `docs/`. Tests for the PHP app are in `tests/Unit/` and `tests/Integration/`. The Python agent subprocess lives in `agent/` as the installable package `claudriel_agent` (`agent/claudriel_agent/`), with tools in `agent/claudriel_agent/tools/` and tests in `agent/tests/`.

## Build, Test, and Development Commands
Use Composer scripts for the PHP app:

- `composer lint` checks formatting with Pint.
- `composer format` rewrites PHP formatting.
- `composer analyse` runs PHPStan.
- `composer test` runs PHPUnit unit tests.
- Local admin (split stack): `composer serve:php` binds PHP on **37840**; `cd frontend/admin && npm run dev` serves the SPA on **37841**. Use **`http://localhost:37841/admin/`** (same hostname as `http://localhost:37840` in `.env` / Google OAuth — not `127.0.0.1` for one and `localhost` for the other). Ports: `frontend/admin/devPorts.ts`.

- Playwright: default is **`npm run test:e2e`** in `frontend/admin` (one mocked smoke spec). Full browser suite: **`npm run test:e2e:all`** (run when the split-stack app + auth are stable).

For the Python agent subprocess:

- Architecture and stdin/stdout contracts: [`docs/specs/agent-subprocess.md`](docs/specs/agent-subprocess.md) (adapter-only layer; PHP is the source of truth for business rules).
- `cd agent && python -m pytest tests/` runs agent tests (imports `claudriel_agent` from `agent/` on `sys.path`).
- CI installs `pip install -e './agent[dev]'` from the repo root for Ruff, Black, and mypy.
- Entrypoints: `python agent/main.py` (shim), `python -m claudriel_agent` (after `pip install -e ./agent`), Docker `python -m claudriel_agent`; eval CLIs: `python agent/eval_runner.py`, etc.

CI mirrors these commands in [`.github/workflows/ci.yml`](/home/fsd42/dev/claudriel/.github/workflows/ci.yml).

## Coding Style & Naming Conventions
Follow PSR-4 autoloading: PHP classes use the `Claudriel\\` namespace and live under matching paths in `src/`. Keep class names descriptive and singular where appropriate, for example `Claudriel\\Service\\...` or `Claudriel\\Command\\...`. Use Pint for PHP formatting; do not hand-format around it. In the agent subprocess, use Python 3.11+, `snake_case` module names, and keep lines under 120 characters.

## Testing Guidelines
Add PHP tests under `tests/Unit/` with names ending in `Test.php`; integration tests go under `tests/Integration/`. Add agent subprocess tests as `test_*.py` files under `agent/tests/`. Run the smallest relevant test set locally before opening a PR, then run the full repo checks if your change crosses PHP and agent boundaries.

Full layered smoke (CI parity, Playwright, HTTP script, matrix checklists): [docs/smoke/FULL_SMOKE_CHECKLIST.md](docs/smoke/FULL_SMOKE_CHECKLIST.md).

## Commit & Pull Request Guidelines
Recent history uses short imperative subjects, for example `Make recurring schedule edits safe by default` and `Format schedule times in dashboard`. Keep commits focused and avoid bundling unrelated files. Open PRs against `main` with a clear description, linked issue when applicable, and screenshots for UI changes. Standard flow is branch -> PR -> merge -> GitHub Actions deploy; do not treat direct production edits as normal workflow.

## Security & Configuration Tips
This repo uses local path Composer repositories during development, so avoid hardcoding machine-specific paths outside the existing setup. Treat `storage/`, deployment config, and server access as sensitive; inspect production over SSH only when explicitly requested.
