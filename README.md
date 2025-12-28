# Laravel Profile Provider

[![Latest Version on Packagist](https://img.shields.io/packagist/v/saeedvir/laravel-profile-provider.svg?style=flat-square)](https://packagist.org/packages/saeedvir/laravel-profile-provider)
[![Total Downloads](https://img.shields.io/packagist/dt/saeedvir/laravel-profile-provider.svg?style=flat-square)](https://packagist.org/packages/saeedvir/laravel-profile-provider)
[![License](https://img.shields.io/packagist/l/saeedvir/laravel-profile-provider.svg?style=flat-square)](https://github.com/saeedvir/LaravelProfileProvider/blob/main/LICENSE)

A powerful Laravel package to profile and analyze service provider performance with detailed timing, memory usage, and diagnostic analysis. Identify bottlenecks in your Laravel application's boot process and optimize service provider performance.

## Features

- 📊 **Detailed Timing Analysis** - Separate timing for registration and boot phases
- 💾 **Memory Usage Tracking** - Monitor memory consumption per provider
- 🚦 **Diagnostic Analysis** - Detect common performance issues (filesystem operations, HTTP calls, database queries, etc.)
- 📈 **Comparison Mode** - Compare current run with previous runs to track performance changes
- 🔄 **Parallel Boot Estimation** - Estimate potential speedup with parallel provider loading
- 📤 **Multiple Export Formats** - Export results in JSON, CSV, or formatted tables
- 🎨 **Color-Coded Output** - Visual highlighting of slow providers and performance issues
- 🔍 **Dependency Analysis** - Analyze provider dependencies and their impact
- ⚡ **Performance Recommendations** - Get actionable suggestions to optimize your application
- 🎯 **Deferred Provider Detection** - Identify which providers are deferred and which should be

## Requirements

- PHP 8.1 or higher
- Laravel 10.x, 11.x, or 12.x

## Installation

Install the package via Composer:

```bash
composer require saeedvir/laravel-profile-provider --dev
```

The package will automatically register its service provider.

## Configuration

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --provider="Saeedvir\LaravelProfileProvider\LaravelProfileProviderServiceProvider"
```

This will create a `config/profile-provider.php` file with the following options:

```php
return [
    // Default threshold in seconds for marking providers as slow
    'threshold' => 0.01,

    // Default number of top slowest providers to display
    'top' => 20,

    // Default field to sort providers by (total, register, boot, memory)
    'sort' => 'total',

    // How many hours to keep previous run data for comparison
    'cache_ttl_hours' => 24,

    // Maximum length for provider names in output tables
    'max_provider_name_length' => 50,
];
```

## Usage

### Basic Usage

Run the profiler with default settings:

```bash
php artisan profile:providers
```

This will display a table showing all service providers with their registration time, boot time, total time, and diagnostic information.

### Common Use Cases

#### 1. Find the Slowest Providers

Show only the top 10 slowest providers:

```bash
php artisan profile:providers --top=10
```

#### 2. Track Memory Usage

Include memory consumption analysis:

```bash
php artisan profile:providers --memory
```

#### 3. Compare Performance Over Time

Compare current run with the previous run to track performance changes:

```bash
php artisan profile:providers --compare
```

#### 4. Export Results

Export results to a JSON file for further analysis:

```bash
php artisan profile:providers --format=json --export=storage/profiling/results.json
```

Export to CSV:

```bash
php artisan profile:providers --format=csv --export=storage/profiling/results.csv
```

#### 5. Focus on Specific Threshold

Only show providers slower than 50ms:

```bash
php artisan profile:providers --threshold=0.05
```

#### 6. Sort by Different Metrics

Sort by registration time:

```bash
php artisan profile:providers --sort=register
```

Sort by boot time:

```bash
php artisan profile:providers --sort=boot
```

Sort by memory usage:

```bash
php artisan profile:providers --sort=memory --memory
```

#### 7. Estimate Parallel Boot Performance

See potential speedup with parallel provider loading:

```bash
php artisan profile:providers --parallel
```

#### 8. Dry Run (Simulation)

Simulate profiling without actual execution (useful for testing):

```bash
php artisan profile:providers --dry-run
```

#### 9. Profile Cached Providers

Profile only providers from the bootstrap cache:

```bash
php artisan profile:providers --use-cache
```

Or profile exclusively cached providers:

```bash
php artisan profile:providers --only-cached
```

#### 10. Skip Diagnostics

For faster profiling without diagnostic analysis:

```bash
php artisan profile:providers --no-diagnostics
```

### Advanced Usage Examples

#### Complete Performance Analysis

```bash
php artisan profile:providers \
    --top=15 \
    --threshold=0.01 \
    --memory \
    --compare \
    --parallel \
    --export=storage/profiling/analysis-$(date +%Y%m%d).json
```

#### Quick Check for Slow Providers

```bash
php artisan profile:providers --top=5 --threshold=0.1 --no-diagnostics
```

#### Detailed Analysis with All Features

```bash
php artisan profile:providers \
    --memory \
    --compare \
    --parallel \
    --sort=total \
    --format=table
```

## Available Options

| Option | Description | Default |
|--------|-------------|---------|
| `--top=N` | Show top N slowest providers | 20 |
| `--threshold=N` | Mark providers slower than N seconds | 0.01 |
| `--sort=FIELD` | Sort by: total, register, boot, memory | total |
| `--format=FORMAT` | Output format: table, json, csv | table |
| `--export=PATH` | Save results to file | - |
| `--compare` | Compare with previous run | false |
| `--memory` | Include memory usage tracking | false |
| `--no-diagnostics` | Skip diagnostic analysis | false |
| `--use-cache` | Read providers from bootstrap cache | false |
| `--dry-run` | Simulate profiling without execution | false |
| `--only-cached` | Profile only cached providers | false |
| `--parallel` | Estimate parallel boot timing | false |

## Understanding the Output

### Table Output

The default table output includes:

- **Provider**: The fully qualified class name of the service provider
- **Register(s)**: Time taken during the registration phase
- **Boot(s)**: Time taken during the boot phase
- **Total(s)**: Combined registration and boot time
- **Type**: Shows "DEFERRED" if the provider is deferred
- **Status**: Shows "SLOW" if the provider exceeds the threshold
- **Memory**: Memory consumption (when `--memory` is used)
- **Diagnostics**: Detected performance patterns (filesystem, http, database, etc.)
- **Errors**: Any errors encountered during profiling

### Summary Statistics

The summary section provides:

- Total number of providers
- Successful vs failed providers
- Number of deferred providers
- Count of slow providers
- Total boot time
- Average and median times
- Memory statistics (when enabled)
- Percentile distribution (P50, P75, P90, P95, P99, P100)

### Diagnostic Patterns

The profiler detects the following patterns:

- **filesystem**: File operations (File::allFiles, glob, Storage)
- **http**: HTTP requests (Http, GuzzleHttp, curl)
- **config**: Configuration access
- **container**: Container bindings and resolutions
- **database**: Database queries (DB, Eloquent, Schema)
- **cache**: Cache operations
- **event**: Event listeners and dispatchers
- **queue**: Queue operations
- **broadcast**: Broadcasting operations
- **mail**: Mail operations
- **notification**: Notification operations
- **session**: Session operations
- **validation**: Validation operations
- **view**: View operations
- **route**: Route operations
- **auth**: Authentication operations
- **log**: Logging operations
- **redis**: Redis operations
- **artisan**: Artisan command operations
- **count_in_loop**: Performance anti-pattern
- **potential_n1_query**: Potential N+1 query issues

### Recommendations

The profiler provides actionable recommendations such as:

- ⚡ Consider optimizing/deferring slow providers
- 🔁 Found count() in loops - consider caching counts
- ⏱️ Consider making more providers deferred if possible
- 🎯 Focus optimization on top slow providers (Pareto principle)

## Practical Examples

### Example 1: Identifying Slow Providers in Production

```bash
# Profile with memory tracking and export results
php artisan profile:providers \
    --memory \
    --threshold=0.05 \
    --export=storage/logs/provider-profile.json
```

### Example 2: Continuous Performance Monitoring

```bash
# Compare with previous run to track regressions
php artisan profile:providers \
    --compare \
    --top=20 \
    --memory
```

### Example 3: Optimizing Application Boot Time

```bash
# Get detailed analysis with parallel estimation
php artisan profile:providers \
    --parallel \
    --memory \
    --sort=total \
    --top=10
```

### Example 4: Debugging Specific Performance Issues

```bash
# Focus on providers with database operations
php artisan profile:providers \
    --memory \
    --format=json | jq '.providers | to_entries[] | select(.value.diagnostics | contains(["database"]))'
```

## Best Practices

1. **Run in Development**: This package is intended for development and should be installed with `--dev`
2. **Regular Profiling**: Profile your application regularly to catch performance regressions early
3. **Compare Runs**: Use `--compare` to track performance changes over time
4. **Focus on Top Offenders**: Use `--top=10` to focus on the most impactful providers
5. **Export Results**: Export results for historical tracking and analysis
6. **Consider Deferring**: Look for providers that can be deferred to speed up boot time
7. **Memory Tracking**: Use `--memory` when investigating memory issues
8. **Parallel Estimation**: Use `--parallel` to understand potential speedup opportunities

## Performance Tips

Based on the profiler's output, consider these optimization strategies:

1. **Defer Providers**: Make providers deferred when their services aren't needed on every request
2. **Lazy Loading**: Avoid loading resources during registration/boot that can be loaded on-demand
3. **Cache Configuration**: Cache configuration values instead of reading files repeatedly
4. **Optimize Database Queries**: Move database queries out of the boot process
5. **Avoid HTTP Calls**: Never make HTTP requests during provider registration/boot
6. **Minimize File Operations**: Reduce filesystem operations during boot
7. **Use Singleton Bindings**: Use singleton bindings for services that should be instantiated once

## Troubleshooting

### No Providers Found

If you see "No providers found", ensure:
- Your application has registered providers in `config/app.php` or `bootstrap/providers.php`
- You're running the command from your Laravel application root
- The cache is cleared: `php artisan config:clear`

### High Memory Usage

If profiling causes high memory usage:
- Use `--no-diagnostics` to skip diagnostic analysis
- Use `--top=N` to limit the number of providers analyzed
- Profile in batches using `--only-cached` and without cache

### Inaccurate Timings

For more accurate timings:
- Run the profiler multiple times and compare results
- Avoid running other processes during profiling
- Use `--compare` to track trends rather than absolute values

## Testing

Run the test suite:

```bash
composer test
```

Run tests with coverage:

```bash
composer test-coverage
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Security

If you discover any security-related issues, please email saeed.es91@gmail.com instead of using the issue tracker.

## Credits

- [Saeed Abdollahian](https://github.com/saeedvir)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Support

If you find this package helpful, please consider:
- ⭐ Starring the repository
- 🐛 Reporting bugs
- 💡 Suggesting new features
- 📖 Improving documentation
- 🔀 Contributing code

## Related Packages

- [Laravel Debugbar](https://github.com/barryvdh/laravel-debugbar) - Debug bar for Laravel
- [Laravel Telescope](https://laravel.com/docs/telescope) - Debug assistant for Laravel
- [Clockwork](https://github.com/itsgoingd/clockwork) - Development tools for PHP