<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Game\Application\OpenPlayStep;
use App\Game\Application\OpenRound;
use App\Game\Application\RoundQuery;
use App\Game\Application\StartPlay;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Player\Application\PlayerSessionRegistry;
use App\Shared\Time\SystemClock;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;
use App\Tests\Support\TransactionalWebTestCase;

final class PlayJourneyE2ETest extends TransactionalWebTestCase
{
    public function testBrowserCompletesWinningPathResetsRoundAndCreditsPendingPlay(): void
    {
        $client = $this->createTransactionalClient();
        $container = self::getContainer();
        $connection = $this->testConnection();
        $connection->executeStatement("DELETE FROM request_rate_limit WHERE scope IN ('play_start_ip', 'play_view_ip', 'choice_ip')");
        $latestRoundStart = $connection->fetchOne('SELECT MAX(started_at) FROM game_round');
        $clockStart = is_string($latestRoundStart) && $latestRoundStart !== ''
            ? new DateTimeImmutable($latestRoundStart.' UTC')
            : new DateTimeImmutable('2030-01-01 12:00:00.000000 UTC');
        $clock = new FrozenClock($clockStart->modify('+1 day'));
        $container->set(SystemClock::class, $clock);
        $roundQuery = $container->get(RoundQuery::class);
        if ($roundQuery->active() === null) {
            $container->get(OpenRound::class)->open();
        }

        $round = $connection->fetchAssociative("SELECT id, public_code, encrypted_winning_path FROM game_round WHERE status = 'ACTIVE' LIMIT 1");
        self::assertIsArray($round);
        $ciphertext = $round['encrypted_winning_path'];
        if (is_resource($ciphertext)) {
            $ciphertext = stream_get_contents($ciphertext);
        }
        $winningPath = $container->get(RoundSecretCipher::class)->decrypt((string) $ciphertext, OpenRound::pathContext((string) $round['id']));
        self::assertSame(20, strlen($winningPath));

        // Una seconda giocata resta aperta e deve essere accreditata al settlement.
        $pendingIdentity = $container->get(PlayerSessionRegistry::class)->resolve(null);
        $pendingPlay = $container->get(StartPlay::class)->start($pendingIdentity->id);
        $container->get(OpenPlayStep::class)->open($pendingPlay->publicCode, $pendingIdentity->id);

        $crawler = $client->request('GET', '/');
        $client->submit($crawler->selectButton('Inizia una giocata gratuita')->form());
        self::assertTrue($client->getResponse()->isRedirect());
        $playUrl = (string) $client->getResponse()->headers->get('Location');
        $playPath = (string) parse_url($playUrl, PHP_URL_PATH);
        $browserPlayCode = basename($playPath);
        self::assertNotSame('', $browserPlayCode);

        for ($step = 1; $step <= 20; ++$step) {
            $crawler = $client->request('GET', $playUrl);
            self::assertResponseIsSuccessful();
            $formNode = $crawler->filter('form.choice-form');
            self::assertCount(1, $formNode);
            $action = (string) $formNode->attr('action');
            $csrf = (string) $formNode->filter('input[name="_token"]')->attr('value');
            $challenge = (string) $formNode->filter('input[name="challengeToken"]')->attr('value');
            $requestId = (string) $formNode->filter('input[name="requestId"]')->attr('value');

            $clock->advance('+2 seconds');

            $client->request('POST', $action, [
                '_token' => $csrf,
                'challengeToken' => $challenge,
                'requestId' => $requestId,
                'selectedOption' => $winningPath[$step - 1] === '0' ? 'A' : 'B',
                'clientElapsedMilliseconds' => 2000,
            ]);
            self::assertTrue($client->getResponse()->isRedirect());
            self::assertSame($step, (int) $connection->fetchOne(
                'SELECT current_step FROM play WHERE public_code = :code',
                ['code' => $browserPlayCode],
            ));
        }

        $crawler = $client->request('GET', $playUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Hai trovato la strada', $crawler->filter('body')->text());

        $oldRound = $connection->fetchAssociative('SELECT status, winner_play_id FROM game_round WHERE id = :id', ['id' => $round['id']]);
        self::assertSame('SETTLED', $oldRound['status']);
        self::assertNotNull($oldRound['winner_play_id']);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'"));
        self::assertSame('CREDITED', $connection->fetchOne('SELECT status FROM play WHERE public_code = :code', ['code' => $pendingPlay->publicCode]));
        self::assertSame('AVAILABLE', $connection->fetchOne('SELECT status FROM play_credit WHERE source_play_id = (SELECT id FROM play WHERE public_code = :code)', ['code' => $pendingPlay->publicCode]));
    }
}
