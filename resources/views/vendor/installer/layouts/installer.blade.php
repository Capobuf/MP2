<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('installer::installer.title', ['name' => config('installer.name')]) }}</title>
    <link rel="icon" href="{{ asset(config('installer.favicon', 'favicon.ico')) }}">
    <link rel="stylesheet" href="{{ asset('installer/installer.css') }}">
    <style>
        :root {
            --theme-primary: {{ config('installer.theme.primary') }};
            --theme-primary-dark: {{ config('installer.theme.primary_dark') }};
        }

        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; background: #f1f5f9; }
        [x-cloak] { display: none !important; }
        .installer-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .installer-card { width: 90%; max-width: 1920px; min-height: 85vh; display: flex; flex-direction: column; background: #fff; border-radius: 1.5rem; overflow: hidden; }
        .installer-sidebar { width: 100%; display: flex; flex-direction: column; justify-content: space-between; padding: 2.5rem; background: #0b1d25; color: #fff; }
        .installer-content { flex: 1; display: flex; flex-direction: column; padding: 2rem; }
        @media (min-width: 768px) {
            .installer-card { flex-direction: row; }
            .installer-sidebar { width: 350px; }
            .installer-content { padding: 3rem; }
        }
    </style>
    @livewireStyles
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
