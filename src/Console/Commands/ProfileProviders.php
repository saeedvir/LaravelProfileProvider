<?php

namespace Saeedvir\LaravelProfileProvider\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\TableSeparator;

class ProfileProviders extends Command
{
    protected $signature = 'profile:providers
        {--top= : Show top N slowest providers}
        {--threshold= : Mark providers slower than this (seconds)}
        {--sort= : Sort by total, register, or boot}
        {--format=table : Output format (table, json, csv)}
        {--export= : Save results to file}
        {--compare : Compare with previous run}
        {--memory : Include memory usage}
        {--no-diagnostics : Skip diagnostic analysis}
        {--use-cache : Read providers from bootstrap/cache/services.php}
        {--dry-run : Simulate profiling without actual execution}
        {--only-cached : Profile only providers from cache file}
        {--parallel : Simulate parallel boot timing (estimate)}';

    protected $description = 'Profile service providers with timing, memory usage, and diagnostic analysis';

    private const SIGNIFICANT_CHANGE_THRESHOLD = 0.001;

    private array $diagnosticPatterns = [];
    private array $fileCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->initializeDiagnosticPatterns();
    }

    public function handle(): int
    {
        $this->applyConfigDefaults();

        if (!$this->option('dry-run') && !$this->confirm('This may affect application performance. Continue?', true)) {
            return 1;
        }

        $threshold = (float) ($this->option('threshold') ?? config('profile-provider.threshold', 0.01));
        $providers = $this->getProvidersList();

        if (empty($providers)) {
            $this->error('No providers found.');
            return 1;
        }

        $this->info(sprintf('Found %d service provider(s) to profile', count($providers)));

        if ($this->option('parallel')) {
            $this->info('Note: Parallel timing is an estimation based on dependency analysis');
        }

        $timings = $this->profileProviders($providers);
        $stats = $this->calculateStatistics($timings, $threshold);

        if ($this->option('compare')) {
            $this->compareWithPreviousRun($timings);
        }

        $this->displayResults($timings, $stats, $threshold);

        if ($exportPath = $this->option('export')) {
            $this->exportResults($timings, $this->option('format'), $exportPath);
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: No actual profiling was performed. Data is simulated.');
        }

        return 0;
    }

    private function applyConfigDefaults(): void
    {
        if (!$this->option('top')) {
            $this->optionResolver->setDefault('top', config('profile-provider.top', 20));
        }

        if (!$this->option('threshold')) {
            $this->optionResolver->setDefault('threshold', config('profile-provider.threshold', 0.01));
        }

        if (!$this->option('sort')) {
            $this->optionResolver->setDefault('sort', config('profile-provider.sort', 'total'));
        }
    }

    private function initializeDiagnosticPatterns(): void
    {
        $this->diagnosticPatterns = [
            'filesystem' => '/File::allFiles|glob\(|Finder::create|Storage::/',
            'http' => '/Http::|GuzzleHttp|curl_|https?:\/\/|HttpClient/',
            'config' => '/config\s*\(|Config::get|config_path/',
            'container' => '/->make\(|app\(|resolve\(|singleton\(|bind\(/',
            'database' => '/DB::|Eloquent|Schema::|Model::/',
            'cache' => '/Cache::|cache\(|remember\(/',
            'event' => '/Event::|event\(|listen\(/',
            'queue' => '/Queue::|dispatch\(|job\(/',
            'deferred' => '/public\s+\$defer\s*=\s*true/',
            'broadcast' => '/Broadcast::|broadcaster/',
            'mail' => '/Mail::|mail\(|Mailable/',
            'notification' => '/Notification::|notify\(/',
            'session' => '/Session::|session\(/',
            'validation' => '/Validator::|validate\(/',
            'view' => '/View::|view\(|Blade::/',
            'route' => '/Route::|route\(|UrlGenerator/',
            'auth' => '/Auth::|auth\(|Gate::/',
            'log' => '/Log::|logger\(|Logging/',
            'redis' => '/Redis::|redis\(/',
            'artisan' => '/Artisan::|command\(/',
        ];
    }

    private function getProvidersList(): array
    {
        $useCache = $this->option('use-cache');
        $onlyCached = $this->option('only-cached');
        $providers = [];

        if ($useCache || $onlyCached) {
            $cacheFile = base_path('bootstrap/cache/services.php');
            if (file_exists($cacheFile)) {
                $cacheData = include $cacheFile;
                if (is_array($cacheData) && isset($cacheData['providers']) && is_array($cacheData['providers'])) {
                    $providers = array_merge($providers, Arr::wrap($cacheData['providers']));
                    $this->info(sprintf('Found %d provider(s) from cache file', count($cacheData['providers'])));
                } else {
                    $this->warn('Cache file has invalid structure: bootstrap/cache/services.php');
                }
            } else {
                $this->warn('Cache file not found: bootstrap/cache/services.php');
            }
        }

        if ($onlyCached) {
            return array_values(array_unique($providers));
        }

        $providersFile = base_path('bootstrap/providers.php');
        if (file_exists($providersFile)) {
            $fileProviders = Arr::wrap(require $providersFile);
            $providers = array_merge($providers, $fileProviders);
        }

        $configProviders = Arr::wrap(config('app.providers', []));
        $providers = array_merge($providers, $configProviders);

        $this->addPackageProviders($providers);

        return array_values(array_unique($providers));
    }

    private function addPackageProviders(array &$providers): void
    {
        $composerFile = base_path('composer.json');
        if (!file_exists($composerFile)) {
            return;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        if ($composer && isset($composer['extra']['laravel']['providers'])) {
            $packageProviders = Arr::wrap($composer['extra']['laravel']['providers']);
            $providers = array_merge($providers, $packageProviders);
        }
    }

    private function profileProviders(array $providers): array
    {
        $app = $this->laravel;
        $instances = [];
        $timings = [];

        $progressBar = null;
        if ($this->output->isVerbose()) {
            $progressBar = $this->output->createProgressBar(count($providers) * 2);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
            $progressBar->setMessage('Starting provider profiling...');
            $progressBar->start();
        }

        foreach ($providers as $providerClass) {
            if ($progressBar) {
                $progressBar->setMessage("Registering: {$this->formatProviderName($providerClass)}");
                $progressBar->advance();
            }

            $result = $this->profileProvider($providerClass, function () use ($app, $providerClass) {
                $provider = $app->resolveProvider($providerClass);
                $provider->register();
                return $provider;
            }, 'register');

            if ($result['success'] ?? false) {
                $providerInstance = $result['data'];
                $instances[$providerClass] = $providerInstance; // Critical fix: store instance

                $timings[$providerClass] = [
                    'register_time' => $result['time'],
                    'register_memory' => $result['memory'] ?? 0,
                    'register_error' => $result['error'] ?? null,
                    'is_deferred' => $providerInstance && method_exists($providerInstance, 'isDeferred')
                        ? $providerInstance->isDeferred()
                        : false,
                    'provides' => $providerInstance && method_exists($providerInstance, 'provides')
                        ? $providerInstance->provides()
                        : [],
                ];
            } else {
                $timings[$providerClass] = [
                    'error' => $result['error'] ?? 'Failed to resolve or register'
                ];
                continue;
            }

            if (!$this->option('no-diagnostics')) {
                $timings[$providerClass]['diagnostics'] = $this->analyzeProvider($providerClass);
                $timings[$providerClass]['dependencies'] = $this->analyzeDependencies($providerClass);
            }
        }

        foreach ($instances as $providerClass => $provider) {
            if ($progressBar) {
                $progressBar->setMessage("Booting: {$this->formatProviderName($providerClass)}");
                $progressBar->advance();
            }

            $result = $this->profileProvider($providerClass, function () use ($provider) {
                if (method_exists($provider, 'boot')) {
                    $provider->boot();
                }
            }, 'boot');

            $timings[$providerClass]['boot_time'] = $result['time'];
            $timings[$providerClass]['boot_memory'] = $result['memory'] ?? 0;
            $timings[$providerClass]['boot_error'] = $result['error'] ?? null;

            $timings[$providerClass]['total_time'] =
                ($timings[$providerClass]['register_time'] ?? 0) +
                ($timings[$providerClass]['boot_time'] ?? 0);

            $timings[$providerClass]['total_memory'] =
                ($timings[$providerClass]['register_memory'] ?? 0) +
                ($timings[$providerClass]['boot_memory'] ?? 0);
        }

        if ($progressBar) {
            $progressBar->setMessage('Profiling complete!');
            $progressBar->finish();
            $this->newLine();
        }

        if ($this->option('parallel')) {
            $this->calculateParallelTiming($timings);
        }

        return $this->sortTimings($timings);
    }

    private function profileProvider(string $providerClass, callable $callback, string $phase): array
    {
        if ($this->option('dry-run')) {
            $baseTime = 0.0001;
            $complexity = strlen($providerClass) / 100;
            $randomFactor = rand(80, 120) / 100;

            return [
                'time' => $baseTime * $complexity * $randomFactor,
                'memory' => $this->option('memory') ? rand(1024, 10240) : 0,
                'success' => true,
                'data' => new class {
                    public function isDeferred()
                    {
                        return rand(0, 1) ? true : false;
                    }
                    public function provides()
                    {
                        return [];
                    }
                },
            ];
        }

        $startTime = hrtime(true);
        $startMemory = $this->option('memory') ? memory_get_usage(true) : 0;

        try {
            $result = $callback();
            return [
                'time' => (hrtime(true) - $startTime) / 1e9,
                'memory' => $this->option('memory') ? memory_get_usage(true) - $startMemory : 0,
                'success' => true,
                'data' => $result ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'time' => (hrtime(true) - $startTime) / 1e9,
                'memory' => $this->option('memory') ? memory_get_usage(true) - $startMemory : 0,
                'error' => $e->getMessage(),
                'success' => false,
            ];
        }
    }

    private function analyzeProvider(string $class): array
    {
        if (isset($this->fileCache[$class])) {
            return $this->fileCache[$class];
        }

        try {
            $ref = new ReflectionClass($class);
            $file = $ref->getFileName();

            if (!$file || !is_file($file)) {
                return $this->fileCache[$class] = ['no_source'];
            }

            $code = File::get($file);
            if ($code === '') {
                return $this->fileCache[$class] = ['empty_source'];
            }
        } catch (\Throwable) {
            return $this->fileCache[$class] = ['reflection_failed'];
        }

        $flags = [];
        foreach ($this->diagnosticPatterns as $flag => $pattern) {
            if (@preg_match($pattern, $code)) {
                $flags[] = $flag;
            }
        }

        if (strpos($code, 'foreach') !== false && strpos($code, 'count(') !== false) {
            if (preg_match('/foreach.*count\(/', $code)) {
                $flags[] = 'count_in_loop';
            }
        }

        if (preg_match('/\->all\(\)|\->get\(\)->/', $code)) {
            $flags[] = 'potential_n1_query';
        }

        return $this->fileCache[$class] = $flags;
    }

    private function analyzeDependencies(string $class): array
    {
        try {
            $ref = new ReflectionClass($class);
            $methods = ['register', 'boot'];
            $dependencies = [];

            foreach ($methods as $method) {
                if ($ref->hasMethod($method)) {
                    $methodRef = $ref->getMethod($method);
                    $params = $methodRef->getParameters();

                    foreach ($params as $param) {
                        if ($type = $param->getType()) {
                            $typeName = $type instanceof \ReflectionNamedType
                                ? $type->getName()
                                : (string) $type;

                            if (class_exists($typeName) || interface_exists($typeName)) {
                                $dependencies[] = [
                                    'type' => $typeName,
                                    'name' => $param->getName(),
                                    'optional' => $param->isOptional(),
                                ];
                            }
                        }
                    }
                }
            }

            return $dependencies;
        } catch (\Throwable) {
            return [];
        }
    }

    private function calculateParallelTiming(array &$timings): void
    {
        $dependencyGraph = [];
        $providerTimes = [];

        foreach ($timings as $provider => $timing) {
            if (isset($timing['dependencies'])) {
                $deps = array_column($timing['dependencies'], 'type');
                $dependencyGraph[$provider] = $deps;
            }
            $providerTimes[$provider] = $timing['total_time'] ?? 0;
        }

        $sequentialTime = array_sum($providerTimes);
        $parallelTime = 0;

        foreach ($providerTimes as $provider => $time) {
            $deps = $dependencyGraph[$provider] ?? [];
            $maxDepTime = 0;

            foreach ($deps as $dep) {
                if (isset($providerTimes[$dep])) {
                    $maxDepTime = max($maxDepTime, $providerTimes[$dep]);
                }
            }

            $currentPath = $maxDepTime + $time;
            $parallelTime = max($parallelTime, $currentPath);
        }

        $speedup = $sequentialTime > 0 ? $sequentialTime / max($parallelTime, 0.0001) : 1;

        foreach ($timings as &$timing) {
            $timing['parallel_estimate'] = $parallelTime;
            $timing['sequential_time'] = $sequentialTime;
            $timing['potential_speedup'] = round($speedup, 2);
        }
    }

    private function sortTimings(array $timings): array
    {
        $sortBy = $this->option('sort') ?? config('profile-provider.sort', 'total');
        $sortField = match ($sortBy) {
            'register' => 'register_time',
            'boot' => 'boot_time',
            'memory' => 'total_memory',
            'parallel' => 'parallel_estimate',
            default => 'total_time',
        };

        uasort($timings, function ($a, $b) use ($sortField) {
            $aVal = $a[$sortField] ?? 0;
            $bVal = $b[$sortField] ?? 0;
            return $bVal <=> $aVal;
        });

        return $timings;
    }

    private function calculateStatistics(array $timings, float $threshold): array
    {
        $totalTimes = array_filter(array_column($timings, 'total_time'), fn($v) => $v !== null);
        $registerTimes = array_filter(array_column($timings, 'register_time'), fn($v) => $v !== null);
        $bootTimes = array_filter(array_column($timings, 'boot_time'), fn($v) => $v !== null);
        $memoryUsage = array_filter(array_column($timings, 'total_memory'), fn($v) => $v !== null);

        $stats = [
            'total_providers' => count($timings),
            'deferred_providers' => count(array_filter($timings, fn($t) => $t['is_deferred'] ?? false)),
            'successful_providers' => count(array_filter($timings, fn($t) => !isset($t['error']))),
            'failed_providers' => count(array_filter($timings, fn($t) => isset($t['error']))),
            'slow_providers' => array_filter($timings, fn($t) => ($t['total_time'] ?? 0) >= $threshold),
        ];

        if (!empty($totalTimes)) {
            $stats['total_time'] = array_sum($totalTimes);
            $stats['avg_time'] = array_sum($totalTimes) / count($totalTimes);
            $stats['median_time'] = $this->calculateMedian($totalTimes);
            $stats['percentiles'] = $this->calculatePercentiles($totalTimes, [50, 75, 90, 95, 99, 100]);
        }

        if (!empty($registerTimes)) {
            $stats['total_register_time'] = array_sum($registerTimes);
            $stats['avg_register_time'] = array_sum($registerTimes) / count($registerTimes);
        }

        if (!empty($bootTimes)) {
            $stats['total_boot_time'] = array_sum($bootTimes);
            $stats['avg_boot_time'] = array_sum($bootTimes) / count($bootTimes);
        }

        if ($this->option('memory') && !empty($memoryUsage)) {
            $totalMemory = array_sum($memoryUsage);
            $stats['total_memory_bytes'] = $totalMemory;
            $stats['total_memory_mb'] = round($totalMemory / 1024 / 1024, 2);
            $stats['avg_memory_kb'] = round($totalMemory / count($memoryUsage) / 1024, 2);
            $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        }

        return $stats;
    }

    private function calculateMedian(array $values): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $middle = floor(($count - 1) / 2);

        if ($count % 2) {
            return $values[$middle];
        }

        return ($values[$middle] + $values[$middle + 1]) / 2;
    }

    private function calculatePercentiles(array $values, array $percentiles): array
    {
        if (empty($values)) {
            return [];
        }

        sort($values);
        $count = count($values);
        $results = [];

        foreach ($percentiles as $p) {
            if ($p == 100) {
                $results[$p] = end($values);
                continue;
            }

            $index = (int) ceil(($p / 100) * $count) - 1;
            $index = max(0, min($index, $count - 1));
            $results[$p] = $values[$index];
        }

        return $results;
    }

    private function compareWithPreviousRun(array $currentTimings): void
    {
        $cacheKey = 'profile:providers:last_run';
        $previous = Cache::get($cacheKey);

        if (!$previous) {
            $this->info('No previous run found for comparison.');
            Cache::put($cacheKey, $currentTimings, now()->addHours(config('profile-provider.cache_ttl_hours', 24)));
            return;
        }

        $comparisons = [];
        foreach ($currentTimings as $provider => $timing) {
            if (isset($previous[$provider]) && isset($timing['total_time'])) {
                $prevTotal = $previous[$provider]['total_time'] ?? 0;
                $currTotal = $timing['total_time'];
                $delta = $currTotal - $prevTotal;

                if (abs($delta) >= self::SIGNIFICANT_CHANGE_THRESHOLD) {
                    $comparisons[$provider] = [
                        'previous' => $prevTotal,
                        'current' => $currTotal,
                        'delta' => $delta,
                        'percent' => $prevTotal > 0 ? ($delta / $prevTotal) * 100 : 0,
                    ];
                }
            }
        }

        if (!empty($comparisons)) {
            $this->info("\nComparison with previous run (top 10 most changed):");
            uasort($comparisons, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

            $rows = [];
            foreach (array_slice($comparisons, 0, 10) as $provider => $comp) {
                $color = $comp['delta'] > 0 ? 'red' : 'green';
                $sign = $comp['delta'] > 0 ? '+' : '';
                $percentSign = $comp['percent'] > 0 ? '+' : '';

                $rows[] = [
                    $this->formatProviderName($provider),
                    number_format($comp['previous'], 4),
                    number_format($comp['current'], 4),
                    "<fg={$color}>{$sign}" . number_format($comp['delta'], 4) . "</>",
                    "<fg={$color}>{$percentSign}" . number_format($comp['percent'], 1) . "%</>",
                ];
            }

            $this->table(['Provider', 'Previous', 'Current', 'Δ Time', 'Δ %'], $rows);
        } else {
            $this->info('No significant changes detected compared to previous run.');
        }

        Cache::put($cacheKey, $currentTimings, now()->addHours(config('profile-provider.cache_ttl_hours', 24)));
    }

    private function displayResults(array $timings, array $stats, float $threshold): void
    {
        $format = $this->option('format');

        if ($format === 'json') {
            $this->displayJson($timings, $stats);
            return;
        }

        if ($format === 'csv') {
            $this->displayCsv($timings);
            return;
        }

        $this->displayTable($timings, $stats, $threshold);
        $this->displaySummary($stats, $threshold, $timings);

        if (!$this->option('no-diagnostics')) {
            $this->displayRecommendations($timings, $threshold);
        }
    }

    private function displayTable(array $timings, array $stats, float $threshold): void
    {
        $rows = [];
        $top = (int) ($this->option('top') ?? config('profile-provider.top', 20));
        $showMemory = $this->option('memory');
        $showDiagnostics = !$this->option('no-diagnostics');

        foreach (array_slice($timings, 0, $top, true) as $provider => $t) {
            $totalTime = $t['total_time'] ?? 0;
            $registerTime = $t['register_time'] ?? 0;
            $bootTime = $t['boot_time'] ?? 0;
            $isDeferred = $t['is_deferred'] ?? false;

            $row = [
                $this->formatProviderName($provider),
                $this->formatDuration($registerTime, $threshold / 2),
                $this->formatDuration($bootTime, $threshold / 2),
                $this->formatDuration($totalTime, $threshold),
                $isDeferred ? 'DEFERRED' : '',
                $totalTime >= $threshold ? '<fg=red>SLOW</>' : '',
            ];

            if ($showMemory) {
                $row[] = round(($t['total_memory'] ?? 0) / 1024, 2) . ' KB';
            }

            if ($showDiagnostics) {
                $row[] = implode(', ', array_slice($t['diagnostics'] ?? [], 0, 3));
            }

            $row[] = $this->formatErrors($t);

            $rows[] = $row;
        }

        if (count($timings) > $top) {
            $rows[] = new TableSeparator();
            $rows[] = [
                sprintf('... and %d more', count($timings) - $top),
                '',
                '',
                '',
                '',
                '',
                ...($showMemory ? [''] : []),
                ...($showDiagnostics ? [''] : []),
                ''
            ];
        }

        $headers = ['Provider', 'Register(s)', 'Boot(s)', 'Total(s)', 'Type', 'Status'];

        if ($showMemory) {
            $headers[] = 'Memory';
        }

        if ($showDiagnostics) {
            $headers[] = 'Diagnostics';
        }

        $headers[] = 'Errors';

        $this->table($headers, $rows);
    }

    private function displaySummary(array $stats, float $threshold, array $timings): void
    {
        $this->newLine(2);
        $this->info('📊 SUMMARY STATISTICS');
        $this->line(str_repeat('─', 60));

        $summaryRows = [
            ['Total Providers', $stats['total_providers']],
            ['Successful', "<fg=green>{$stats['successful_providers']}</>"],
        ];

        if ($stats['failed_providers'] > 0) {
            $summaryRows[] = ['Failed', "<fg=red>{$stats['failed_providers']}</>"];
        }

        $summaryRows[] = ['Deferred', $stats['deferred_providers'] ?? 0];
        $summaryRows[] = [
            'Slow Providers (≥' . number_format($threshold, 3) . 's)',
            count($stats['slow_providers'] ?? [])
        ];
        $summaryRows[] = ['Total Boot Time', number_format($stats['total_time'] ?? 0, 4) . 's'];
        $summaryRows[] = ['Average per Provider', number_format($stats['avg_time'] ?? 0, 4) . 's'];
        $summaryRows[] = ['Median Time', number_format($stats['median_time'] ?? 0, 4) . 's'];

        if (isset($stats['total_register_time'])) {
            $summaryRows[] = ['Registration Time', number_format($stats['total_register_time'], 4) . 's'];
        }

        if (isset($stats['total_boot_time'])) {
            $summaryRows[] = ['Boot Time', number_format($stats['total_boot_time'], 4) . 's'];
        }

        if ($this->option('memory')) {
            $summaryRows[] = ['Total Memory', number_format($stats['total_memory_mb'] ?? 0, 2) . ' MB'];
            $summaryRows[] = ['Average Memory', number_format($stats['avg_memory_kb'] ?? 0, 2) . ' KB'];
            $summaryRows[] = ['Peak Memory', number_format($stats['peak_memory_mb'] ?? 0, 2) . ' MB'];
        }

        $first = reset($timings);
        if (isset($first['potential_speedup'])) {
            $summaryRows[] = ['Parallel Speedup Estimate', '~' . $first['potential_speedup'] . 'x'];
        }

        $this->table(['Metric', 'Value'], $summaryRows);

        if (isset($stats['percentiles'])) {
            $this->newLine();
            $this->info('📈 PERCENTILES (Total Time)');
            $percentileRows = [];
            foreach ($stats['percentiles'] as $p => $value) {
                $percentileRows[] = ["P{$p}", number_format($value, 6) . 's'];
            }
            $this->table(['Percentile', 'Value'], $percentileRows);
        }
    }

    private function displayRecommendations(array $timings, float $threshold): void
    {
        $slowProviders = array_filter($timings, fn($t) => ($t['total_time'] ?? 0) >= $threshold);
        $providersWithCountInLoop = array_filter(
            $timings,
            fn($t) => in_array('count_in_loop', $t['diagnostics'] ?? [])
        );

        $recommendations = [];

        if (count($slowProviders) > 5) {
            $recommendations[] = '⚡ Consider optimizing/deferring slow providers';
        }

        if (!empty($providersWithCountInLoop)) {
            $recommendations[] = '🔁 Found count() in loops - consider caching counts';
        }

        $deferredCount = count(array_filter($timings, fn($t) => $t['is_deferred'] ?? false));
        if ($deferredCount < count($timings) * 0.3) {
            $recommendations[] = '⏱️ Consider making more providers deferred if possible';
        }

        $totalTime = array_sum(array_column($timings, 'total_time'));
        $slowTime = array_sum(array_column($slowProviders, 'total_time'));

        if ($slowTime > $totalTime * 0.8) {
            $recommendations[] = '🎯 Focus optimization on top slow providers (Pareto principle)';
        }

        if (!empty($recommendations)) {
            $this->newLine();
            $this->info('💡 RECOMMENDATIONS');
            foreach ($recommendations as $rec) {
                $this->line("  • {$rec}");
            }
        }
    }

    private function displayJson(array $timings, array $stats): void
    {
        $output = [
            'metadata' => [
                'timestamp' => now()->toISOString(),
                'command' => $this->signature,
                'options' => $this->options(),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
            ],
            'statistics' => $stats,
            'providers' => $timings,
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function displayCsv(array $timings): void
    {
        $headers = ['Provider', 'Register(s)', 'Boot(s)', 'Total(s)', 'Deferred', 'Memory(KB)', 'Diagnostics', 'Errors'];
        $this->line(implode(',', array_map(fn($h) => '"' . $h . '"', $headers)));

        foreach ($timings as $provider => $t) {
            $row = [
                $provider,
                $t['register_time'] ?? 0,
                $t['boot_time'] ?? 0,
                $t['total_time'] ?? 0,
                ($t['is_deferred'] ?? false) ? 'yes' : 'no',
                $this->option('memory') ? round(($t['total_memory'] ?? 0) / 1024, 2) : 0,
                implode(';', $t['diagnostics'] ?? []),
                $this->formatErrors($t, false),
            ];

            $row = array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row);
            $this->line(implode(',', $row));
        }
    }

    private function exportResults(array $timings, string $format, string $path): void
    {
        $realPath = realpath(dirname($path)) . DIRECTORY_SEPARATOR . basename($path);
        $allowedPaths = [
            storage_path(),
            base_path('tests'),
            base_path('reports'),
            base_path('storage'),
        ];

        $isAllowed = false;
        foreach ($allowedPaths as $allowed) {
            if (str_starts_with($realPath, $allowed)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            $this->error('Export path must be within storage, tests, or reports directory');
            return;
        }

        $dir = dirname($realPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        switch ($format) {
            case 'json':
                $content = json_encode([
                    'metadata' => [
                        'timestamp' => now()->toISOString(),
                        'laravel_version' => app()->version(),
                    ],
                    'providers' => $timings,
                ], JSON_PRETTY_PRINT);
                break;
            case 'csv':
                $content = $this->convertToCsv($timings);
                break;
            default:
                $this->error("Unsupported export format: {$format}");
                return;
        }

        if (file_put_contents($realPath, $content) !== false) {
            $this->info("Results exported to: {$realPath}");
        } else {
            $this->error("Failed to export results to: {$realPath}");
        }
    }

    private function convertToCsv(array $timings): string
    {
        $headers = ['Provider', 'Register(s)', 'Boot(s)', 'Total(s)', 'Memory(KB)', 'Diagnostics', 'Errors'];
        $rows = [implode(',', array_map(fn($h) => '"' . $h . '"', $headers))];

        foreach ($timings as $provider => $t) {
            $row = [
                $provider,
                $t['register_time'] ?? 0,
                $t['boot_time'] ?? 0,
                $t['total_time'] ?? 0,
                $this->option('memory') ? round(($t['total_memory'] ?? 0) / 1024, 2) : 0,
                implode(';', $t['diagnostics'] ?? []),
                $this->formatErrors($t, false),
            ];

            $row = array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row);
            $rows[] = implode(',', $row);
        }

        return implode("\n", $rows);
    }

    private function formatProviderName(string $name): string
    {
        $shortName = class_basename($name);
        $maxLength = config('profile-provider.max_provider_name_length', 50);

        if (strlen($name) > $maxLength) {
            $namespace = substr($name, 0, strrpos($name, '\\') + 1);
            $shortened = substr($namespace, 0, 20) . '...' . $shortName;
            return strlen($shortened) > $maxLength ? $shortName : $shortened;
        }
        return $name;
    }

    private function formatDuration(float $seconds, float $warningThreshold): string
    {
        if ($seconds == 0) {
            return number_format($seconds, 6);
        }

        $formatted = number_format($seconds, 6);

        if ($seconds >= $warningThreshold * 3) {
            return "<fg=red;options=bold>{$formatted}</>";
        } elseif ($seconds >= $warningThreshold * 2) {
            return "<fg=red>{$formatted}</>";
        } elseif ($seconds >= $warningThreshold) {
            return "<fg=yellow>{$formatted}</>";
        }

        return $formatted;
    }

    private function formatErrors(array $timing, bool $color = true): string
    {
        $errors = collect(['error', 'register_error', 'boot_error'])
            ->map(fn($key) => $timing[$key] ?? null)
            ->filter()
            ->values()
            ->all();

        $errorStr = implode('; ', $errors);

        if ($color && !empty($errorStr)) {
            return "<fg=red>{$errorStr}</>";
        }

        return $errorStr;
    }
}
