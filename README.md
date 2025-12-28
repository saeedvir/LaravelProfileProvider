# Laravel Profile Provider

[![Latest Version on Packagist](https://img.shields.io/packagist/v/saeedvir/laravel-profile-provider.svg?style=flat-square)](https://packagist.org/packages/saeedvir/laravel-profile-provider)
[![Total Downloads](https://img.shields.io/packagist/dt/saeedvir/laravel-profile-provider.svg?style=flat-square)](https://packagist.org/packages/saeedvir/laravel-profile-provider)
[![License](https://img.shields.io/packagist/l/saeedvir/laravel-profile-provider.svg?style=flat-square)](https://github.com/saeedvir/LaravelProfileProvider/blob/main/LICENSE)

A powerful Laravel package to profile and analyze service provider performance with detailed timing, memory usage, and diagnostic analysis.

## Features

- 📊 Detailed timing for registration and boot phases
- 💾 Memory usage tracking
- 🚦 Diagnostic analysis for common performance issues
- 📈 Comparison with previous runs
- 🔄 Parallel boot time estimation
- 📤 Export results in multiple formats (JSON, CSV)
- 🎨 Color-coded output highlighting slow providers
- 🔍 Dependency analysis

## Installation

Require the package via Composer:

```bash
composer require saeedvir/laravel-profile-provider --dev