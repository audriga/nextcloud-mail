<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\JsonLd;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\Message;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Exception\SmimeDecryptException;
use OCA\Mail\Model\IMAPMessage;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\DelegationService;
use OCA\Mail\Service\JsonLd\EmailMessageMapper;
use OCA\Mail\Service\JsonLd\JsonLdService;
use OCA\Mail\Service\MailManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class JsonLdServiceTest extends TestCase {
	private DelegationService&MockObject $delegationService;
	private MailManager&MockObject $mailManager;
	private AccountService&MockObject $accountService;
	private IURLGenerator&MockObject $urlGenerator;
	private LoggerInterface&MockObject $logger;
	private Account&MockObject $account;
	private Mailbox $mailbox;
	private JsonLdService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->delegationService = $this->createMock(DelegationService::class);
		$this->mailManager = $this->createMock(MailManager::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->account = $this->createMock(Account::class);

		$this->mailbox = new Mailbox();
		$this->mailbox->setId(3);
		$this->mailbox->setAccountId(5);

		$this->delegationService->method('resolveMessageUserId')->willReturn('alice');
		$this->accountService->method('find')->willReturn($this->account);
		$this->mailManager->method('getMailbox')->willReturn($this->mailbox);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => match ($route) {
				'mail.jsonld.message' => 'https://cloud.example.com/apps/mail/lda/message/' . $parameters['id'] . (isset($parameters['format']) ? '?format=' . $parameters['format'] : ''),
				'mail.page.thread' => 'https://cloud.example.com/apps/mail/box/' . $parameters['mailboxId'] . '/thread/' . $parameters['id'],
			},
		);

		$this->service = new JsonLdService(
			$this->delegationService,
			$this->mailManager,
			$this->accountService,
			new EmailMessageMapper(),
			$this->urlGenerator,
			$this->logger,
		);
	}

	private function message(int $id, int $sentAt, ?string $threadRootId = null): Message {
		$message = new Message();
		$message->setId($id);
		$message->setMailboxId(3);
		$message->setSubject('Subject');
		$message->setSentAt($sentAt);
		$message->setThreadRootId($threadRootId);

		return $message;
	}

	private function imapMessage(string $messageId, bool $hasHtml = false): IMAPMessage&MockObject {
		$imapMessage = $this->createMock(IMAPMessage::class);
		$imapMessage->method('getFullMessage')->willReturn([
			'uid' => 1,
			'messageId' => $messageId,
			'from' => [['label' => 'Alice', 'email' => 'alice@example.com']],
			'to' => [],
			'cc' => [],
			'bcc' => [],
			'subject' => 'Hello',
			'dateInt' => 1767323045,
			'hasHtmlBody' => $hasHtml,
		]);
		$imapMessage->method('hasHtmlMessage')->willReturn($hasHtml);
		$imapMessage->method('getHtmlBody')->willReturn('<p>Html Body</p>');
		$imapMessage->method('getPlainBody')->willReturn('Plain Body');

		return $imapMessage;
	}

	/**
	 * @param array<int, string> $messageIds DB message id => RFC Message-ID
	 * @param int[] $failingIds DB message ids whose IMAP fetch fails
	 */
	private function stubImapMessages(array $messageIds, array $failingIds = [], array $htmlIds = []): void {
		$this->mailManager->method('getImapMessage')->willReturnCallback(
			function (Account $account, Mailbox $mailbox, Message $message) use ($messageIds, $failingIds, $htmlIds): IMAPMessage {
				if (in_array($message->getId(), $failingIds, true)) {
					throw new ServiceException('IMAP unavailable');
				}

				$hasHtml = in_array($message->getId(), $htmlIds, true);
				return $this->imapMessage($messageIds[$message->getId()], $hasHtml);
			},
		);
	}

	public function testGetMessageHtmlByDefaultWhenAvailable(): void {
		$message = $this->message(42, 1767323045);
		$this->mailManager->expects($this->once())
			->method('getMessage')
			->with('alice', 42)
			->willReturn($message);
		$this->mailManager->expects($this->once())
			->method('getImapMessage')
			->with($this->anything(), $this->anything(), $message, true)
			->willReturn($this->imapMessage('<abc@example.com>', true));

		$result = $this->service->getMessage('alice', 42);

		$this->assertSame('https://schema.org/', $result['@context']);
		$this->assertSame('<abc@example.com>', $result['identifier']);
		$this->assertSame('42', $result['id']);
		$this->assertSame('text/html', $result['encodingFormat']);
		$this->assertSame('<p>Html Body</p>', $result['description']);
		$this->assertSame(
			['@type' => 'ViewAction', 'target' => 'https://cloud.example.com/apps/mail/box/3/thread/42'],
			$result['potentialAction'][0],
		);
		$this->assertSame(
			['@type' => 'RefreshAction', 'target' => 'https://cloud.example.com/apps/mail/lda/message/42', 'authentication' => 'basic'],
			$result['potentialAction'][1],
		);
	}

	public function testGetMessagePlainWhenRequested(): void {
		$message = $this->message(42, 1767323045);
		$this->mailManager->method('getMessage')->willReturn($message);
		$this->mailManager->method('getImapMessage')->willReturn($this->imapMessage('<abc@example.com>', true));

		$result = $this->service->getMessage('alice', 42, JsonLdService::FORMAT_PLAIN);

		$this->assertSame('text/plain', $result['encodingFormat']);
		$this->assertSame('Plain Body', $result['description']);
		$this->assertSame('https://cloud.example.com/apps/mail/lda/message/42?format=plain', $result['potentialAction'][1]['target']);
	}

	public function testGetMessageFallbackToPlainWhenHtmlNotAvailable(): void {
		$message = $this->message(42, 1767323045);
		$this->mailManager->method('getMessage')->willReturn($message);
		$this->mailManager->method('getImapMessage')->willReturn($this->imapMessage('<abc@example.com>', false));

		$result = $this->service->getMessage('alice', 42, JsonLdService::FORMAT_HTML);

		$this->assertSame('text/plain', $result['encodingFormat']);
		$this->assertSame('Plain Body', $result['description']);
	}

	public function testGetMessageThrowsClientExceptionWhenNotFound(): void {
		$this->mailManager->method('getMessage')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(ClientException::class);

		$this->service->getMessage('alice', 42);
	}

	public function testGetMessageThrowsServiceExceptionWhenBodyCannotBeDecrypted(): void {
		$this->mailManager->method('getMessage')->willReturn($this->message(42, 1767323045));
		$this->mailManager->method('getImapMessage')->willThrowException(new SmimeDecryptException('no key'));

		$this->expectException(ServiceException::class);

		$this->service->getMessage('alice', 42);
	}

	public function testGetThreadWithoutThreadRootReturnsSingleMessage(): void {
		$message = $this->message(42, 1767323045, null);
		$this->mailManager->method('getMessage')->willReturn($message);
		$this->mailManager->expects($this->never())->method('getThread');
		$this->mailManager->method('getImapMessage')->willReturn($this->imapMessage('<abc@example.com>'));

		$result = $this->service->getThread('alice', 42);

		$this->assertSame('https://schema.org/', $result['@context']);
		$this->assertSame('Conversation', $result['@type']);
		$this->assertSame('42', $result['id']);
		$this->assertSame('Subject', $result['headline']);
		$this->assertSame(1, $result['numberOfItems']);
		$this->assertArrayNotHasKey('potentialAction', $result);
		$this->assertSame([['@type' => 'ViewAction', 'target' => 'https://cloud.example.com/apps/mail/box/3/thread/42']], $result['itemListElement'][0]['potentialAction']);
	}

	public function testGetThreadReturnsAllMessagesChronologically(): void {
		$requested = $this->message(1, 100, 'root@example.com');
		$this->mailManager->method('getMessage')->willReturn($requested);
		$this->mailManager->expects($this->once())
			->method('getThread')
			->with($this->anything(), '<root@example.com>')
			->willReturn([
				$requested,
				$this->message(2, 300, 'root@example.com'),
				$this->message(3, 200, 'root@example.com'),
			]);
		$this->stubImapMessages([
			1 => '<m1@example.com>',
			2 => '<m2@example.com>',
			3 => '<m3@example.com>',
		], [], [1]);

		$result = $this->service->getThread('alice', 1, JsonLdService::FORMAT_HTML);

		$this->assertSame(3, $result['numberOfItems']);
		$this->assertSame(['<m1@example.com>', '<m3@example.com>', '<m2@example.com>'], array_column($result['itemListElement'], 'identifier'));
		$this->assertSame('text/html', $result['itemListElement'][0]['encodingFormat']);
		$this->assertSame('text/plain', $result['itemListElement'][1]['encodingFormat']);
	}

	public function testGetThreadSkipsMessagesThatFailToLoad(): void {
		$requested = $this->message(1, 100, 'root@example.com');
		$this->mailManager->method('getMessage')->willReturn($requested);
		$this->mailManager->method('getThread')->willReturn([
			$requested,
			$this->message(2, 200, 'root@example.com'),
		]);
		$this->stubImapMessages([
			1 => '<m1@example.com>',
			2 => '<m2@example.com>',
		], [1]);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->service->getThread('alice', 1);

		$this->assertSame(1, $result['numberOfItems']);
		$this->assertSame(['<m2@example.com>'], array_column($result['itemListElement'], 'identifier'));
	}

	public function testGetThreadThrowsServiceExceptionWhenNoMessageCanBeLoaded(): void {
		$requested = $this->message(1, 100, 'root@example.com');
		$this->mailManager->method('getMessage')->willReturn($requested);
		$this->mailManager->method('getThread')->willReturn([$requested]);
		$this->stubImapMessages([1 => '<m1@example.com>'], [1]);

		$this->expectException(ServiceException::class);

		$this->service->getThread('alice', 1);
	}

	public function testGetThreadHeadlineIsSubjectOfOldestMessage(): void {
		$root = $this->message(1, 100, 'root@example.com');
		$root->setSubject('Root subject');
		$reply = $this->message(2, 300, 'root@example.com');
		$reply->setSubject('Reply');
		$this->mailManager->method('getMessage')->willReturn($root);
		$this->mailManager->method('getThread')->willReturn([$reply, $root]);
		$this->stubImapMessages([1 => '<m1@example.com>', 2 => '<m2@example.com>']);

		$result = $this->service->getThread('alice', 1);

		$this->assertSame('Root subject', $result['headline']);
	}

	public function testGetThreadBreaksSentAtTiesByOldestId(): void {
		$first = $this->message(1, 100, 'root@example.com');
		$second = $this->message(2, 100, 'root@example.com');
		$this->mailManager->method('getMessage')->willReturn($first);
		$this->mailManager->method('getThread')->willReturn([$second, $first]);
		$this->stubImapMessages([1 => '<m1@example.com>', 2 => '<m2@example.com>']);

		$result = $this->service->getThread('alice', 1);

		$this->assertSame(['<m1@example.com>', '<m2@example.com>'], array_column($result['itemListElement'], 'identifier'));
	}
}
