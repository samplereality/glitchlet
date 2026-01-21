# Glitchlet notes

## Overview
- Glitchlet is a browser-based HTML/CSS/JS editor with live preview, multi-file tree, project manager, and PHP publishing.
- Frontend lives in `index.html`, `assets/styles.css`, and `assets/app.js`.
- Publishing + admin dashboards live in `publish/`.

## Key UI features
- File manager: collapsible pane, rename files, add folders, drag/drop into/out of folders, delete files/folders.
- Editor: CodeMirror 5 with syntax highlighting, bracket/tag auto-close, folding + fold gutter, search dialog.
- Theme toggles: main theme auto/light/dark, editor theme light/dark, line-wrap toggle.
- Modals: reusable dialog modal replaces alerts/prompts/confirm; project metadata modal; new project modal; publish modal.

## File tree and folders
- Folders are implemented with a hidden `.keep` marker file.
- Tree view is built from file paths; collapsible folders in `renderFileTree()`.
- Drag files into folders (drop on folder row), drag out by dropping on file tree background.
- `.keep` files are hidden and skipped when publishing.

## Publish flow (frontend)
- `publishProject()` sends a zip to `PUBLISH_ENDPOINT` with name/creator/description.
- On success, publish modal shows URL and per-project admin password with copy buttons.
- Import supports `.zip` and `.tgz/.tar.gz` (pako + untar-sync).

## Publish flow (backend)
- `publish/publish.php` validates and extracts zip, generates word-based slug, injects Remix/Admin FABs, writes `project.json`.
- Admin password per project stored in `../private/project_auth.json` (outside web root).
- Response JSON includes `adminPassword` for the publish modal.

## Admin dashboards
- Per-project admin: `projects/{slug}/admin.php` (generated from `publish/admin_template.php`).
- Global admin: `projects/admin.php` (generated from `publish/projects_admin_template.php`).
- Global admin can archive/restore/delete and reset per-project passwords (shows once).

## Wayfinding
- Main app has a "Published Projects" button (link) and "Project Manager".
- Published projects index has an Admin button (global admin) + App button.
- Global admin has Projects + App buttons.
- Individual project pages have Admin + Remix only.

## Shared hosting setup
- Create a subdomain doc root for the app (not necessarily `public_html`).
- Create `/projects` inside the doc root.
- Create `~/private/` outside web root for `project_auth.json`.
- Ensure PHP can write to `/projects` and `~/private/`.
- Set `ADMIN_PASSWORD_HASH` in `publish/publish.php`.

## Notable paths and constants
- `assets/app.js`
  - `PUBLISH_ENDPOINT` points to `https://glitchlet.digitaldavidson.net/publish/publish.php`.
  - `LINE_WRAP_KEY`, `THEME_STORAGE_KEY`, `EDITOR_THEME_KEY` in localStorage.
- `publish/publish.php`
  - `AUTH_STORE_PATH` = `../private/project_auth.json`.
  - `APP_URL` and `PROJECT_URL_BASE` for wayfinding.
  - Word lists for slug generation.

## Known gotchas
- Any hidden files in publish zip are rejected by server; `.keep` skipped on zip build.
- Individual project `admin.php` must be regenerated if templates change.
- `projects/admin.php` and `projects/index.html` are regenerated on publish.

## TODO ideas
- Persist folder collapse state in localStorage.
- Add folder icons in tree and rename folders.
- Add “regenerate admin pages” helper script.
