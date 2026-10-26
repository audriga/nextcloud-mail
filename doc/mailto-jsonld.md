<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Open a JSON-LD compose window via Mailto (SessionStorage Pattern)

Apps can open Mail's existing composer with a Schema.org JSON-LD object in the message body by staging the payload in browser `sessionStorage` and redirecting the browser to `/apps/mail/mailto`.

This pattern completely replaces the previous form-based `POST /compose` submission flow:
- **No URL size limits**: The payload is stored in client-side `sessionStorage`, so large JSON-LD objects transfer reliably.
- **No server transport storage**: No drafts or server cache entries are created for transport.
- **Full Mailto support**: Standard mailto query parameters (`to`, `subject`, `cc`, `bcc`) work seamlessly alongside `itemid`.

---

## 1. Desired Pattern (Caller Usage)

```js
import { generateUrl } from '@nextcloud/router'

// 1. Generate an item ID and stage payload in sessionStorage
const itemId = 'mail-' + Date.now().toString(36)
sessionStorage.setItem(itemId, JSON.stringify({
    jsonld: jsonLdObject,
}))

// 2. Redirect to Mail's mailto route (with optional recipients and subject)
window.location.href = generateUrl(`/apps/mail/mailto?itemid=${itemId}&to=recipient@example.com&subject=Recipe`)
```

---

## 2. Request Parameters

Query parameters accepted on `GET /apps/mail/mailto`:

| Parameter | Type | Description |
| --- | --- | --- |
| `itemid` | `string` | **Required for JSON-LD**. Key referencing the staged object in `sessionStorage`. |
| `to` | `string` | Optional recipient email address or comma-separated list. |
| `subject` | `string` | Optional email subject line. |
| `cc` | `string` | Optional CC recipient(s). |
| `bcc` | `string` | Optional BCC recipient(s). |
| `accountId` | `int` | Optional specific Mail account ID to send from. (Database primary key in `id` of `oc_mail_accounts` table) |

---

## 3. How It Works

1. The calling app writes `{ jsonld: jsonLdObject }` to `sessionStorage` under `itemId`.
2. The browser navigates via GET to `/apps/mail/mailto?itemid=...`.
3. Mail's PHP controller (`PageController::mailto`) injects `mailto_item_id` (and optional `mailto_account_id`) into Nextcloud `InitialStateService` and returns Mail's app page.
4. On client startup, Mail's `MailboxThread.vue::handleMailto` detects `mailto_item_id`:
   - Retrieves the payload from `sessionStorage.getItem(itemId)`.
   - Immediately purges it via `sessionStorage.removeItem(itemId)` to prevent duplicate sends on subsequent reloads.
   - Renders the interactive Schema.org card HTML client-side using Mail's bundled `hetc` renderer.
   - Embeds `<script type="application/ld+json">` alongside the rendered card HTML in the message body.
   - Opens the composer with all pre-filled query fields (`to`, `subject`, etc.) and the rendered card body.

---

## 4. Vue Component Example

```vue
<template>
	<button type="button" @click="shareInMail">
		Share in Mail
	</button>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ShareJsonLdInMail',
	props: {
		jsonld: {
			type: Object,
			required: true,
		},
		recipient: {
			type: String,
			default: '',
		},
		subject: {
			type: String,
			default: '',
		},
	},
	methods: {
		shareInMail() {
			const itemId = 'mail-' + Date.now().toString(36) + Math.random().toString(36).substring(2, 7)
			sessionStorage.setItem(itemId, JSON.stringify({
				jsonld: this.jsonld,
			}))

			const query = { itemid: itemId }
			if (this.recipient) {
				query.to = this.recipient
			}
			if (this.subject) {
				query.subject = this.subject
			}

			const url = generateUrl('/apps/mail/mailto', query)
			window.location.href = url
		},
	},
}
</script>
```
