<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Controller;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Controller\JsonLdController;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\JsonLd\JsonLdErrorMapper;
use OCA\Mail\Service\JsonLd\JsonLdService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class JsonLdControllerTest extends TestCase {
	private const MESSAGE_URL = 'https://cloud.example.com/apps/mail/lda/message/42';
	private const MESSAGE_PLAIN_URL = 'https://cloud.example.com/apps/mail/lda/message/42?format=plain';
	private const THREAD_URL = 'https://cloud.example.com/apps/mail/lda/thread/42';

	private JsonLdService&MockObject $jsonLdService;
	private IRequest&MockObject $request;
	private IURLGenerator&MockObject $urlGenerator;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->jsonLdService = $this->createMock(JsonLdService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->request->method('getPathInfo')->willReturn('/apps/mail/lda/message/42');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => match ($route) {
				'mail.jsonld.message' => 'https://cloud.example.com/apps/mail/lda/message/' . $parameters['id'] . (isset($parameters['format']) ? '?format=' . $parameters['format'] : ''),
				'mail.jsonld.thread' => 'https://cloud.example.com/apps/mail/lda/thread/' . $parameters['id'] . (isset($parameters['format']) ? '?format=' . $parameters['format'] : ''),
			},
		);
	}

	private function controller(?string $userId): JsonLdController {
		return new JsonLdController(
			'mail',
			$this->request,
			$userId,
			$this->jsonLdService,
			new JsonLdErrorMapper(),
			$this->urlGenerator,
			$this->logger,
		);
	}

	public function testMessageReturnsJson(): void {
		$this->jsonLdService->expects($this->once())
			->method('getMessage')
			->with('alice', 42, 'html')
			->willReturn(['@type' => 'EmailMessage']);

		$response = $this->controller('alice')->message(42);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['@type' => 'EmailMessage'], $response->getData());
		$this->assertSame('application/json; charset=utf-8', $response->getHeaders()['Content-Type']);
	}

	public function testMessagePlainFormat(): void {
		$this->jsonLdService->expects($this->once())
			->method('getMessage')
			->with('alice', 42, 'plain')
			->willReturn(['@type' => 'EmailMessage', 'encodingFormat' => 'text/plain']);

		$response = $this->controller('alice')->message(42, 'plain');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('text/plain', $response->getData()['encodingFormat']);
	}

	public function testMessageWithoutUserReturnsLoginAction(): void {
		$this->jsonLdService->expects($this->never())->method('getMessage');

		$response = $this->controller(null)->message(42);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('LDAActionResponse', $response->getData()['@type']);
		$this->assertSame(
			['@type' => 'LoginAction', 'type' => 'basic', 'target' => self::MESSAGE_URL],
			$response->getData()['potentialAction'],
		);
	}

	public function testMessageWithoutUserWithPlainFormatReturnsLoginActionWithFormatParam(): void {
		$this->jsonLdService->expects($this->never())->method('getMessage');

		$response = $this->controller(null)->message(42, 'plain');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(
			['@type' => 'LoginAction', 'type' => 'basic', 'target' => self::MESSAGE_PLAIN_URL],
			$response->getData()['potentialAction'],
		);
	}

	public function testMessageNotFoundReturnsErrorAction(): void {
		$this->jsonLdService->method('getMessage')->willThrowException(new ClientException('gone'));

		$response = $this->controller('alice')->message(42);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('ErrorAction', $response->getData()['@type']);
		$this->assertSame('/apps/mail/lda/message/42', $response->getData()['requestPath']);
	}

	public function testMessageServiceFailureReturnsInternalServerError(): void {
		$this->jsonLdService->method('getMessage')->willThrowException(new ServiceException('imap down'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller('alice')->message(42);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Internal Server Error', $response->getData()['name']);
	}

	public function testThreadPassesFormat(): void {
		$this->jsonLdService->expects($this->once())
			->method('getThread')
			->with('alice', 1, 'plain')
			->willReturn(['@type' => 'Conversation']);

		$this->controller('alice')->thread(1, 'plain');
	}

	public function testThreadDefaultsFormatToHtml(): void {
		$this->jsonLdService->expects($this->once())
			->method('getThread')
			->with('alice', 1, 'html')
			->willReturn(['@type' => 'Conversation']);

		$this->controller('alice')->thread(1);
	}
}
