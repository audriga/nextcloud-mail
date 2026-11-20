<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\JsonLd;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\JsonLd\EmailMessageMapper;
use OCA\Mail\Service\JsonLd\JsonLdErrorMapper;
use OCP\AppFramework\Http;

class JsonLdErrorMapperTest extends TestCase {
	private JsonLdErrorMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new JsonLdErrorMapper();
	}

	public function testUnauthorized(): void {
		$target = 'https://cloud.example.com/apps/mail/lda/message/42';

		$result = $this->mapper->unauthorized($target);

		$this->assertSame([
			'@context' => EmailMessageMapper::CONTEXT,
			'@type' => 'LDAActionResponse',
			'status' => 'error',
			'description' => 'Authentication required',
			'potentialAction' => [
				'@type' => 'LoginAction',
				'type' => 'basic',
				'target' => $target,
			],
		], $result);
	}

	public function testErrorNotFound(): void {
		$result = $this->mapper->error(Http::STATUS_NOT_FOUND, 'Message not found', '/apps/mail/lda/message/42');

		$this->assertSame([
			'@context' => EmailMessageMapper::CONTEXT,
			'@type' => 'ErrorAction',
			'name' => 'Not Found',
			'description' => 'Message not found',
			'requestPath' => '/apps/mail/lda/message/42',
		], $result);
	}

	public function testErrorInternalServerError(): void {
		$result = $this->mapper->error(Http::STATUS_INTERNAL_SERVER_ERROR, 'Could not load message', '/apps/mail/lda/thread/42');

		$this->assertSame('Internal Server Error', $result['name']);
	}
}
