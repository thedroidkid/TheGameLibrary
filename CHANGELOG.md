# Changelog

## v1.6 - 2026-10-21

- Added multiple genres per game, entered manually (separated by `|` or commas) or filled in from TheGamesDB search results.
- Genres appear as tags on game cards and in the details dialog, and are included in search and CSV export.
- Added a genre filter to the main library page.
- Added **Select games** mode to pick multiple games (or all games matching the current filters) and delete them in one step after confirmation.
- The platform is now required when adding a game and starts on "Select a platform" instead of PlayStation 5.
- Added in-app Settings support to the Python server for the TheGamesDB API key and shared accent color, stored in `.thegamesdb-key` and `accent-color`.
- Improved the game grid so cards align and covers of different shapes (such as N64 and Game Boy boxes) display at their natural proportions.
- Allowed the library header buttons to wrap on narrow screens.
- Fixed "Find cover" failing with a `searchForCovers is not defined` error.
- Updated the app version in Settings and deployment examples to **v1.6**.

## v1.5 - 2026-10-20

- Replaced browser-based ROM folder import with `.txt` file import: one game title per line, with a selected platform applied to the entire list.
- Updated the app version in Settings and deployment examples to **v1.5**.
- Bulk import now marks and skips duplicate title/platform pairs, including repeats within the file, and rechecks duplicates on the server before saving.
- Vertically centered the text-import file picker button and filename within the input field.
- Fixed bulk import to start with "Select a platform" instead of inheriting the manual entry default.
- Added a game-title preview and a "Confirm import" action before saving an imported list.

## v1.4 - 2026-10-18

- Added browser-based ROM folder import. Choose a platform, review filename-based game title suggestions, and select which games to add.
- Detects likely duplicates already in the library or repeated within the selected folder, and skips them by default.
- Scans filenames only; ROM contents are not read or uploaded, and ROM folders do not need to be mounted into Docker.
- Centered the folder picker and show the selected folder name.
- Updated the app version in Settings to **v1.4**.

## v1.3 - 2026-10-16

- Added a game details dialog, opened by clicking a game's title or cover, with full description and notes plus an edit action.
- Reorganized the add-game dialog into **Online Search** and **Manual entry** sections, with online search at the top.
- Added a platform filter to the main library page. Its options are populated from platforms in the library and work alongside title and status filters.
- Added an **Export CSV** button to download the full library, including game details and cover paths, regardless of active filters. CSV values are escaped and spreadsheet-formula injection is mitigated.
- Updated the app version in Settings to **v1.3**.
