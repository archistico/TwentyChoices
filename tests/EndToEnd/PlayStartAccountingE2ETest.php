<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Game\Application\OpenRound;
use App\Player\Http\PlayerCookieFactory;
use App\Tests\Support\TransactionalWebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class PlayStartAccountingE2ETest extends TransactionalWebTestCase
{
    public function testHomePreissuesHashedAnonymousCookieAndRepeatedStartDoesNotChargeTwice(): void
    {
        $client = $this->createTransactionalClient();
        $connection = $this->testConnection();
        self::getContainer()->get(OpenRound::class)->open();
        $connection->executeStatement("DELETE FROM request_rate_limit WHERE scope IN ('play_start_ip', 'play_view_ip')");

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $playerCookie = $this->findResponseCookie($client->getResponse()->headers->getCookies(), PlayerCookieFactory::NAME);
        self::assertNotNull($playerCookie);
        self::assertTrue($playerCookie->isHttpOnly());
        self::assertFalse($playerCookie->isSecure());
        self::assertSame('/', $playerCookie->getPath());
        self::assertSame(Cookie::SAMESITE_LAX, $playerCookie->getSameSite());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $playerCookie->getValue());

        $sessionRows = $connection->fetchAllAssociative('SELECT id, public_token_hash FROM player_session');
        self::assertCount(1, $sessionRows);
        self::assertSame(hash('sha256', $playerCookie->getValue()), $sessionRows[0]['public_token_hash']);
        self::assertNotSame($playerCookie->getValue(), $sessionRows[0]['public_token_hash']);

        $csrf = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $form = $crawler->selectButton('Inizia una giocata gratuita')->form();
        $client->submit($form);
        self::assertTrue($client->getResponse()->isRedirect());
        $firstLocation = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/gioca/', $firstLocation);

        $firstCounts = [
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'ledger' => (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')"),
            'contribution' => (int) $connection->fetchOne("SELECT entry_contribution_cents FROM game_round WHERE status = 'ACTIVE'"),
        ];
        self::assertSame(['plays' => 1, 'ledger' => 3, 'contribution' => 80], $firstCounts);

        $client->request('POST', '/gioca/inizia', ['_token' => $csrf]);
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame($firstLocation, (string) $client->getResponse()->headers->get('Location'));
        self::assertSame($firstCounts['plays'], (int) $connection->fetchOne('SELECT COUNT(*) FROM play'));
        self::assertSame($firstCounts['ledger'], (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')"));
        self::assertSame($firstCounts['contribution'], (int) $connection->fetchOne("SELECT entry_contribution_cents FROM game_round WHERE status = 'ACTIVE'"));

        $client->request('GET', $firstLocation);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Scelta 1/20', $client->getResponse()->getContent() ?: '');
    }

    public function testStartWithoutPreissuedPlayerCookieFailsClosedWithoutAccounting(): void
    {
        $client = $this->createTransactionalClient();
        $connection = $this->testConnection();
        self::getContainer()->get(OpenRound::class)->open();
        $connection->executeStatement("DELETE FROM request_rate_limit WHERE scope = 'play_start_ip'");

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $csrf = (string) $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotSame('', $csrf);

        $client->getCookieJar()->expire(PlayerCookieFactory::NAME);
        $before = [
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'ledger' => (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')"),
            'contribution' => (int) $connection->fetchOne("SELECT entry_contribution_cents FROM game_round WHERE status = 'ACTIVE'"),
        ];

        $client->request('POST', '/gioca/inizia', ['_token' => $csrf]);
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame('/', (string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        self::assertSame($before['plays'], (int) $connection->fetchOne('SELECT COUNT(*) FROM play'));
        self::assertSame($before['ledger'], (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')"));
        self::assertSame($before['contribution'], (int) $connection->fetchOne("SELECT entry_contribution_cents FROM game_round WHERE status = 'ACTIVE'"));
    }

    public function testHttpsHomeIssuesSecurePlayerCookie(): void
    {
        $client = $this->createTransactionalClient([], ['HTTPS' => 'on']);
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $playerCookie = $this->findResponseCookie($client->getResponse()->headers->getCookies(), PlayerCookieFactory::NAME);
        self::assertNotNull($playerCookie);
        self::assertTrue($playerCookie->isSecure());
        self::assertTrue($playerCookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $playerCookie->getSameSite());
    }

    /** @param list<Cookie> $cookies */
    private function findResponseCookie(array $cookies, string $name): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
