<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Mail LDA (JSON-LD Action API)

Mail provides read-only JSON-LD endpoints for retrieving a message as a Schema.org `EmailMessage` or a conversation as a `Conversation`. Both endpoints use HTTP GET and return JSON (`Content-Type: application/json`).

## Usage

### Authentication

Authenticate as a Nextcloud user with HTTP Basic authentication. For API clients, use a Nextcloud app password. The authenticated user must have access to the requested message.

### Endpoints

| Request | Response |
| --- | --- |
| `GET /apps/mail/lda/message/{id}` | One `EmailMessage` |
| `GET /apps/mail/lda/thread/{id}` | A `Conversation` containing the messages in the thread |

`{id}` is the Mail database ID of a message. The thread endpoint also takes a message ID; it finds that message's conversation.

Both endpoints accept the optional `format` query parameter:

| Value | Body format |
| --- | --- |
| `html` (default) | Sanitized HTML when available; otherwise plain text |
| `plain` | Plain text |

The selected body format is reported in `encodingFormat` as `text/html` or `text/plain`. Currently, a `format` value other than exactly `plain` follows the default HTML-selection path.

### cURL examples

Replace the placeholders with your Nextcloud URL, user and app password. Do not put a real app password in documentation or commit it to source control.

```bash
# Message, using the default format
curl -i --user '<user>:<app-password>' \
  '<nextcloud-url>/index.php/apps/mail/lda/message/<message-db-id>'

# Message as plain text
curl -i --user '<user>:<app-password>' \
  '<nextcloud-url>/index.php/apps/mail/lda/message/<message-db-id>?format=plain'

# Thread as plain text
curl -i --user '<user>:<app-password>' \
  '<nextcloud-url>/index.php/apps/mail/lda/thread/<message-db-id>?format=plain'
```

For a local instance, the original example IDs can be tested with a local app password:

```bash
curl -i --user 'admin:<app-password>' \
  'http://localhost/index.php/apps/mail/lda/thread/292'

curl -i --user 'admin:<app-password>' \
  'http://localhost/index.php/apps/mail/lda/message/28'
```

## Response data

### Message: `EmailMessage`

The message endpoint returns one `EmailMessage` JSON-LD object.

| Property | Meaning |
| --- | --- |
| `@context` | `https://schema.org/` |
| `@type` | `EmailMessage` |
| `id` | Mail database message ID, represented as a string |
| `identifier` | RFC Message-ID from the email |
| `headline` | Subject |
| `description` | Message body in the selected format |
| `encodingFormat` | `text/html` or `text/plain` |
| `createdBy` | Sender email address, or `null` if there is no sender |
| `toRecipient`, `ccRecipient`, `bccRecipient` | Arrays of `Person` objects with `name` and `email` |
| `dateCreated` | Sent date in ISO 8601 format |
| `potentialAction` | `ViewAction` targeting `/apps/mail/box/{mailboxId}/thread/{id}` and `RefreshAction` targeting this message endpoint; the refresh action uses basic authentication |

### Thread: `Conversation`

The thread endpoint returns a `Conversation` with these top-level properties:

- `@context`: `https://schema.org/`
- `@type`: `Conversation`
- `id`: database ID of the message supplied in the request
- `headline`: subject of the oldest message in the conversation
- `numberOfItems`: number of messages successfully loaded
- `itemListElement`: `EmailMessage` objects in chronological order (oldest first, with database ID as the tie-breaker)
- `potentialAction`: a `RefreshAction` targeting this thread endpoint (`/apps/mail/lda/thread/{id}`, with `?format=plain` when requested) and using basic authentication

Each item has the message properties listed above, except that it has only a `ViewAction` and does not include its own `@context`. Messages that cannot be loaded are skipped; if no messages can be loaded, the endpoint returns an error.

## Current limitations

- **Database IDs are required.** Both endpoints take Mail's database message ID in the URL. The RFC Message-ID is returned as `identifier`, but is not accepted as a lookup key.
- **Mailbox selection follows the requested message.** The message endpoint loads the mailbox that owns the requested message ID; it does not choose the first mailbox. For a thread, Mail finds the requested message's account and calls `MailManager::getThread()` with its thread-root ID. This returns matching messages across that account's mailboxes, not just the requested message's mailbox. If the requested message has no thread root, the response contains only that message. Threads in another Mail account are not included.
- **Threads are not paginated.** The thread endpoint currently returns all matching messages it can load in one response; there are no paging parameters or continuation links.

## Authentication, access and errors

The controller uses Nextcloud authentication and resolves access to the requested message through `DelegationService::resolveMessageUserId()`, then loads the message, its mailbox and its account before accessing IMAP. Although the routes use `#[PublicPage]` so the controller can return the endpoint's JSON-LD authentication response, an unauthenticated request is rejected before any message lookup. The endpoints are also marked `#[NoAdminRequired]` and `#[NoCSRFRequired]`. They do not provide public-share access.

All responses, including errors, use the Schema.org context and JSON content type.

| HTTP status | Response |
| --- | --- |
| `401 Unauthorized` | `LDAActionResponse` with `status: error`, an authentication description, and a `LoginAction` (`type: basic`) targeting the requested GET URL |
| `404 Not Found` | `ErrorAction` when the message, mailbox or account cannot be found or accessed |
| `500 Internal Server Error` | `ErrorAction` when Mail cannot load the message from IMAP, including when a thread has no loadable messages |

The `ErrorAction` contains `name`, a short non-leaky `description`, and `requestPath`. Internal error details are written to the log. Error responses do not include a `ViewAction`.

## Implementation notes

- The feature uses a dedicated controller rather than changing existing controllers. It returns a plain `JSONResponse`, not an OCS response envelope, and reuses Mail services through dependency injection. This separation keeps the implementation self-contained and leaves open the possibility of extracting it into a wrapper app later.
- The database ID is used for access-scoped lookup; the RFC Message-ID is exposed in the response as `identifier`.
- The HTML body comes from Mail's HTML sanitization service. Plain text can be selected explicitly, and is also used when an HTML body is unavailable.
- Out of scope: `CommentAction`, POST operations, public/share pages, lookup by RFC Message-ID, Talk integration and an OpenAPI specification.

Main implementation files:

```text
lib/Controller/JsonLdController.php          # authentication, access checks and responses
lib/Service/JsonLd/JsonLdService.php         # message/thread loading and body selection
lib/Service/JsonLd/EmailMessageMapper.php    # maps Mail message data to JSON-LD
lib/Service/JsonLd/JsonLdErrorMapper.php    # maps errors to JSON-LD
appinfo/routes.php                           # GET routes
```

Unit tests are in `tests/Unit/Controller/JsonLdControllerTest.php` and:

```text
tests/Unit/Service/JsonLd/EmailMessageMapperTest.php
tests/Unit/Service/JsonLd/JsonLdErrorMapperTest.php
tests/Unit/Service/JsonLd/JsonLdServiceTest.php
```

They cover response mapping, body formats and fallback, missing optional fields, thread ordering and partial-load behavior, and the 401/404/500 response shapes. Implementation follows the app's conventions: AGPL-3.0-or-later SPDX headers, minimal comments, native types, constructor promotion, `Http::STATUS_*` constants, and logging exceptions with `LoggerInterface` context.

Run the focused checks from `apps/mail`:

```bash
composer test:unit -- --filter=JsonLd
composer cs:check
composer psalm
```

For a manual check against a live instance, call an endpoint with an app password and call it again without credentials to verify the JSON-LD `401` response.
