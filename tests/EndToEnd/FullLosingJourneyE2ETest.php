<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Game\Application\OpenRound;
use App\Game\Application\RoundQuery;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Shared\Time\SystemClock;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\TransactionalWebTestCase;
use DateTimeImmutable;

final class FullLosingJourneyE2ETest extends TransactionalWebTestCase
{
    public function testBrowserCompletesLosingJourneyGetsReceiptWithoutRevealAndCanRetrySameRound(): void
    {
        $client = $this->createTransactionalClient();
        $container = self::getContainer();
        $connection = $this->testConnection();
        $connection->executeStatement("DELETE FROM request_rate_limit WHERE scope IN ('play_start_ip', 'play_view_ip', 'choice_ip')");
        $clock = new FrozenClock(new DateTimeImmutable('2036-01-01 12:00:00.000000 UTC'));
        $container->set(SystemClock::class, $clock);

        if ($container->get(RoundQuery::class)->active() === null) {
            $container->get(OpenRound::class)->open();
        }

        $round = $connection->fetchAssociative(<<<'SQL'
SELECT id, public_code, encrypted_winning_path, encrypted_secret_nonce
FROM game_round
WHERE status = 'ACTIVE'
LIMIT 1
SQL);
        self::assertIsArray($round);
        $cipher = $container->get(RoundSecretCipher::class);
        $winningPath = $cipher->decrypt(
            self::blobToString($round['encrypted_winning_path']),
            OpenRound::pathContext((string) $round['id']),
        );
        $secretNonce = $cipher->decrypt(
            self::blobToString($round['encrypted_secret_nonce']),
            OpenRound::nonceContext((string) $round['id']),
        );
        $losingPath = ($winningPath[0] === '0' ? '1' : '0').substr($winningPath, 1);

        $home = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $client->submit($home->selectButton('Inizia una giocata gratuita')->form());
        self::assertTrue($client->getResponse()->isRedirect());
        $playUrl = (string) $client->getResponse()->headers->get('Location');
        $playCode = basename((string) parse_url($playUrl, PHP_URL_PATH));
        self::assertNotSame('', $playCode);
        $playId = (string) $connection->fetchOne('SELECT id FROM play WHERE public_code = :code', ['code' => $playCode]);

        for ($step = 1; $step <= 20; ++$step) {
            $page = $client->request('GET', $playUrl);
            self::assertResponseIsSuccessful();
            $form = $page->filter('form.choice-form');
            self::assertCount(1, $form);
            $clock->advance('+2 seconds');

            $client->request('POST', (string) $form->attr('action'), [
                '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
                'challengeToken' => (string) $form->filter('input[name="challengeToken"]')->attr('value'),
                'requestId' => (string) $form->filter('input[name="requestId"]')->attr('value'),
                'selectedOption' => $losingPath[$step - 1] === '0' ? 'A' : 'B',
                'clientElapsedMilliseconds' => 2_000,
            ]);
            self::assertTrue($client->getResponse()->isRedirect());
            self::assertSame($step, (int) $connection->fetchOne('SELECT current_step FROM play WHERE id = :id', ['id' => $playId]));

            if ($step < 20) {
                self::assertSame('IN_PROGRESS', $connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $playId]));
                self::assertSame('ACTIVE', $connection->fetchOne('SELECT status FROM game_round WHERE id = :id', ['id' => $round['id']]));
            }
        }

        $completed = $client->request('GET', $playUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Il percorso non era quello corretto', $completed->filter('body')->text());
        self::assertSame('COMPLETED_LOST', $connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $playId]));
        $completedAt = $connection->fetchOne('SELECT completed_at FROM play WHERE id = :id', ['id' => $playId]);
        self::assertIsString($completedAt);
        self::assertNotSame('', $completedAt);
        self::assertSame(20, (int) $connection->fetchOne('SELECT COUNT(*) FROM play_step WHERE play_id = :id AND answered_at IS NOT NULL', ['id' => $playId]));
        self::assertSame($losingPath, $connection->fetchOne('SELECT chosen_path_bits FROM play WHERE id = :id', ['id' => $playId]));
        self::assertSame('ACTIVE', $connection->fetchOne('SELECT status FROM game_round WHERE id = :id', ['id' => $round['id']]));
        self::assertNull($connection->fetchOne('SELECT revealed_winning_path FROM game_round WHERE id = :id', ['id' => $round['id']]) ?: null);
        self::assertNull($connection->fetchOne('SELECT revealed_secret_nonce_hex FROM game_round WHERE id = :id', ['id' => $round['id']]) ?: null);

        $verificationLink = $completed->selectLink('Verifica la giocata')->link()->getUri();
        $receiptPage = $client->request('GET', $verificationLink);
        self::assertResponseIsSuccessful();
        $receiptBody = $receiptPage->filter('body')->text();
        self::assertStringContainsString('LOST', $receiptBody);
        self::assertStringContainsString('round è ancora ACTIVE', $receiptBody);
        self::assertStringNotContainsString($winningPath, $receiptBody);
        self::assertStringNotContainsString(bin2hex($secretNonce), $receiptBody);

        $client->submit($completed->selectButton('Riprova')->form());
        self::assertTrue($client->getResponse()->isRedirect());
        $retryUrl = (string) $client->getResponse()->headers->get('Location');
        $retryCode = basename((string) parse_url($retryUrl, PHP_URL_PATH));
        self::assertNotSame($playCode, $retryCode);
        $retry = $connection->fetchAssociative(
            'SELECT round_id, status, participation_number, entry_kind FROM play WHERE public_code = :code',
            ['code' => $retryCode],
        );
        self::assertIsArray($retry);
        self::assertSame((string) $round['id'], (string) $retry['round_id']);
        self::assertSame('IN_PROGRESS', $retry['status']);
        self::assertSame(2, (int) $retry['participation_number']);
        self::assertSame('STANDARD', $retry['entry_kind']);
        self::assertSame('COMPLETED_LOST', $connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $playId]));
    }

    private static function blobToString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }
}
