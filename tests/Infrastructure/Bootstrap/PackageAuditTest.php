<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Bootstrap;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/tools/PackageAudit.php';

final class PackageAuditTest extends TestCase
{
    public function testCleanPackageAllowsOnlySourceFilesAndPlaceholderSecret(): void
    {
        $root = $this->temporaryDirectory();
        try {
            mkdir($root.'/var', 0775, true);
            file_put_contents($root.'/var/.gitignore', "*\n!.gitignore\n");
            file_put_contents($root.'/.env', "APP_ENV=dev\nAPP_SECRET=change-this-development-secret\n");

            $violations = (new \PackageAudit())->violations($root);

            self::assertSame([], $violations);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPackageAuditRejectsSecretsDatabasesVendorAndRuntimeArtifacts(): void
    {
        $root = $this->temporaryDirectory();
        try {
            mkdir($root.'/var/log', 0775, true);
            mkdir($root.'/vendor', 0775, true);
            mkdir($root.'/bin/.phpunit/phpunit', 0775, true);
            file_put_contents($root.'/.env', "APP_ENV=dev\nAPP_SECRET=real-secret-that-must-not-ship\n");
            file_put_contents($root.'/.env.local', "APP_SECRET=local\n");
            file_put_contents($root.'/.env.local.php', "<?php return ['APP_SECRET' => 'compiled'];\n");
            file_put_contents($root.'/var/data.db', 'db');
            file_put_contents($root.'/var/data.db-journal', 'journal');
            file_put_contents($root.'/var/log/security.jsonl', '{}');
            file_put_contents($root.'/bin/.phpunit/phpunit/phpunit', 'generated');

            $violations = (new \PackageAudit())->violations($root);

            self::assertContains('.env contains a non-placeholder APP_SECRET', $violations);
            self::assertContains('.env.local', $violations);
            self::assertContains('.env.local.php', $violations);
            self::assertContains('var/data.db', $violations);
            self::assertContains('var/data.db-journal', $violations);
            self::assertContains('var/log/security.jsonl', $violations);
            self::assertContains('vendor/', $violations);
            self::assertContains('bin/.phpunit/', $violations);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/twentychoices-package-audit-'.bin2hex(random_bytes(6));
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
