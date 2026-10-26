# Changelog

All notable changes to the Website Updates Report plugin are listed here.

## 1.1.0 — October 26, 2026

### Changed
- **Gallery Pages now track BildeGallery instead of FileBird.** New gallery folders, renames, moves and deletions are recorded as they happen. This requires BildeGallery 1.2.0 or higher.
  - Activity recorded while FileBird was in use still appears in reports for those dates.
  - If BildeGallery isn't active, the Gallery Pages section says so instead of showing an empty list.
- **The sync button is now "Sync Existing Gallery Folders."** It adds BildeGallery folders that aren't in the report log yet.
  - Folders imported from FileBird are skipped, so the import doesn't show up as new gallery pages.
  - Folders created with BildeGallery 1.2.0 or higher use their real creation date and author. Older folders are dated by their oldest image, as before.

### Added
- **A Change column in the Gallery Pages Updated table**, showing what changed, for example: Renamed from "Gala", or Moved from Events › 2025. Updates recorded under FileBird show a dash, since FileBird didn't record what changed.

## 1.0.8 — October 24, 2026

### Added
- **Edit history for pages, posts, and projects.** The New and Updated tables in each of these sections now have two new columns:
  - **Last Edited By**: the person who most recently saved changes. The existing Author column still shows who originally created the item.
  - **Edits**: how many times the item was edited during the report period, plus its total edits over its full history.
- Edit counts come from the revisions WordPress saves. Autosaves are not counted. If the site limits or removes old revisions, the counts reflect only the revisions still kept.
- These columns also appear in emailed and scheduled reports.
- **Plugin, theme, and WordPress update tracking.** The plugin now records every update as it happens: what was updated, the old and new version, who ran it, and when. Updates WordPress installs on its own are labeled "Automatic update."
  - The report's updates section now lists these updates, replacing the list of currently active plugins.
  - The summary shows how many plugins, themes, and WordPress updates happened during the period.
  - Only updates made after installing version 1.0.8 are included. Earlier updates weren't recorded and can't be recovered.
- **Updates from GitHub.** New versions are now delivered from GitHub releases and appear on the Plugins and Updates screens like any other plugin update, with no setup needed. The plugin is not distributed through WordPress.org.

### Changed
- Now requires WordPress 5.8 or higher.

### Fixed
- Scheduled report emails now use the same styled layout as reports emailed from the report screen.
- Updated the plugin's website link on the Plugins screen.

## Earlier versions (1.0.0 – 1.0.7)

These versions built the plugin's core reporting features:

### Reporting
- Activity reports for any custom date range (defaults to the last 30 days).
- Choose which sections to include in each report:
  - **Pages**: new pages and updated pages
  - **Projects**: new and updated Divi projects, with their categories
  - **Posts**: new and updated posts, with their categories
  - **Media**: images, videos, and documents uploaded
  - **Gallery Pages**: FileBird gallery folders created, updated, and deleted, with the full folder path and who made the change
  - **New Users**: accounts added during the period, with role and registration date
  - **Plugins**: active plugins with version and author
- A summary panel with counts for each section.
- Detailed tables with View and Edit links for each item.

### Sharing
- Export to PDF, or print, from the report screen.
- Email a report straight from the report screen.
- **Scheduled reports**: automatically email a report covering the previous 14 days, every other Monday at 8:00 AM (site time). Supports multiple recipients separated by commas.

### Gallery tracking
- Logs FileBird folder activity as it happens.
- A **Sync FileBird Folders** tool adds existing folders to the activity log so they can appear in reports.

### General
- Version number shown on the report screen.
- Responsive layout for desktop, tablet, and mobile.
