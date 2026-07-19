<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Bootstrap;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/tools/ReleaseManifestVerifier.php';

final class ReleaseManifestVerifierTest extends TestCase
{
    public function testRuntimeArtifactsDoNotInvalidateAnAlreadyInitializedWorkingCopy(): void
    {
        $root = $this->temporaryDirectory();
        try {
            mkdir($root.'/var/cache/dev', 0775, true);
            mkdir($root.'/vendor/pkg', 0775, true);
            mkdir($root.'/bin/.phpunit/phpunit/vendor/theseer/tokenizer/src', 0775, true);
            file_put_contents($root.'/README.md', "release\n");
            file_put_contents($root.'/.env.local', "APP_SECRET=local\n");
            file_put_contents($root.'/var/cache/dev/container.php', '<?php');
            file_put_contents($root.'/var/test.db', 'db');
            file_put_contents($root.'/vendor/pkg/file.php', '<?php');
            file_put_contents($root.'/bin/.phpunit/phpunit/vendor/theseer/tokenizer/composer.lock', '{}');
            file_put_contents($root.'/bin/.phpunit/phpunit/vendor/theseer/tokenizer/src/Tokenizer.php', '<?php');
            file_put_contents($root.'/release-manifest.json', json_encode([
                'format' => 1,
                'release' => 'test',
                'files' => [
                    'README.md' => hash_file('sha256', $root.'/README.md'),
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            self::assertSame([], (new \ReleaseManifestVerifier())->violations($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testChangedMissingAndUnexpectedSourceFilesAreRejected(): void
    {
        $root = $this->temporaryDirectory();
        try {
            file_put_contents($root.'/README.md', "original\n");
            file_put_contents($root.'/missing.php', '<?php');
            $missingHash = hash_file('sha256', $root.'/missing.php');
            unlink($root.'/missing.php');
            file_put_contents($root.'/release-manifest.json', json_encode([
                'format' => 1,
                'release' => 'test',
                'files' => [
                    'README.md' => hash('sha256', "expected\n"),
                    'missing.php' => $missingHash,
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            file_put_contents($root.'/unexpected.php', '<?php');

            $violations = (new \ReleaseManifestVerifier())->violations($root);

            self::assertContains('release file hash mismatch: README.md', $violations);
            self::assertContains('release file is missing: missing.php', $violations);
            self::assertContains('unexpected source file not present in release manifest: unexpected.php', $violations);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPhpunitBridgeDownloadIsTreatedAsGeneratedTooling(): void
    {
        $root = $this->temporaryDirectory();
        try {
            mkdir($root.'/bin/.phpunit/phpunit/vendor/theseer/tokenizer/src', 0775, true);
            file_put_contents($root.'/README.md', "release\n");
            file_put_contents($root.'/bin/.phpunit/phpunit/vendor/theseer/tokenizer/src/Tokenizer.php', '<?php');
            file_put_contents($root.'/release-manifest.json', json_encode([
                'format' => 1,
                'release' => 'test',
                'files' => [
                    'README.md' => hash_file('sha256', $root.'/README.md'),
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            self::assertSame([], (new \ReleaseManifestVerifier())->violations($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testManifestCannotDeclarePhpunitBridgeDownloadAsReleaseFile(): void
    {
        $root = $this->temporaryDirectory();
        try {
            mkdir($root.'/bin/.phpunit', 0775, true);
            file_put_contents($root.'/bin/.phpunit/phpunit', 'generated');
            file_put_contents($root.'/release-manifest.json', json_encode([
                'format' => 1,
                'release' => 'test',
                'files' => [
                    'bin/.phpunit/phpunit' => hash_file('sha256', $root.'/bin/.phpunit/phpunit'),
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            self::assertContains(
                'manifest illegally includes runtime/secret path: bin/.phpunit/phpunit',
                (new \ReleaseManifestVerifier())->violations($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testManifestCannotDeclareRuntimeOrSecretFilesAsReleaseFiles(): void
    {
        $root = $this->temporaryDirectory();
        try {
            file_put_contents($root.'/.env.local', "APP_SECRET=secret\n");
            file_put_contents($root.'/release-manifest.json', json_encode([
                'format' => 1,
                'release' => 'test',
                'files' => [
                    '.env.local' => hash_file('sha256', $root.'/.env.local'),
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            self::assertContains(
                'manifest illegally includes runtime/secret path: .env.local',
                (new \ReleaseManifestVerifier())->violations($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/twentychoices-release-manifest-'.bin2hex(random_bytes(6));
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            self::fail('Unable to create temporary test directory.');
        }

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
