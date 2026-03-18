# Google OAuth Scopes

Scopes requested during the Google OAuth consent flow, as defined in
`src/Controller/GoogleOAuthController.php` constant `SCOPES`.

## Scope Reference

| Scope | Constant Suffix | Purpose |
|-------|----------------|---------|
| `userinfo.email` | `auth/userinfo.email` | Retrieve user email address for the `provider_email` field on Integration records |
| `gmail.readonly` | `auth/gmail.readonly` | Read inbox messages for event ingestion (GmailMessageNormalizer) |
| `gmail.send` | `auth/gmail.send` | Send emails via the agent chat subprocess |
| `calendar.readonly` | `auth/calendar.readonly` | Read calendar events for day-brief assembly |
| `calendar.events` | `auth/calendar.events` | Create and edit calendar events via the agent |
| `calendar.calendarlist.readonly` | `auth/calendar.calendarlist.readonly` | List available calendars for calendar selection |
| `calendar.freebusy` | `auth/calendar.freebusy` | Check availability windows for scheduling |
| `drive.file` | `auth/drive.file` | Per-file access only (future artifact storage, no full drive access) |

## Security Notes

- All scopes use the minimum-privilege variant (e.g. `gmail.readonly` rather than `gmail.modify`).
- `drive.file` grants access only to files created by the application, not the entire Drive.
- The consent flow uses `access_type=offline` and `prompt=consent` to obtain a refresh token.
- Tokens are stored in the `Integration` entity with `provider=google`.

## Token Lifecycle

1. User clicks "Connect Google" in the app shell.
2. `GoogleOAuthController::redirect()` builds the authorization URL with all scopes.
3. Google returns an authorization code to `GoogleOAuthController::callback()`.
4. The controller exchanges the code for access + refresh tokens via Google's token endpoint.
5. Tokens are upserted into an `Integration` entity linked to the authenticated account.
6. `GoogleTokenManager` handles token refresh when the access token expires.

## Internal API Consumption

The agent subprocess accesses Google APIs via internal routes that use `GoogleTokenManager`
to resolve valid tokens:

| Route | Scopes Used |
|-------|-------------|
| `GET /api/internal/gmail/list` | `gmail.readonly` |
| `GET /api/internal/gmail/read/{id}` | `gmail.readonly` |
| `POST /api/internal/gmail/send` | `gmail.send` |
| `GET /api/internal/calendar/list` | `calendar.readonly`, `calendar.calendarlist.readonly` |
| `POST /api/internal/calendar/create` | `calendar.events` |
