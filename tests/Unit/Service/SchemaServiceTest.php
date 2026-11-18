<?php

declare(strict_types=1);

/**
 * @copyright 2024 Gerke Frölje <gerke@audriga.com>
 *
 * @author 2024 Gerke Frölje <gerke@audriga.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Mail\Tests\Service;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OC\Memcache\ArrayCache;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\IMAP\HordeImapClient;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MessageMapper;
use OCA\Mail\Service\SchemaService;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

class SchemaServiceTest extends TestCase {
	/** @var IMAPClientFactory|MockObject */
	private $imapClientFactory;

	/** @var MessageMapper|MockObject */
	private $messageMapper;

	/** @var IAppManager|MockObject */
	private $appManager;

	private ICache $cache;

	/** @var SchemaService */
	private $service;

	/** @var string */
	private $messageBody;

	protected function setUp(): void {
		parent::setUp();

		$this->imapClientFactory = $this->createMock(IMAPClientFactory::class);
		$this->messageMapper = $this->createMock(MessageMapper::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = new ArrayCache('schema_test');
		$this->appManager = $this->createMock(IAppManager::class);

		$cacheFactory->method('createLocal')
			->willReturn($this->cache);

		$this->service = new SchemaService(
			$this->imapClientFactory,
			$this->messageMapper,
			$cacheFactory,
			$this->appManager,
			new NullLogger(),
		);

		$this->messageBody = '<html><body>
			<script type="application/ld+json">
				{ "@context" : "https://schema.org",
					"@type" : "Organization",
				"url" : "http://www.your-company-site.com",
				"contactPoint" : [
					{ "@type" : "ContactPoint",
					"telephone" : "+1-401-555-1212",
					"contactType" : "customer service"
					} ] }
			</script>
		</body></html>';
	}

	private function getAccountAndMailbox(): array {
		$mailAccount = new MailAccount();
		$mailAccount->setId(100);
		$mailAccount->setUserId('1');
		$account = new Account($mailAccount);

		$mailbox = new Mailbox();
		$mailbox->setName('INBOX');

		return [$account, $mailbox];
	}

	public function testExtractEmpty() {
		[$account, $mailbox] = $this->getAccountAndMailbox();

		$client = $this->createMock(HordeImapClient::class);
		$this->imapClientFactory->expects($this->once())
			->method('getClient')
			->with($account)
			->willReturn($client);

		$body = '<html><body>hello</body></html>';

		$this->messageMapper->expects($this->once())
			->method('getHtmlBody')
			->with($client, 'INBOX', 13)
			->willReturn($body);

		$schema = $this->service->extract($account, $mailbox, 13);

		$this->assertEmpty($schema);
	}

	public function testExtractFromBody() {
		[$account, $mailbox] = $this->getAccountAndMailbox();

		$client = $this->createMock(HordeImapClient::class);
		$this->imapClientFactory->expects($this->once())
			->method('getClient')
			->with($account)
			->willReturn($client);

		$this->messageMapper->expects($this->once())
			->method('getHtmlBody')
			->with($client, 'INBOX', 13)
			->willReturn($this->messageBody);

		$schema = $this->service->extract($account, $mailbox, 13);

		$this->assertNotEmpty($schema);
	}

	public function testExtractFlagsRecipeWithMissingRequiredApp(): void {
		[$account, $mailbox] = $this->getAccountAndMailbox();
		$this->imapClientFactory->method('getClient')
			->willReturn($this->createMock(HordeImapClient::class));
		$this->messageMapper->method('getHtmlBody')
			->willReturn($this->recipeBody());
		$this->appManager->method('getInstalledApps')
			->willReturn([]);

		$schema = $this->service->extract($account, $mailbox, 13);

		$this->assertFalse($schema->isRequiredAppInstalled);
	}

	public function testExtractFlagsRecipeWithInstalledRequiredApp(): void {
		[$account, $mailbox] = $this->getAccountAndMailbox();
		$this->imapClientFactory->method('getClient')
			->willReturn($this->createMock(HordeImapClient::class));
		$this->messageMapper->method('getHtmlBody')
			->willReturn($this->recipeBody());
		$this->appManager->method('getInstalledApps')
			->willReturn(['cookbook']);

		$schema = $this->service->extract($account, $mailbox, 13);

		$this->assertTrue($schema->isRequiredAppInstalled);
	}

	public function testExtractKeepsRequiredAppFlagOnCacheHit(): void {
		[$account, $mailbox] = $this->getAccountAndMailbox();
		$this->imapClientFactory->expects($this->once())
			->method('getClient')
			->willReturn($this->createMock(HordeImapClient::class));
		$this->messageMapper->method('getHtmlBody')
			->willReturn($this->recipeBody());
		$this->appManager->method('getInstalledApps')
			->willReturn(['cookbook']);

		$this->service->extract($account, $mailbox, 13);
		$schema = $this->service->extract($account, $mailbox, 13);

		$this->assertTrue($schema->isRequiredAppInstalled);
	}

	public function testExtractFlagsOnlyRecipeEntriesOfArraySchema(): void {
		[$account, $mailbox] = $this->getAccountAndMailbox();
		$this->imapClientFactory->method('getClient')
			->willReturn($this->createMock(HordeImapClient::class));
		$this->messageMapper->method('getHtmlBody')
			->willReturn('<html><body>'
				. '<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","name":"News"}</script>'
				. '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Recipe","name":"Pancakes"}</script>'
				. '</body></html>');
		$this->appManager->method('getInstalledApps')
			->willReturn([]);

		$schema = $this->service->extract($account, $mailbox, 13);

		$this->assertIsArray($schema);
		$this->assertCount(2, $schema);
		$this->assertFalse(isset($schema[0]->isRequiredAppInstalled));
		$this->assertFalse($schema[1]->isRequiredAppInstalled);
	}

	public function testGetCachedMiss(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(100);
		$mailAccount->setUserId('1');
		$account = new Account($mailAccount);

		$mailbox = new Mailbox();
		$mailbox->setName('FooBar');

		$result = $this->service->getCached(
			$account,
			$mailbox,
			2
		);

		$this->assertNull($result);
	}

	public function testGetCached(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(100);
		$mailAccount->setUserId('1');
		$account = new Account($mailAccount);

		$mailbox = new Mailbox();
		$mailbox->setName('FooBar');

		$this->cache->set('100_FooBar_1', '{"@type":"test"}');

		$result = $this->service->getCached(
			$account,
			$mailbox,
			1
		);

		$this->assertEquals(1, sizeof(json_decode(json_encode($result), true)));
	}

	private function recipeBody(): string {
		return '<html><body><script type="application/ld+json">'
			. '{"@context":"https://schema.org","@type":"Recipe","name":"Pancakes"}'
			. '</script></body></html>';
	}
}
