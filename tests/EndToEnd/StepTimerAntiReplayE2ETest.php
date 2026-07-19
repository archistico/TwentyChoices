<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Game\Application\OpenRound;
use App\Shared\Time\SystemClock;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\TransactionalWebTestCase;
use DateTimeImmutable;

final class StepTimerAntiReplayE2ETest extends TransactionalWebTestCase
{
    public function testClientCannotBypassTimerReplayOrControlStepStateWithForgedFields(): void
    {
        $client = $this->createTransactionalClient();
        $connection = $this->testConnection();
        $clock = new FrozenClock(new DateTimeImmutable('2035-01-01 12:00:00.000000 UTC'));
        self::getContainer()->set(SystemClock::class, $clock);
        self::getContainer()->get(OpenRound::class)->open();
        $connection->executeStatement("DELETE FROM request_rate_limit WHERE scope IN ('play_start_ip', 'play_view_ip', 'choice_ip')");

        $home = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $client->submit($home->selectButton('Inizia una giocata gratuita')->form());
        self::assertTrue($client->getResponse()->isRedirect());
        $playUrl = (string) $client->getResponse()->headers->get('Location');
        $playPath = (string) parse_url($playUrl, PHP_URL_PATH);
        $playCode = basename($playPath);
        self::assertNotSame('', $playCode);

        $firstPage = $client->request('GET', $playUrl);
        self::assertResponseIsSuccessful();
        $firstForm = $this->choiceForm($firstPage);
        $firstAction = (string) $firstForm->attr('action');
        $firstCsrf = (string) $firstForm->filter('input[name="_token"]')->attr('value');
        $firstChallenge = (string) $firstForm->filter('input[name="challengeToken"]')->attr('value');
        $firstRequestId = (string) $firstForm->filter('input[name="requestId"]')->attr('value');
        $playId = (string) $connection->fetchOne('SELECT id FROM play WHERE public_code = :code', ['code' => $playCode]);
        self::assertNotSame('', $playId);

        $clock->advance('+1999 milliseconds');
        $client->request('POST', $firstAction, $this->choicePayload(
            $firstCsrf,
            $firstChallenge,
            $firstRequestId,
            'A',
            999_999,
        ));
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(0, $this->currentStep($playId));
        self::assertSame('', (string) $connection->fetchOne('SELECT chosen_path_bits FROM play WHERE id = :id', ['id' => $playId]));
        self::assertNull($connection->fetchOne('SELECT answered_at FROM play_step WHERE play_id = :id AND step_number = 1', ['id' => $playId]) ?: null);

        $clock->advance('+1 millisecond');
        $client->request('POST', $firstAction, $this->choicePayload(
            $firstCsrf,
            $firstChallenge,
            $firstRequestId,
            'A',
            0,
        ));
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(1, $this->currentStep($playId));
        self::assertSame('0', (string) $connection->fetchOne('SELECT chosen_path_bits FROM play WHERE id = :id', ['id' => $playId]));
        $stepOne = $connection->fetchAssociative('SELECT selected_option, client_elapsed_ms FROM play_step WHERE play_id = :id AND step_number = 1', ['id' => $playId]);
        self::assertIsArray($stepOne);
        self::assertSame('A', $stepOne['selected_option']);
        self::assertSame(0, (int) $stepOne['client_elapsed_ms']);

        // Replay dello stesso idempotency key con un'opzione diversa: nessuna mutazione.
        $client->request('POST', $firstAction, $this->choicePayload(
            $firstCsrf,
            $firstChallenge,
            $firstRequestId,
            'B',
            500_000,
        ));
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(1, $this->currentStep($playId));
        self::assertSame('0', (string) $connection->fetchOne('SELECT chosen_path_bits FROM play WHERE id = :id', ['id' => $playId]));
        self::assertSame('A', (string) $connection->fetchOne('SELECT selected_option FROM play_step WHERE play_id = :id AND step_number = 1', ['id' => $playId]));

        // Due schede sullo stesso step: la seconda GET ruota il token senza azzerare il timer.
        $tabOnePage = $client->request('GET', $playUrl);
        self::assertResponseIsSuccessful();
        $tabOne = $this->choiceForm($tabOnePage);
        $tabOneToken = (string) $tabOne->filter('input[name="challengeToken"]')->attr('value');
        $tabOneRequest = (string) $tabOne->filter('input[name="requestId"]')->attr('value');
        $tabOneCsrf = (string) $tabOne->filter('input[name="_token"]')->attr('value');
        $timingBefore = $connection->fetchAssociative('SELECT shown_at, available_at FROM play_step WHERE play_id = :id AND step_number = 2', ['id' => $playId]);
        self::assertIsArray($timingBefore);

        $tabTwoPage = $client->request('GET', $playUrl);
        self::assertResponseIsSuccessful();
        $tabTwo = $this->choiceForm($tabTwoPage);
        $tabTwoToken = (string) $tabTwo->filter('input[name="challengeToken"]')->attr('value');
        $tabTwoRequest = (string) $tabTwo->filter('input[name="requestId"]')->attr('value');
        $tabTwoCsrf = (string) $tabTwo->filter('input[name="_token"]')->attr('value');
        $timingAfter = $connection->fetchAssociative('SELECT shown_at, available_at FROM play_step WHERE play_id = :id AND step_number = 2', ['id' => $playId]);

        self::assertNotSame($tabOneToken, $tabTwoToken);
        self::assertNotSame($tabOneRequest, $tabTwoRequest);
        self::assertSame($timingBefore, $timingAfter);

        $clock->advance('+2 seconds');
        $client->request('POST', (string) $tabOne->attr('action'), $this->choicePayload(
            $tabOneCsrf,
            $tabOneToken,
            $tabOneRequest,
            'B',
            2_000,
        ));
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(1, $this->currentStep($playId));

        $client->request('POST', (string) $tabTwo->attr('action'), $this->choicePayload(
            $tabTwoCsrf,
            $tabTwoToken,
            $tabTwoRequest,
            'B',
            0,
        ));
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(2, $this->currentStep($playId));
        self::assertSame('01', (string) $connection->fetchOne('SELECT chosen_path_bits FROM play WHERE id = :id', ['id' => $playId]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM play_step WHERE play_id = :id AND answered_at IS NOT NULL', ['id' => $playId]));
    }

    /** @return array<string, scalar> */
    private function choicePayload(string $csrf, string $challenge, string $requestId, string $option, int $clientElapsed): array
    {
        return [
            '_token' => $csrf,
            'challengeToken' => $challenge,
            'requestId' => $requestId,
            'selectedOption' => $option,
            'clientElapsedMilliseconds' => $clientElapsed,
            // Campi deliberatamente falsificati: il controller non deve considerarli autorevoli.
            'step' => 20,
            'currentStep' => 19,
            'roundId' => 'R-FORGED-ROUND',
            'playId' => 'P-FORGED-PLAY',
            'chosenPathBits' => str_repeat('1', 20),
            'shownAt' => '1999-01-01 00:00:00.000000',
            'availableAt' => '1999-01-01 00:00:00.000000',
            'answeredAt' => '2099-01-01 00:00:00.000000',
            'serverTimestamp' => '2099-01-01T00:00:00Z',
        ];
    }

    private function currentStep(string $playId): int
    {
        return (int) $this->testConnection()->fetchOne('SELECT current_step FROM play WHERE id = :id', ['id' => $playId]);
    }

    private function choiceForm(\Symfony\Component\DomCrawler\Crawler $crawler): \Symfony\Component\DomCrawler\Crawler
    {
        $form = $crawler->filter('form.choice-form');
        self::assertCount(1, $form);

        return $form;
    }
}
