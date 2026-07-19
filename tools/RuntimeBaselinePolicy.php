<?php

declare(strict_types=1);

final class RuntimeBaselinePolicy
{
    public const MINIMUM_PHP_VERSION = '8.4.0';
    public const COMPOSER_REQUIREMENT = '>=8.4';

    public static function supportsPhpVersion(string $version): bool
    {
        return version_compare($version, self::MINIMUM_PHP_VERSION, '>=');
    }

    /** @return list<array{name:string,status:string,value:string,detail:string}> */
    public function inspect(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/\\');
        $checks = [];

        $composer = $this->readJson($root.'/composer.json');
        $composerRequirement = (string) ($composer['require']['php'] ?? '');
        $checks[] = $this->check(
            'composer.json PHP baseline',
            $composerRequirement === self::COMPOSER_REQUIREMENT,
            $composerRequirement === '' ? '(missing)' : $composerRequirement,
            'composer.json deve dichiarare esattamente '.self::COMPOSER_REQUIREMENT.'.',
        );

        $platformOverride = (string) ($composer['config']['platform']['php'] ?? '');
        $checks[] = $this->check(
            'Composer resolution platform',
            $platformOverride === self::MINIMUM_PHP_VERSION,
            $platformOverride === '' ? '(missing)' : $platformOverride,
            'config.platform.php deve restare fissato a '.self::MINIMUM_PHP_VERSION.' per non alzare accidentalmente la baseline durante composer update.',
        );

        $lock = $this->readJson($root.'/composer.lock');
        $lockRequirement = (string) ($lock['platform']['php'] ?? '');
        $checks[] = $this->check(
            'composer.lock PHP baseline',
            $lockRequirement === self::COMPOSER_REQUIREMENT,
            $lockRequirement === '' ? '(missing)' : $lockRequirement,
            'composer.lock deve essere allineato alla baseline '.self::COMPOSER_REQUIREMENT.'.',
        );

        $lockPlatformOverride = (string) ($lock['platform-overrides']['php'] ?? '');
        $checks[] = $this->check(
            'Lock resolution platform',
            $lockPlatformOverride === self::MINIMUM_PHP_VERSION,
            $lockPlatformOverride === '' ? '(missing)' : $lockPlatformOverride,
            'composer.lock deve registrare platform-overrides.php = '.self::MINIMUM_PHP_VERSION.'.',
        );

        $expectedContentHash = $this->composerContentHash($composer);
        $actualContentHash = (string) ($lock['content-hash'] ?? '');
        $checks[] = $this->check(
            'composer.lock content hash',
            hash_equals($expectedContentHash, $actualContentHash),
            $actualContentHash === '' ? '(missing)' : $actualContentHash,
            'composer.lock deve essere fresco rispetto al composer.json distribuito.',
        );

        $readme = $this->readFile($root.'/README.md');
        $readmeOk = str_contains($readme, '- PHP 8.4 o superiore')
            && !str_contains($readme, '- PHP 8.3 o 8.4');
        $checks[] = $this->check(
            'README PHP baseline',
            $readmeOk,
            $readmeOk ? 'PHP 8.4+' : 'inconsistent',
            'La sezione requisiti del README deve dichiarare PHP 8.4 o superiore e non la vecchia baseline 8.3/8.4.',
        );

        $bootstrapPowerShell = $this->readFile($root.'/scripts/bootstrap.ps1');
        $bootstrapShell = $this->readFile($root.'/scripts/bootstrap.sh');
        $bootstrapOk = !str_contains($bootstrapPowerShell, '8.3')
            && !str_contains($bootstrapShell, '8.3')
            && str_contains($bootstrapPowerShell, 'PHP 8.4')
            && str_contains($bootstrapShell, 'PHP 8.4');
        $checks[] = $this->check(
            'Bootstrap PHP baseline',
            $bootstrapOk,
            $bootstrapOk ? 'PHP 8.4+' : 'inconsistent',
            'Entrambi gli script bootstrap devono richiedere PHP 8.4 o superiore.',
        );

        $ci = $this->readFile($root.'/.github/workflows/ci.yml');
        $ciOk = str_contains($ci, "php-version: '8.4'")
            && str_contains($ci, 'composer check-platform-reqs');
        $checks[] = $this->check(
            'CI runtime gate',
            $ciOk,
            $ciOk ? 'PHP 8.4 + platform check' : 'incomplete',
            'La CI deve usare PHP 8.4 ed eseguire composer check-platform-reqs.',
        );

        return $checks;
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    public function hasErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $composer */
    private function composerContentHash(array $composer): string
    {
        $relevantKeys = [
            'name',
            'version',
            'require',
            'require-dev',
            'conflict',
            'replace',
            'provide',
            'minimum-stability',
            'prefer-stable',
            'repositories',
            'extra',
        ];
        $relevantContent = [];
        foreach ($relevantKeys as $key) {
            if (array_key_exists($key, $composer)) {
                $relevantContent[$key] = $composer[$key];
            }
        }
        if (isset($composer['config']['platform'])) {
            $relevantContent['config']['platform'] = $composer['config']['platform'];
        }
        ksort($relevantContent);

        return md5(json_encode($relevantContent, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = $this->readFile($path);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON root non valido: '.$path);
        }

        return $decoded;
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('File non leggibile: '.$path);
        }

        return $contents;
    }

    /** @return array{name:string,status:string,value:string,detail:string} */
    private function check(string $name, bool $ok, string $value, string $detail): array
    {
        return [
            'name' => $name,
            'status' => $ok ? 'ok' : 'error',
            'value' => $value,
            'detail' => $detail,
        ];
    }
}
