<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2015-2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Mail\Tests\Unit\Controller;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Contracts\IUserPreferences;
use OCA\Mail\Controller\PageController;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\TagMapper;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Http\JsonResponse;
use OCA\Mail\Http\Middleware\ErrorMiddleware;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\AiIntegrations\AiIntegrationsService;
use OCA\Mail\Service\AliasesService;
use OCA\Mail\Service\Classification\ClassificationSettingsService;
use OCA\Mail\Service\ContextChat\ContextChatSettingsService;
use OCA\Mail\Service\InternalAddressService;
use OCA\Mail\Service\MailManager;
use OCA\Mail\Service\OutboxService;
use OCA\Mail\Service\QuickActionsService;
use OCA\Mail\Service\SmimeService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Authentication\LoginCredentials\ICredentials;
use OCP\Authentication\LoginCredentials\IStore as ICredentialStore;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\User\IAvailabilityCoordinator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use function array_filter;
use function array_values;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_replace;
use function str_repeat;
use function urlencode;

class PageControllerTest extends TestCase {
	public function testComposeRoutesSharePathWithDistinctHttpMethods(): void {
		$routeConfig = require __DIR__ . '/../../../appinfo/routes.php';
		$composeRoutes = array_values(array_filter($routeConfig['routes'],
			static fn (array $route): bool => $route['url'] === '/compose'));

		$this->assertSame([
			['name' => 'page#compose', 'url' => '/compose', 'verb' => 'GET'],
			['name' => 'page#composeJsonLd', 'url' => '/compose', 'verb' => 'POST'],
		], $composeRoutes);
	}

	/** @var string */
	private $appName;

	/** @var IRequest|MockObject */
	private $request;

	/** @var string */
	private $userId;

	/** @var IURLGenerator|MockObject */
	private $urlGenerator;

	/** @var IConfig|MockObject */
	private $config;

	/** @var AccountService|MockObject */
	private $accountService;

	/** @var AiIntegrationsService|MockObject */
	private $aiIntegrationsService;

	/** @var AliasesService|MockObject */
	private $aliasesService;

	/** @var IUserSession|MockObject */
	private $userSession;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var IUserPreferences|MockObject */
	private $preferences;

	/** @var MailManager|MockObject */
	private $mailManager;

	/** @var TagMapper|MockObject */
	private $tagMapper;

	/** @var IInitialState|MockObject */
	private $initialState;

	/** @var LoggerInterface|MockObject */
	private $logger;

	/** @var OutboxService|MockObject */
	private $outboxService;

	/** @var IEventDispatcher|MockObject */
	private $eventDispatcher;

	/** @var ICredentialStore|MockObject */
	private $credentialStore;

	/** @var PageController */
	private $controller;

	private SmimeService $smimeService;

	/** @var InternalAddressService|MockObject */
	private $internalAddressService;

	private QuickActionsService|MockObject $quickActionsService;

	private IAvailabilityCoordinator&MockObject $availabilityCoordinator;
	private IAppManager $appManager;

	private ContextChatSettingsService $contextChatSettingsService;

