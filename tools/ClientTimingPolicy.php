<?php

declare(strict_types=1);

final class ClientTimingPolicy
{
    /** @return list<array{name:string,status:string,value:string,detail:string}> */
    public function inspect(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/\\');
        $javascript = $this->readFile($root.'/public/play.js');
        $template = $this->readFile($root.'/templates/play/show.html.twig');

        $usesMonotonicClock = str_contains($javascript, 'performance.now()')
            && !str_contains($javascript, 'Date.now()');
        $usesRelativeServerTiming = str_contains($template, 'data-wait-remaining-ms=')
            && str_contains($template, 'data-elapsed-at-render-ms=')
            && !str_contains($template, 'data-shown-at=')
            && !str_contains($template, 'data-available-at=');

        return [
            $this->check(
                'Client monotonic clock',
                $usesMonotonicClock,
                $usesMonotonicClock ? 'performance.now()' : 'invalid',
                'play.js deve usare un clock monotono e non Date.now() per il countdown.',
            ),
            $this->check(
                'Relative server timing',
                $usesRelativeServerTiming,
                $usesRelativeServerTiming ? 'relative milliseconds' : 'invalid',
                'Il DOM deve ricevere durate relative calcolate dal server, non epoch assoluti.',
            ),
        ];
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
