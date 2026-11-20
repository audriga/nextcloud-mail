<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\JsonLd;

use DateTimeImmutable;
use DateTimeInterface;

class EmailMessageMapper {
	public const CONTEXT = 'https://schema.org/';

	/**
	 * @param array $message IMAPMessage::getFullMessage() output with body & encodingFormat populated
	 */
	public function mapMessage(array $message, string $id, string $refreshUrl, string $viewUrl): array {
		return ['@context' => self::CONTEXT] + $this->emailMessage($message, $id, $viewUrl, $refreshUrl);
	}

	/**
	 * @param list<array> $messages mapped messages
	 */
	public function mapConversation(string $headline, array $messages, string $id, string $refreshUrl): array {
		return [
			'@context' => self::CONTEXT,
			'@type' => 'Conversation',
			'id' => $id,
			'headline' => $headline,
			'numberOfItems' => count($messages),
			'itemListElement' => array_map(static fn (array $message): array => array_diff_key($message, ['@context' => true]), $messages),
			'potentialAction' => [
				['@type' => 'RefreshAction', 'target' => $refreshUrl, 'authentication' => 'basic'],
			],
		];
	}

	public function emailMessageItem(array $message, string $id, string $viewUrl): array {
		return $this->emailMessage($message, $id, $viewUrl, null);
	}

	private function emailMessage(array $message, string $id, string $viewUrl, ?string $refreshUrl): array {
		$actions = [
			['@type' => 'ViewAction', 'target' => $viewUrl],
		];
		if ($refreshUrl !== null) {
			$actions[] = ['@type' => 'RefreshAction', 'target' => $refreshUrl, 'authentication' => 'basic'];
		}

		return [
			'@type' => 'EmailMessage',
			'id' => $id,
			'identifier' => $message['messageId'],
			'headline' => $message['subject'],
			'description' => $message['body'] ?? '',
			'encodingFormat' => $message['encodingFormat'] ?? 'text/plain',
			'createdBy' => $message['from'] === [] ? null : $message['from'][0]['email'],
			'toRecipient' => $this->persons($message['to'] ?? []),
			'ccRecipient' => $this->persons($message['cc'] ?? []),
			'bccRecipient' => $this->persons($message['bcc'] ?? []),
			'dateCreated' => (new DateTimeImmutable('@' . $message['dateInt']))->format(DateTimeInterface::ATOM),
			'potentialAction' => $actions,
		];
	}

	private function persons(array $addresses): array {
		return array_map($this->person(...), $addresses);
	}

	private function person(array $address): array {
		return [
			'@type' => 'Person',
			'name' => ($address['label'] ?? '') !== '' ? $address['label'] : ($address['email'] ?? ''),
			'email' => $address['email'] ?? '',
		];
	}
}
