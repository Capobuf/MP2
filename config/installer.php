<?php

use App\Installer\Callbacks\FinalizeInstallation;
use App\Installer\Callbacks\PromotePlatformAdmin;
use App\Installer\Steps\CheckRequirements;
use App\Installer\Steps\ConfigureEnvironment;
use App\Installer\Steps\ConfigureScheduler;
use App\Installer\Steps\PrepareDatabase;
use App\Installer\Steps\RunMigrations;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use RelayerCore\LaravelInstaller\Steps\CheckPermissions;
use RelayerCore\LaravelInstaller\Steps\CreateAdmin;

return [
    'name' => 'Master Plan IT',
    'logo' => null,
    'favicon' => 'favicon.ico',

    'requirements' => [
        'php_version' => '8.3',
        'extensions' => [
            'bcmath',
            'ctype',
            'dom',
            'fileinfo',
            'filter',
            'hash',
            'iconv',
            'intl',
            'json',
            'libxml',
            'mbstring',
            'openssl',
            'pcre',
            'pdo',
            'pdo_mysql',
            'session',
            'tokenizer',
            'xmlreader',
            'zip',
        ],
        'memory_limit' => '128M',
        'opcache' => false,
    ],

    'writable_directories' => [
        'storage/app',
        'storage/framework',
        'storage/logs',
        'bootstrap/cache',
    ],

    'steps' => [
        CheckRequirements::class,
        CheckPermissions::class,
        ConfigureEnvironment::class,
        PrepareDatabase::class,
        RunMigrations::class,
        CreateAdmin::class,
        ConfigureScheduler::class,
    ],

    'admin_model' => User::class,
    'on_admin_created' => PromotePlatformAdmin::class,
    'environment_fields' => [
        'APP_URL' => [
            'type' => 'text',
            'label' => 'URL pubblico',
            'default' => env('APP_URL', ''),
            'state_key' => 'app_url',
        ],
    ],
    'seeder' => DatabaseSeeder::class,
    'installed_file' => storage_path('installed'),

    'theme' => [
        'primary' => '#39d5c4',
        'primary_dark' => '#5ce5d4',
    ],

    'after_install' => FinalizeInstallation::class,
    'redirect_after_install' => '/admin/login',
];
