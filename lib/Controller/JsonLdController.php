<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Controller;

use Closure;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\JsonLd\JsonLdErrorMapper;
use OCA\Mail\Service\JsonLd\JsonLdService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class JsonLdController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private JsonLdService $jsonLdService,
		private JsonLdErrorMapper $errorMapper,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get a mail message as schema.org EmailMessage
	 *
	 * @param int $id the mail message id
	 * @param string $format 'html' (default) or 'plain'
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function message(int $id, string $format = JsonLdService::FORMAT_HTML): JSONResponse {
		$targetParams = ['id' => $id];
		if ($format === JsonLdService::FORMAT_PLAIN) {
			$targetParams['format'] = 'plain';
		}
		$target = $this->urlGenerator->linkToRouteAbsolute('mail.jsonld.message', $targetParams);

		return $this->respond($target, fn (string $userId): JSONResponse => new JSONResponse($this->jsonLdService->getMessage($userId, $id, $format)));
	}

	/**
	 * Get the thread of a mail message as schema.org Conversation
	 *
	 * @param int $id the mail message id
	 * @param string $format 'html' (default) or 'plain'
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function thread(int $id, string $format = JsonLdService::FORMAT_HTML): JSONResponse {
		$targetParams = ['id' => $id];
		if ($format === JsonLdService::FORMAT_PLAIN) {
			$targetParams['format'] = 'plain';
		}
		$target = $this->urlGenerator->linkToRouteAbsolute('mail.jsonld.thread', $targetParams);

		return $this->respond($target, fn (string $userId): JSONResponse => new JSONResponse($this->jsonLdService->getThread($userId, $id, $format)));
	}

	/**
	 * @param Closure(string): JSONResponse $load
	 */
	private function respond(string $target, Closure $load): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse($this->errorMapper->unauthorized($target), Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $load($this->userId);
		} catch (ClientException $e) {
			$this->logger->info('Mail for JSON-LD not found', ['exception' => $e]);
			return $this->errorResponse(Http::STATUS_NOT_FOUND, 'Message, account or mailbox not found');
		} catch (ServiceException $e) {
			$this->logger->error('Could not load mail for JSON-LD', ['exception' => $e]);
			return $this->errorResponse(Http::STATUS_INTERNAL_SERVER_ERROR, 'Could not load message from IMAP');
		}
	}

	private function errorResponse(int $status, string $description): JSONResponse {
		return new JSONResponse(
			$this->errorMapper->error($status, $description, (string)$this->request->getPathInfo()),
			$status,
		);
	}
}
