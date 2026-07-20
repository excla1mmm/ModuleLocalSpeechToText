# STT Worker Download Segment Design

## Goal

Add a permanent download entry point for the macOS STT Worker at the top of the `Workers` tab without importing the AI Supervisor card design.

## Visual Design

The download entry is a normal Semantic UI `ui segment`, placed immediately before the existing worker API-key segment. It uses the same `ui stackable grid`, `ui header`, muted helper text, blue labeled action button, spacing, border, and small-radius styling already present in ModuleLocalSpeechToText.

The segment contains:

- the standard Semantic UI download icon;
- the title `Программа обработчика`;
- the description `Установите macOS STT Worker для локального распознавания записей.`;
- compact metadata `macOS · Apple Silicon · Источник: files.miko.ru`;
- the action `Скачать для macOS`;
- the disabled-state note `Ссылка появится после публикации` while no verified installer URL exists.

No gradient, promotional eyebrow, custom app icon, oversized radius, or AI Supervisor color token is introduced. On narrow screens the action becomes left-aligned and full-width through module-scoped CSS.

## Localization

Russian is the source catalog. English contains native English copy. The other 27 catalogs receive the English fallback values so all 29 catalogs retain exact key parity.

## Verification

A focused PHP UI contract verifies placement, Semantic UI classes, the disabled unpublished state, module-scoped responsive CSS, exact Russian and English copy, and catalog key parity. The full module contract suite and PHP syntax checks validate integration.
