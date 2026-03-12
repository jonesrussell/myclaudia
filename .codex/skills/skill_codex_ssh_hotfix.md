---
name: codex-ssh-hotfix
description: Use when the user explicitly instructs Codex to inspect or hotfix Claudriel production over SSH, especially for the production Caddyfile, logs, or runtime validation.
---

# Codex SSH Hotfix

Use this skill only when the user explicitly authorizes production SSH work.

## SSH From WSL

- Start in WSL.
- Primary entry point: `ssh deployer@claudriel.northcloud.one`
- Use elevated commands only if required and approved.

## Allowed Commands

Prefer read-first commands:

- `pwd`
- `ls -la /home/deployer/claudriel`
- `sed -n '1,220p' /home/deployer/claudriel/Caddyfile`
- `grep -nE 'basicauth|basic_auth|reverse_proxy|php_fastcgi' /home/deployer/claudriel/Caddyfile`
- `tail -n 100 /home/deployer/claudriel/log/access.log`
- `journalctl -u caddy -n 100 --no-pager`

Allowed edit scope:

- `/home/deployer/claudriel/Caddyfile`
- logs or validation output read from `/home/deployer/claudriel/`

Never edit unrelated server config files.

## Safely Edit `/home/deployer/claudriel/Caddyfile`

1. Read the file fully enough to identify the exact stanza to change.
2. Create a timestamped backup next to the file.
3. Edit only the requested block.
4. Re-read the affected section immediately after the edit.
5. Do not modify TLS, reverse proxy, PHP-FPM, or unrelated domain routing unless the task explicitly requires it.

## Validate Caddy Config

Preferred order:

1. `caddy validate --config /home/deployer/claudriel/Caddyfile`
2. If runtime file permissions block full validation: `caddy adapt --config /home/deployer/claudriel/Caddyfile >/tmp/claudriel-caddy.json`
3. If approved, validate the full active config from `/etc/caddy/Caddyfile`

State clearly whether validation was full or syntax-only.

## Restart Or Reload Caddy Safely

- Prefer reload over restart.
- Preferred command: `caddy reload --config /etc/caddy/Caddyfile`
- If reload is unavailable and approved, use `sudo systemctl reload caddy`
- Restart only if reload is impossible and the user explicitly accepts the higher-risk action.

## Temporary Hotfix Rules

- SSH fixes are temporary unless the same change is committed in the repo.
- After any hotfix, identify the canonical repo file that must be updated.
- Never leave a production-only change undocumented if a normal deploy could overwrite it.
