<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemLogController extends Controller
{
    public function index(Request $request): View
    {
        $availableLogs = $this->availableLogs();
        $selectedLogType = (string) $request->query('log_type', 'coindcx_prices');

        if (! array_key_exists($selectedLogType, $availableLogs)) {
            $selectedLogType = 'coindcx_prices';
        }

        $lines = (int) $request->query('lines', 200);
        if ($lines < 50) {
            $lines = 50;
        } elseif ($lines > 1000) {
            $lines = 1000;
        }

        $search = trim((string) $request->query('search', ''));
        $selectedLog = $availableLogs[$selectedLogType];
        $filePath = $selectedLog['path'];
        $fileExists = is_file($filePath);
        $logLines = [];

        if ($fileExists) {
            $logLines = $this->tailFile($filePath, $lines);

            if ($search !== '') {
                $logLines = array_values(array_filter(
                    $logLines,
                    fn (string $line): bool => stripos($line, $search) !== false
                ));
            }

            $logLines = array_map(fn (string $line): string => $this->sanitizeLogLine($line), $logLines);
        }

        return view('logs.index', [
            'availableLogs' => $this->availableLogOptions($availableLogs),
            'selectedLogType' => $selectedLogType,
            'selectedLogLabel' => $selectedLog['label'],
            'lines' => $lines,
            'search' => $search,
            'logLines' => $logLines,
            'fileExists' => $fileExists,
            'filePathDisplay' => $selectedLog['display_path'],
        ]);
    }

    /**
     * @return array<string, array{label: string, path: string, display_path: string}>
     */
    private function availableLogs(): array
    {
        return [
            'coindcx_prices' => [
                'label' => 'CoinDCX Price Fetch Logs',
                'path' => base_path('python/logs/coindcx_prices.log'),
                'display_path' => 'python/logs/coindcx_prices.log',
            ],
            'monitor' => [
                'label' => 'Python Monitor Logs',
                'path' => base_path('python/logs/monitor.log'),
                'display_path' => 'python/logs/monitor.log',
            ],
            'python_errors' => [
                'label' => 'Python Error Logs',
                'path' => base_path('python/logs/errors.log'),
                'display_path' => 'python/logs/errors.log',
            ],
            'laravel' => [
                'label' => 'Laravel Logs',
                'path' => storage_path('logs/laravel.log'),
                'display_path' => 'storage/logs/laravel.log',
            ],
        ];
    }

    /**
     * @param array<string, array{label: string, path: string, display_path: string}> $availableLogs
     * @return array<string, array{label: string, display_path: string}>
     */
    private function availableLogOptions(array $availableLogs): array
    {
        return array_map(
            fn (array $log): array => [
                'label' => $log['label'],
                'display_path' => $log['display_path'],
            ],
            $availableLogs
        );
    }

    /**
     * @return array<int, string>
     */
    private function tailFile(string $path, int $lines): array
    {
        if ($lines <= 0 || ! is_readable($path)) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - $lines + 1);
        $logLines = [];

        $file->seek($startLine);
        while (! $file->eof()) {
            $line = rtrim((string) $file->fgets(), "\r\n");

            if ($line === '' && $file->eof()) {
                continue;
            }

            $logLines[] = $line;
        }

        return $logLines;
    }

    private function sanitizeLogLine(string $line): string
    {
        $line = preg_replace('/(X-PYTHON-API-TOKEN|Authorization)(["\'\s:=]+)([^,"\'\s}]+)/i', '$1$2[redacted]', $line) ?? $line;
        $line = preg_replace('/(api[_-]?token|token)(["\'\s:=]+)([^,"\'\s}]+)/i', '$1$2[redacted]', $line) ?? $line;

        return $line;
    }
}
