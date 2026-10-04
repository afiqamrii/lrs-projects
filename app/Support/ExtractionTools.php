<?php

namespace App\Support;

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

class ExtractionTools
{
    public function execute(array $arguments, ?string $directory = null, int $outputLimit = 1048576): string
    {
        $process = new Process($arguments, $directory, ['OMP_THREAD_LIMIT' => '1']);
        $process->setTimeout((int) config('extraction.tool_timeout'));
        $bytes = 0;
        try {
            $process->run(function (string $type, string $buffer) use ($process, &$bytes, $outputLimit): void {
                $bytes += strlen($buffer);
                if ($bytes > $outputLimit) {
                    $process->stop(0);
                    throw new ExtractionProblem('output_limit', 'The extractor output exceeded its safety limit. Select fewer pages or enter the details manually.');
                }
            });
        } catch (ExtractionProblem $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ExtractionProblem('tool_execution', 'The local tool could not complete within its configured limit. Retry a smaller selection or use manual entry.');
        }
        if (! $process->isSuccessful()) {
            throw new ExtractionProblem('tool_failed', 'The local tool could not read this source. It may be encrypted, damaged or unsupported. The original is retained for manual review.');
        }

        return $process->getOutput();
    }

    public function profile(): array
    {
        $configuration = config('extraction');
        unset($configuration['demo']);
        $versions = [];
        foreach (['pdftotext', 'pdfinfo', 'pdftoppm', 'tesseract'] as $name) {
            try {
                $process = new Process([$configuration[$name], $name === 'tesseract' ? '--version' : '-v']);
                $process->setTimeout(5);
                $process->run();
                $versions[$name] = $process->isSuccessful() ? mb_substr(trim(strtok($process->getOutput().$process->getErrorOutput(), "\n") ?: ''), 0, 250) : 'unavailable';
            } catch (\Throwable $exception) {
                $versions[$name] = 'unavailable';
            }
        }
        $versions['phpspreadsheet'] = InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet') ?? 'unavailable';
        $versions['php'] = PHP_VERSION;

        return ['limits' => $configuration, 'tools' => $versions];
    }

    public function requireTool(string $name, array $configuration): void
    {
        if (($configuration['tools'][$name] ?? 'unavailable') === 'unavailable') {
            throw new ExtractionProblem('missing_'.$name, 'The '.$name.' tool is unavailable. Ask Admin to configure it, or continue with manual entry.', true);
        }
    }

    public function ocrArguments(array $configuration): array
    {
        $limits = $configuration['limits'];
        $extra = empty($limits['tessdata']) ? [] : ['--tessdata-dir', $limits['tessdata']];
        $installed = $this->execute([$limits['tesseract'], '--list-langs', ...$extra]);
        foreach (explode('+', $limits['languages']) as $language) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $language) || ! preg_match('/^'.preg_quote($language, '/').'\r?$/m', $installed)) {
                throw new ExtractionProblem('missing_language', 'The configured OCR language is not installed. Ask Admin to configure the language pack.', true);
            }
        }

        return $extra;
    }
}
