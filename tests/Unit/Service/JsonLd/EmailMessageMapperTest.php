<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\JsonLd;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\JsonLd\EmailMessageMapper;

class EmailMessageMapperTest extends TestCase {
	private const MESSAGE_URL = 'https://cloud.example.com/apps/mail/lda/message/194';
	private const MESSAGE_PLAIN_URL = 'https://cloud.example.com/apps/mail/lda/message/194?format=plain';
	private const THREAD_URL = 'https://cloud.example.com/apps/mail/lda/thread/194';
	private const VIEW_URL = 'https://cloud.example.com/apps/mail/box/12/thread/194';

	private EmailMessageMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new EmailMessageMapper();
	}

	private function htmlMessage(): array {
		return [
			'uid' => 194,
			'messageId' => '<CA+abc123xyz@mail.gmail.com>',
			'from' => [['label' => 'Alice', 'email' => 'alice@example.com']],
			'to' => [['label' => 'Bob Smith', 'email' => 'bob@example.com']],
			'replyTo' => [],
			'cc' => [['label' => 'Carol Danvers', 'email' => 'carol@example.com']],
			'bcc' => [],
			'subject' => 'Project status update and Q3 planning',
			'dateInt' => 1791963000,
			'hasHtmlBody' => true,
			'encodingFormat' => 'text/html',
			'body' => '<p>Hi team,</p><p>Here is the project status update and our goals for next sprint.</p><p>Best regards,<br>Alice</p>',
		];
	}

	public function testMapMessageHtmlBody(): void {
		$result = $this->mapper->mapMessage($this->htmlMessage(), '194', self::MESSAGE_URL, self::VIEW_URL);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'EmailMessage',
			'id' => '194',
			'identifier' => '<CA+abc123xyz@mail.gmail.com>',
			'headline' => 'Project status update and Q3 planning',
			'description' => '<p>Hi team,</p><p>Here is the project status update and our goals for next sprint.</p><p>Best regards,<br>Alice</p>',
			'encodingFormat' => 'text/html',
			'createdBy' => 'alice@example.com',
			'toRecipient' => [
				[
					'@type' => 'Person',
					'name' => 'Bob Smith',
					'email' => 'bob@example.com',
				],
			],
			'ccRecipient' => [
				[
					'@type' => 'Person',
					'name' => 'Carol Danvers',
					'email' => 'carol@example.com',
				],
			],
			'bccRecipient' => [],
			'dateCreated' => '2026-10-14T07:30:00+00:00',
			'potentialAction' => [
				['@type' => 'ViewAction', 'target' => self::VIEW_URL],
				['@type' => 'RefreshAction', 'target' => self::MESSAGE_URL, 'authentication' => 'basic'],
			],
		], $result);
	}

	public function testMapMessagePlainBody(): void {
		$message = $this->htmlMessage();
		$message['encodingFormat'] = 'text/plain';
		$message['body'] = "Hi team,\n\nHere is the project status update.";

		$result = $this->mapper->mapMessage($message, '194', self::MESSAGE_PLAIN_URL, self::VIEW_URL);

		$this->assertSame('text/plain', $result['encodingFormat']);
		$this->assertSame("Hi team,\n\nHere is the project status update.", $result['description']);
		$this->assertSame(self::MESSAGE_PLAIN_URL, $result['potentialAction'][1]['target']);
	}

	public function testMapMessageWithoutSender(): void {
		$message = $this->htmlMessage();
		$message['from'] = [];

		$result = $this->mapper->mapMessage($message, '194', self::MESSAGE_URL, self::VIEW_URL);

		$this->assertNull($result['createdBy']);
	}

	public function testEmailMessageItemHasViewActionOnlyAndNoContext(): void {
		$result = $this->mapper->emailMessageItem($this->htmlMessage(), '194', self::VIEW_URL);

		$this->assertArrayNotHasKey('@context', $result);
		$this->assertSame([['@type' => 'ViewAction', 'target' => self::VIEW_URL]], $result['potentialAction']);
	}

	public function testMapConversation(): void {
		$item1 = $this->mapper->mapMessage($this->htmlMessage(), '194', self::MESSAGE_URL, self::VIEW_URL);
		$item2 = $this->mapper->mapMessage([
			'uid' => 195,
			'messageId' => '<CA+reply456@mail.gmail.com>',
			'from' => [['label' => 'Bob Smith', 'email' => 'bob@example.com']],
			'to' => [['label' => 'Alice Johnson', 'email' => 'alice@example.com']],
			'replyTo' => [],
			'cc' => [['label' => 'Carol Danvers', 'email' => 'carol@example.com']],
			'bcc' => [],
			'subject' => 'Re: Project status update and Q3 planning',
			'dateInt' => 1791965700,
			'hasHtmlBody' => false,
			'encodingFormat' => 'text/plain',
			'body' => "Thanks Alice! Looks good to me, let's proceed.\n\nBob",
		], '195', 'https://cloud.example.com/apps/mail/lda/message/195', self::VIEW_URL);

		$conversation = $this->mapper->mapConversation(
			'Project status update and Q3 planning',
			[$item1, $item2],
			'194',
			self::THREAD_URL,
		);

		$this->assertSame([
			'@context' => 'https://schema.org/',
			'@type' => 'Conversation',
			'id' => '194',
			'headline' => 'Project status update and Q3 planning',
			'numberOfItems' => 2,
			'itemListElement' => [
				array_diff_key($item1, ['@context' => true]),
				array_diff_key($item2, ['@context' => true]),
			],
			'potentialAction' => [
				['@type' => 'RefreshAction', 'target' => self::THREAD_URL, 'authentication' => 'basic'],
			],
		], $conversation);
	}
}
