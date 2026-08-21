# UI Theme Toolchain

**Status:** Accepted
**Date:** 2026-08-20

## Current need

The binding `ui.md` requires project-wide visual tokens and responsive overrides for
the Filament panel. A compiled Filament theme is therefore required by the current UI
alignment work.

## Decision

Use Filament 5's documented custom-theme path with Vite and Tailwind CSS. Blade and
Livewire remain the application UI layer; no SPA or frontend framework is introduced.

## Dependency assessment

| Item | Assessment |
|---|---|
| Why core alone is insufficient | Filament's runtime theme cannot compile project-owned Tailwind sources or purge custom utility classes. |
| Compatibility | Filament 5.7 uses Tailwind CSS 4; Laravel 13 supports the Laravel Vite plugin; the development runtime is Node 24. |
| Maintenance | Vite, Tailwind CSS, and the Laravel Vite plugin are actively maintained first-party project dependencies. |
| License | All added npm packages use the MIT license. |
| Security | Development/build-time dependencies only; production serves static versioned assets. |
| Custom code removed | Replaces direct loading of a hand-maintained public stylesheet and uses Filament's supported `viteTheme()` API. |
| Removal consequence | The theme can be compiled with another supported bundler and registered through `theme()`; Blade/Livewire behavior is unaffected. |

The committed npm lockfile pins the complete dependency graph. Installed dependency
source under `node_modules/` remains ignored and unmodified.
