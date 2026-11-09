<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use PHPUnit\Framework\TestCase;

/**
 * src/Generated must equal what tools/sdk/generate_php.py writes from the checked-in OpenAPI document
 * (the PHP twin of packages/sdk-python/tests/test_drift.py and packages/sdk-js/test/drift.test.ts).
 */
final class DriftTest extends TestCase
{
    public function testGeneratedFilesMatchTheGenerator(): void
    {
        $generator = dirname(__DIR__, 3) . '/tools/sdk/generate_php.py';
        if (!is_file($generator)) {
            $this->markTestSkipped('the generator lives in the monorepo, not in the published package');
        }
        $python = self::python();
        if ($python === null) {
            $this->markTestSkipped('python 3 is not on PATH (set PYTHON=...); run `python tools/sdk/generate_php.py --check` instead');
        }
        $proc = proc_open([$python, $generator, '--check'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, trim((string) $out));
    }

    private static function python(): ?string
    {
        $candidates = array_filter([getenv('PYTHON') ?: null, 'python3', 'python']);
        foreach ($candidates as $bin) {
            $proc = @proc_open([$bin, '-c', 'import sys; sys.exit(0 if sys.version_info >= (3, 9) else 1)'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) {
                continue;
            }
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($proc) === 0) {
                return $bin;
            }
        }
        return null;
    }
}
