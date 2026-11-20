<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\JsonLd;

use OCP\AppFramework\Http;

class JsonLdErrorMapper {
	public function unauthorized(string $target): array {
		return [
			'@context' => EmailMessageMapper::CONTEXT,
			'@type' => 'LDAActionResponse',
			'status' => 'error',
			'description' => 'Authentication required',
			'potentialAction' => [
				'@type' => 'LoginAction',
				'type' => 'basic',
				'target' => $target,
			],
		];
	}

	public function error(int $status, string $description, string $requestPath): array {
		return [
			'@context' => EmailMessageMapper::CONTEXT,
			'@type' => 'ErrorAction',
			'name' => $this->reasonPhrase($status),
			'description' => $description,
			'requestPath' => $requestPath,
		];
	}

	private function reasonPhrase(int $status): string {
		return match ($status) {
			Http::STATUS_NOT_FOUND => 'Not Found',
			Http::STATUS_INTERNAL_SERVER_ERROR => 'Internal Server Error',
			default => 'Error',
		};
	}
}
