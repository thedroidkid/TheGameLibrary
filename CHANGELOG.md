# Changelog

## v1.4 - 2026-10-20

- Updated the app version in Settings and deployment examples to **v1.4**.
- Bulk import now marks and skips duplicate title/platform pairs, including repeats within the file, and rechecks duplicates on the server before saving.
- Vertically centered the text-import file picker button and filename within the input field.
- Fixed bulk import to start with "Select a platform" instead of inheriting the manual entry default.
- Added a game-title preview and a "Confirm import" action before saving an imported list.

## 2026-10-19

- Added bulk import from a `.txt` file with one game title per line and a platform selection applied to every imported game.

## 2026-10-16

- Added a game details dialog, opened by clicking a game's title or cover, with full description and notes plus an edit action.
- Reorganized the add-game dialog into **Online Search** and **Manual entry** sections, with online search at the top.
- Added a platform filter to the main library page. Its options are populated from platforms in the library and work alongside title and status filters.
- Added an **Export CSV** button to download the full library, including game details and cover paths, regardless of active filters. CSV values are escaped and spreadsheet-formula injection is mitigated.
- Updated the app version in Settings to **v1.3**.