	private ClassificationSettingsService|MockObject $classificationSettingsService;
	private array $providedInitialStates = [];
	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->userId = 'jane';
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->config = $this->createMock(IConfig::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->aiIntegrationsService = $this->createMock(AiIntegrationsService::class);
		$this->aliasesService = $this->createMock(AliasesService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->preferences = $this->createMock(IUserPreferences::class);
		$this->mailManager = $this->createMock(MailManager::class);
		$this->tagMapper = $this->createMock(TagMapper::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->outboxService = $this->createMock(OutboxService::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->credentialStore = $this->createMock(ICredentialStore::class);
		$this->smimeService = $this->createMock(SmimeService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->internalAddressService = $this->createMock(InternalAddressService::class);
		$this->availabilityCoordinator = $this->createMock(IAvailabilityCoordinator::class);
		$this->quickActionsService = $this->createMock(QuickActionsService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppVersion')->willReturn('0.0.1-dev.0');
		$this->contextChatSettingsService = $this->createMock(ContextChatSettingsService::class);
		$this->contextChatSettingsService->method('isIndexingEnabled')->willReturn(true);

		$this->classificationSettingsService = $this->createMock(ClassificationSettingsService::class);
		$this->controller = new PageController(
			$this->appName,
			$this->request,
			$this->urlGenerator,
			$this->config,
			$this->accountService,
			$this->aliasesService,
			$this->userId,
			$this->userSession,
			$this->preferences,
			$this->mailManager,
			$this->tagMapper,
			$this->initialState,
			$this->logger,
			$this->outboxService,
			$this->eventDispatcher,
			$this->credentialStore,
			$this->smimeService,
			$this->aiIntegrationsService,
			$this->userManager,
			$this->internalAddressService,
			$this->availabilityCoordinator,
			$this->quickActionsService,
			$this->appManager,
			$this->contextChatSettingsService,
			$this->classificationSettingsService
		);
	}

	public function testIndex(): void {
		$account1 = $this->createMock(Account::class);
		$account2 = $this->createMock(Account::class);
		$mailbox = $this->createStub(Mailbox::class);
		$this->preferences->expects($this->exactly(14))
			->method('getPreference')
			->willReturnMap([
				[$this->userId, 'account-settings', '[]', json_encode([])],
				[$this->userId, 'sort-order', 'newest', 'newest'],
				[$this->userId, 'external-avatars', 'true', 'true'],
				[$this->userId, 'reply-mode', 'top', 'bottom'],
				[$this->userId, 'collect-data', 'true', 'true'],
				[$this->userId, 'search-priority-body', 'false', 'false'],
				[$this->userId, 'start-mailbox-id', null, '123'],
				[$this->userId, 'layout-mode', 'vertical-split', 'vertical-split'],
				[$this->userId, 'layout-message-view', 'threaded', 'threaded'],
				[$this->userId, 'follow-up-reminders', 'true', 'true'],
				[$this->userId, 'internal-addresses', 'false', 'false'],
				[$this->userId, 'smime-sign-aliases', '[]', '[]'],
				[$this->userId, 'sort-favorites', 'false', 'false'],
				[$this->userId, 'compact-mode', 'false', 'false'],
			]);
		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with($this->userId)
			->will($this->returnValue([
				$account1,
				$account2,
			]));
		$this->accountService->expects($this->once())
			->method('findDelegatedAccounts')
			->with($this->userId)
			->willReturn([]);
		$this->mailManager->expects($this->exactly(2))
			->method('getMailboxes')
			->withConsecutive(
				[$account1],
				[$account2]
			)
			->willReturnOnConsecutiveCalls(
				[$mailbox],
				[]
			);
		$account1->expects($this->once())
			->method('jsonSerialize')
			->will($this->returnValue([
				'accountId' => 1,
			]));
		$account1->expects($this->once())
			->method('getId')
			->will($this->returnValue(1));
		$account2->expects($this->once())
			->method('jsonSerialize')
			->will($this->returnValue([
				'accountId' => 2,
			]));
		$account2->expects($this->once())
			->method('getId')
			->will($this->returnValue(2));
		$this->aliasesService->expects($this->exactly(2))
			->method('findAll')
			->will($this->returnValueMap([
				[1, $this->userId, ['a11', 'a12']],
				[2, $this->userId, ['a21', 'a22']],
			]));
		$accountsJson = [
			[
				'accountId' => 1,
				'aliases' => [
					'a11',
					'a12',
				],
				'mailboxes' => [
					$mailbox,
				],
				'isDelegated' => false,
			],
			[
				'accountId' => 2,
				'aliases' => [
					'a21',
					'a22',
				],
				'mailboxes' => [],
				'isDelegated' => false,
			],
		];

		$user = $this->createMock(IUser::class);
		$this->userSession->expects($this->once())
			->method('getUser')
			->will($this->returnValue($user));
		$this->config
			->method('getSystemValue')
			->willReturnMap([
				['debug', false, true],
				['version', '0.0.0', '26.0.0'],
				['app.mail.attachment-size-limit', 0, 123],
			]);
		$this->config->expects($this->exactly(7))
			->method('getAppValue')
			->withConsecutive(
				[ 'mail', 'installed_version' ],
				['mail', 'layout_message_view' ],
				['mail', 'google_oauth_client_id' ],
				['mail', 'microsoft_oauth_client_id' ],
				['mail', 'microsoft_oauth_tenant_id' ],
				['core', 'backgroundjobs_mode', 'ajax' ],
				['mail', 'allow_new_mail_accounts', 'yes'],
			)->willReturnOnConsecutiveCalls(
				$this->returnValue('1.2.3'),
				$this->returnValue('threaded'),
				$this->returnValue(''),
				$this->returnValue(''),
				$this->returnValue(''),
				$this->returnValue('cron'),
				$this->returnValue('yes'),
				$this->returnValue('no')
			);
		$this->aiIntegrationsService->expects(self::exactly(4))
			->method('isLlmProcessingEnabled')
			->willReturn(false);

		$user->method('getUID')
			->will($this->returnValue('jane'));
		$this->userManager->expects($this->once())
			->method('getDisplayName')
			->with($this->equalTo('jane'))
			->will($this->returnValue('Jane Doe'));
		$this->config->expects($this->once())
			->method('getUserValue')
			->with($this->equalTo('jane'), $this->equalTo('settings'),
				$this->equalTo('email'), $this->equalTo(''))
			->will($this->returnValue('jane@doe.cz'));

		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$loginCredentials = $this->createMock(ICredentials::class);
		$loginCredentials->expects($this->once())
			->method('getPassword')
			->willReturn(null);
		$this->credentialStore->expects($this->once())
			->method('getLoginCredentials')
			->willReturn($loginCredentials);

		$this->availabilityCoordinator->expects(self::once())
			->method('isEnabled')
			->willReturn(true);

		$this->quickActionsService->expects(self::once())
			->method('findAll')
			->with($this->userId)
			->willReturn([]);
		$this->classificationSettingsService->expects(($this->once()))
			->method(('isClassificationEnabledByDefault'))
			->willReturn(true);
		$this->initialState->expects($this->exactly(27))
			->method('provideInitialState')
			->withConsecutive(
				['debug', true],
				['ncVersion', '26.0.0'],
				['mailVersion', '0.0.1-dev.0'],
				['accounts', $accountsJson],
				['account-settings', []],
				['tags', []],
				['internal-addresses-list', []],
				['internal-addresses', false],
				['smime-sign-aliases',[]],
				['sort-order', 'newest'],
				['password-is-unavailable', true],
				['preferences', [
					'attachment-size-limit' => 123,
					'external-avatars' => 'true',
					'reply-mode' => 'bottom',
					'app-version' => '1.2.3',
					'collect-data' => 'true',
					'start-mailbox-id' => '123',
					'search-priority-body' => 'false',
					'layout-mode' => 'vertical-split',
					'layout-message-view' => 'threaded',
					'follow-up-reminders' => 'true',
					'sort-favorites' => 'false',
					'index-context-chat' => 'true',
					'compact-mode' => 'false'
				]],
				['prefill_displayName', 'Jane Doe'],
				['importance_classification_default', true],
				['prefill_email', 'jane@doe.cz'],
				['outbox-messages', []],
				['quick-actions', []],
				['disable-scheduled-send', false],
				['disable-snooze', false],
				['allow-new-accounts', true],
				['llm_summaries_available', false],
				['llm_translation_enabled', false],
				['llm_freeprompt_available', false],
				['llm_followup_available', false],
				['context_chat_available', true],
				['smime-certificates', []],
				['enable-system-out-of-office', true],
			);

		$expected = new TemplateResponse($this->appName, 'index');
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain('\'self\'');
		$expected->setContentSecurityPolicy($csp);

		$response = $this->controller->index();

		$this->assertEquals($expected, $response);
	}

	public function testComposeSimple() {
		$address = 'user@example.com';
		$uri = "mailto:$address";

		$expected = new RedirectResponse('?to=' . urlencode($address));

		$response = $this->controller->compose($uri);

		$this->assertEquals($expected, $response);
	}

	public function testComposeWithSubject() {
		$address = 'user@example.com';
		$subject = 'hello there';
		$uri = "mailto:$address?subject=$subject";

		$expected = new RedirectResponse('?to=' . urlencode($address)
			. '&subject=' . urlencode($subject));

		$response = $this->controller->compose($uri);

		$this->assertEquals($expected, $response);
	}

	public function testComposeWithCc() {
		$address = 'user@example.com';
		$cc = 'other@example.com';
		$uri = "mailto:$address?cc=$cc";

		$expected = new RedirectResponse('?to=' . urlencode($address)
			. '&cc=' . urlencode($cc));

		$response = $this->controller->compose($uri);

		$this->assertEquals($expected, $response);
	}

	public function testComposeBcc() {
		$bcc = 'blind@example.com';
		$uri = "mailto:?bcc=$bcc";

		$expected = new RedirectResponse('?bcc=' . urlencode($bcc));

		$response = $this->controller->compose($uri);

		$this->assertEquals($expected, $response);
	}

	public function testComposeWithBcc() {
		$address = 'user@example.com';
		$bcc = 'blind@example.com';
		$uri = "mailto:$address?bcc=$bcc";

		$expected = new RedirectResponse('?to=' . urlencode($address)
			. '&bcc=' . urlencode($bcc));

		$response = $this->controller->compose($uri);

		$this->assertEquals($expected, $response);
	}

	public function testComposeWithMultilineBody() {
		$address = 'user@example.com';
		$body = 'Hi!\nWhat\'s up?\nAnother line';
		$uri = "mailto:$address?body=$body";

		$expected = new RedirectResponse('?to=' . urlencode($address)
			. '&body=' . urlencode($body));

		$response = $this->controller->compose($uri);

		$this->assertEquals($expected, $response);
	}

	public function testComposeJsonLdRendersHtmlWithOwnedAccountAndPreservesPayload(): void {
		$account1 = $this->createStub(Account::class);
		$account1->method('getId')->willReturn(12);
		$account1->method('jsonSerialize')->willReturn(['accountId' => 12]);
		$account2 = $this->createStub(Account::class);
		$account2->method('getId')->willReturn(27);
		$account2->method('jsonSerialize')->willReturn(['accountId' => 27]);
		$this->configureComposePageResponse([$account1, $account2]);
		$jsonld = json_encode([
			'@type' => 'Recipe',
			'name' => 'Soupe & crème "été"',
			'description' => "line 1\nline 2 <img src=x onerror=alert(1)> 🍲",
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$response = $this->controller->composeJsonLd($jsonld, '27');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(27, $this->providedInitialStates['compose-data']['accountId']);
		$this->assertSame([], $this->providedInitialStates['compose-data']['to']);
		$this->assertSame([], $this->providedInitialStates['compose-data']['cc']);
		$this->assertSame([], $this->providedInitialStates['compose-data']['bcc']);
		$this->assertSame('', $this->providedInitialStates['compose-data']['subject']);
		$this->assertSame([], $this->providedInitialStates['compose-data']['attachments']);
		$this->assertArrayNotHasKey('id', $this->providedInitialStates['compose-data']);
		$this->assertArrayNotHasKey('draftId', $this->providedInitialStates['compose-data']);

		$body = $this->providedInitialStates['compose-data']['bodyHtml'];
		$this->assertStringContainsString('<table', $body);
		$this->assertStringContainsString($jsonld, $body);
		preg_match('~<script type="application/ld\\+json">(.*?)</script>~s', $body, $matches);
		$this->assertCount(2, $matches);
		$this->assertSame(json_decode($jsonld, true), json_decode($matches[1], true));
		$renderedCard = preg_replace('~<script type="application/ld\\+json">.*?</script>~s', '', $body);
		$this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $renderedCard);
	}

	public function testComposeJsonLdEmbedsOriginalJsonWithoutEscaping(): void {
		$account = $this->createStub(Account::class);
		$account->method('getId')->willReturn(12);
		$account->method('jsonSerialize')->willReturn(['accountId' => 12]);
		$this->configureComposePageResponse([$account]);
		$jsonld = '{"@type":"Recipe","description":"<!--<script>example </script>"}';

		$this->controller->composeJsonLd($jsonld);

		$this->assertStringStartsWith('<div><script type="application/ld+json">' . $jsonld . '</script></div>', $this->providedInitialStates['compose-data']['bodyHtml']);
	}

	public function testComposeJsonLdDefaultsToFirstOwnedAccount(): void {
		$account1 = $this->createStub(Account::class);
		$account1->method('getId')->willReturn(12);
		$account1->method('jsonSerialize')->willReturn(['accountId' => 12]);
		$account2 = $this->createStub(Account::class);
		$account2->method('getId')->willReturn(27);
		$account2->method('jsonSerialize')->willReturn(['accountId' => 27]);
		$this->configureComposePageResponse([$account1, $account2]);

		$response = $this->controller->composeJsonLd('{"@type":"Recipe","name":"Bread"}');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(12, $this->providedInitialStates['compose-data']['accountId']);
	}

	public function testComposeJsonLdKeepsLargePayloadInRequestLocalPageState(): void {
		$account = $this->createStub(Account::class);
		$account->method('getId')->willReturn(12);
		$account->method('jsonSerialize')->willReturn(['accountId' => 12]);
		$this->configureComposePageResponse([$account]);
		$description = str_repeat('🍲 & crème ', 1500);
		$jsonld = json_encode(['@type' => 'Recipe', 'description' => $description], JSON_UNESCAPED_UNICODE);

		$response = $this->controller->composeJsonLd($jsonld);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertGreaterThan(8192, strlen($jsonld));
		$this->assertArrayHasKey('compose-data', $this->providedInitialStates);
		$this->assertStringContainsString($description, $this->providedInitialStates['compose-data']['bodyHtml']);
	}

	public function testComposeJsonLdRejectsInvalidPayloads(): void {
		foreach ([null, [], '', '{', "\xB1", 'null', '"text"', '12', 'true', '[]', '[{"@type":"Recipe"}]', '{}'] as $jsonld) {
			$response = $this->composeJsonLdResponse($jsonld);

			$this->assertInstanceOf(JsonResponse::class, $response);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame('fail', $response->getData()['status']);
			$this->assertSame(ClientException::class, $response->getData()['data']['type']);
		}
	}

	public function testComposeJsonLdRejectsMissingAndUnauthorizedAccounts(): void {
		$accounts = [];
		$this->accountService->method('findByUserId')->willReturnCallback(static function () use (&$accounts): array {
			return $accounts;
		});
		$noAccountResponse = $this->composeJsonLdResponse('{"@type":"Recipe"}');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $noAccountResponse->getStatus());
		$this->assertInstanceOf(JsonResponse::class, $noAccountResponse);
		$this->assertSame('No Mail account is configured for this user.', $noAccountResponse->getData()['data']['message']);

		$ownedAccount = $this->createStub(Account::class);
		$ownedAccount->method('getId')->willReturn(12);
		$accounts = [$ownedAccount];
		$unauthorizedResponse = $this->controller->composeJsonLd('{"@type":"Recipe"}', '999');
		$this->assertSame(Http::STATUS_FORBIDDEN, $unauthorizedResponse->getStatus());
		$this->assertInstanceOf(JsonResponse::class, $unauthorizedResponse);
		$this->assertSame(['status' => 'fail', 'data' => []], $unauthorizedResponse->getData());
		$invalidAccountResponse = $this->composeJsonLdResponse('{"@type":"Recipe"}', '0');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $invalidAccountResponse->getStatus());
		$this->assertInstanceOf(JsonResponse::class, $invalidAccountResponse);
		$arrayAccountResponse = $this->composeJsonLdResponse('{"@type":"Recipe"}', []);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $arrayAccountResponse->getStatus());
		$this->assertInstanceOf(JsonResponse::class, $arrayAccountResponse);
	}

	private function composeJsonLdResponse(mixed $jsonld, mixed $accountId = null): Response {
		try {
			return $this->controller->composeJsonLd($jsonld, $accountId);
		} catch (ClientException $exception) {
			$middleware = new ErrorMiddleware($this->config, $this->logger);
			return $middleware->afterException($this->controller, 'composeJsonLd', $exception);
		}
	}

	private function configureComposePageResponse(array $accounts): void {
		$this->providedInitialStates = [];
		$this->initialState->method('provideInitialState')->willReturnCallback(function (string $name, mixed $value): void {
			$this->providedInitialStates[$name] = $value;
		});
		$this->accountService->method('findByUserId')->willReturn($accounts);
		$this->accountService->method('findDelegatedAccounts')->willReturn([]);
		$this->aliasesService->method('findAll')->willReturn([]);
		$this->mailManager->method('getMailboxes')->willReturn([]);
		$this->preferences->method('getPreference')->willReturnCallback(static fn (...$args) => $args[count($args) - 1] ?? null);
		$this->config->method('getSystemValue')->willReturnCallback(static fn (...$args) => $args[count($args) - 1] ?? null);
		$this->config->method('getAppValue')->willReturnCallback(static fn (...$args) => $args[count($args) - 1] ?? null);
		$this->config->method('getUserValue')->willReturn('');
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($this->userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('getDisplayName')->willReturn('Jane Doe');
		$credentials = $this->createStub(ICredentials::class);
		$credentials->method('getPassword')->willReturn(null);
		$this->credentialStore->method('getLoginCredentials')->willReturn($credentials);
		$this->tagMapper->method('getAllTagsForUser')->willReturn([]);
		$this->internalAddressService->method('getInternalAddresses')->willReturn([]);
		$this->smimeService->method('findAllCertificates')->willReturn([]);
		$this->availabilityCoordinator->method('isEnabled')->willReturn(false);
		$this->quickActionsService->method('findAll')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturn('0.0.1-dev.0');
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->contextChatSettingsService->method('isIndexingEnabled')->willReturn(false);
		$this->aiIntegrationsService->method('isLlmProcessingEnabled')->willReturn(false);
		$this->classificationSettingsService->method('isClassificationEnabledByDefault')->willReturn(false);
	}
}
