<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Game\Application\OpenRound;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Tests\Support\TransactionalWebTestCase;
use Doctrine\DBAL\Connection;

final class ActiveRoundSecretNonDisclosureE2ETest extends TransactionalWebTestCase
{
    public function testActiveRoundSecretsNeverAppearInPublicBrowserPayloadsOrDom(): void
    {
        $client = $this->createTransactionalClient();
        $round = self::getContainer()->get(OpenRound::class)->open();
        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT encrypted_winning_path, encrypted_secret_nonce, question_set_hash, secret_commitment,
       revealed_winning_path, revealed_secret_nonce_hex, verification_published_at
FROM game_round
WHERE id = :id
SQL, ['id' => $round->id]);
        self::assertIsArray($row);

        $cipher = self::getContainer()->get(RoundSecretCipher::class);
        $path = $cipher->decrypt(self::blobToString($row['encrypted_winning_path']), OpenRound::pathContext($round->id));
        $nonce = $cipher->decrypt(self::blobToString($row['encrypted_secret_nonce']), OpenRound::nonceContext($round->id));
        $nonceHex = bin2hex($nonce);

        self::assertNull($row['revealed_winning_path']);
        self::assertNull($row['revealed_secret_nonce_hex']);
        self::assertNull($row['verification_published_at']);

        foreach (['/', '/round/'.$round->publicCode, '/storico', '/health', '/ready'] as $uri) {
            $client->request('GET', $uri);
            $response = $client->getResponse();
            self::assertTrue($response->isSuccessful(), $uri.' returned '.$response->getStatusCode());

            $payload = (string) $response->getContent()."\n".json_encode($response->headers->all(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($path, $payload, 'Winning path leaked from '.$uri);
            self::assertStringNotContainsString($nonceHex, strtolower($payload), 'Nonce leaked from '.$uri);
            self::assertStringNotContainsString(base64_encode(self::blobToString($row['encrypted_winning_path'])), $payload, 'Encrypted path leaked from '.$uri);
            self::assertStringNotContainsString(base64_encode(self::blobToString($row['encrypted_secret_nonce'])), $payload, 'Encrypted nonce leaked from '.$uri);
        }

        $client->request('GET', '/round/'.$round->publicCode);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $row['secret_commitment'], $html);
        self::assertStringContainsString((string) $row['question_set_hash'], $html);
        self::assertStringContainsString('Il materiale segreto non è pubblico mentre il round è attivo', $html);
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
