<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\JsonLd;

use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\Message;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Exception\SmimeDecryptException;
use OCA\Mail\Model\IMAPMessage;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\DelegationService;
use OCA\Mail\Service\MailManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class JsonLdService {
	public const FORMAT_HTML = 'html';
	public const FORMAT_PLAIN = 'plain';

	public function __construct(
		private DelegationService $delegationService,
		private MailManager $mailManager,
		private AccountService $accountService,
		private EmailMessageMapper $mapper,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws ClientException if the message, its mailbox or account is not accessible to the user
	 * @throws ServiceException if the message could not be loaded from IMAP
	 */
	public function getMessage(string $userId, int $id, string $format = self::FORMAT_HTML): array {
		[, $message, $mailbox, $account] = $this->loadMessage($userId, $id);
		$fullMessage = $this->loadFullMessage($account, $mailbox, $message, $format);

		return $this->mapper->mapMessage(
			$fullMessage,
			(string)$id,
			$this->messageUrl($id, $format),
			$this->viewUrl($mailbox->getId(), $id),
		);
	}

	/**
	 * Returns the thread of the message, oldest first. Messages that fail to load are skipped.
	 *
	 * @throws ClientException if the message, its mailbox or account is not accessible to the user
	 * @throws ServiceException if none of the thread messages could be loaded
	 */
	public function getThread(string $userId, int $id, string $format = self::FORMAT_HTML): array {
		[$effectiveUserId, $message, $mailbox, $account] = $this->loadMessage($userId, $id);
		$threadMessages = $message->getThreadRootId() === null
			? [$message]
			: $this->mailManager->getThread($account, $message->getThreadRootId());
		usort($threadMessages, static fn (Message $a, Message $b): int => [$a->getSentAt(), $a->getId()] <=> [$b->getSentAt(), $b->getId()]);
		$headline = $threadMessages[0]->getSubject();

		$items = [];
		$mailboxes = [];
		foreach ($threadMessages as $threadMessage) {
			try {
				$threadMailbox = $mailboxes[$threadMessage->getMailboxId()] ??= $this->mailManager->getMailbox($effectiveUserId, $threadMessage->getMailboxId());
				$fullMessage = $this->loadFullMessage($account, $threadMailbox, $threadMessage, $format);
			} catch (ClientException|DoesNotExistException|ServiceException $e) {
				$this->logger->warning('Skipping thread message that could not be loaded for JSON-LD', ['exception' => $e]);
				continue;
			}

			$items[] = $this->mapper->emailMessageItem(
				$fullMessage,
				(string)$threadMessage->getId(),
				$this->viewUrl($threadMailbox->getId(), $threadMessage->getId()),
			);
		}

		if ($items === []) {
			throw new ServiceException('No message of the thread could be loaded');
		}

		return $this->mapper->mapConversation(
			$headline,
			$items,
			(string)$id,
			$this->threadUrl($id, $format),
		);
	}

	/**
	 * @return array{string, Message, Mailbox, Account}
	 * @throws ClientException
	 */
	private function loadMessage(string $userId, int $id): array {
		try {
			$effectiveUserId = $this->delegationService->resolveMessageUserId($id, $userId);
			$message = $this->mailManager->getMessage($effectiveUserId, $id);
			$mailbox = $this->mailManager->getMailbox($effectiveUserId, $message->getMailboxId());
			$account = $this->accountService->find($effectiveUserId, $mailbox->getAccountId());
		} catch (ClientException|DoesNotExistException $e) {
			throw new ClientException('Message, account or mailbox not found', 0, $e);
		}

		return [$effectiveUserId, $message, $mailbox, $account];
	}

	/**
	 * @return array IMAPMessage::getFullMessage() output enriched with body and encodingFormat
	 * @throws ServiceException
	 */
	private function loadFullMessage(Account $account, Mailbox $mailbox, Message $message, string $format): array {
		try {
			$imapMessage = $this->mailManager->getImapMessage($account, $mailbox, $message, true);
		} catch (SmimeDecryptException $e) {
			throw new ServiceException('Message body could not be decrypted', 0, $e);
		}

		/** @var IMAPMessage $imapMessage */
		$fullMessage = $imapMessage->getFullMessage($message->getId(), false);

		if ($format === self::FORMAT_PLAIN) {
			$fullMessage['body'] = $imapMessage->getPlainBody();
			$fullMessage['encodingFormat'] = 'text/plain';
		} elseif ($imapMessage->hasHtmlMessage()) {
			$fullMessage['body'] = $imapMessage->getHtmlBody($message->getId());
			$fullMessage['encodingFormat'] = 'text/html';
		} else {
			$fullMessage['body'] = $imapMessage->getPlainBody();
			$fullMessage['encodingFormat'] = 'text/plain';
		}

		return $fullMessage;
	}

	private function messageUrl(int $id, string $format): string {
		return $this->jsonLdUrl('mail.jsonld.message', $id, $format);
	}

	private function threadUrl(int $id, string $format): string {
		return $this->jsonLdUrl('mail.jsonld.thread', $id, $format);
	}

	private function jsonLdUrl(string $route, int $id, string $format): string {
		$params = ['id' => $id];
		if ($format === self::FORMAT_PLAIN) {
			$params['format'] = 'plain';
		}
		return $this->urlGenerator->linkToRouteAbsolute($route, $params);
	}

	private function viewUrl(int $mailboxId, int $id): string {
		return $this->urlGenerator->linkToRouteAbsolute('mail.page.thread', ['mailboxId' => $mailboxId, 'id' => $id]);
	}
}
