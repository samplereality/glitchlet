# Glitchlet

Glitchlet is a lightweight, browser-based creative-coding environment (HTML/CSS/JS only). Think "Glitch-lite" for shared hosting: students edit multi-file projects in the browser with instant preview, save locally via IndexedDB, and later publish to a class projects folder.

## Highlights

- Multi-file editor with a file tree and live preview
- Local-first persistence (autosave to IndexedDB)
- ZIP import/export for templates and backups
- Static front end (no Node/React required)
- Account-based publishing with manager + editor roles
- Manager console for accounts, projects, and email setup links

## Install via browser (shared hosting)

These instructions assume a typical shared host (e.g., Reclaim Hosting) with PHP enabled.

1) Create a subdomain in your hosting control panel (e.g., `glitchlet.yoursite.net`). Make sure you do NOT select the "Share Document Root" option.
2) Upload the project files into the subdomain document root (e.g., `~/glitchlet.yoursite.net/`).
3) Configure a MySQL database and add a new user with ALL privileges. On cPanel, you can do both in the MySQL Database wizard. Note the new database name, database user, and database user password.
4) Generate an app password for your Gmail account. Because Google keeps this option buried, the easiest way to find it is to log into your Google Account and search for `app password`. Take note of this app-specific password.
5) Now visit `/install.php` to complete the installation.


## Project structure

- `index.html` - app shell
- `assets/styles.css` - UI styling
- `assets/app.js` - editor logic, preview rendering, persistence, ZIP flows
- `publish/manager.php` - manager console (accounts/projects)
- `publish/projects.php` - per-user published projects dashboard

## Notes

- ZIP import/export uses JSZip from a CDN by default. If you need fully offline hosting, download JSZip locally and update `ensureJSZip()` in `assets/app.js`.
- Account emails use SMTP settings in `publish/config.php` (Gmail app passwords supported).
- Keep `publish/config.php` private. It is ignored by git; do not commit SMTP or DB credentials.
- `/publish/bootstrap.php` is disabled when `install.lock` exists.

## Versioning

- Version lives in `VERSION` and is exposed to the app via `assets/version.js`.
- Bump with `scripts/bump-version.sh`:
  - `scripts/bump-version.sh patch` (or `minor` / `major`)
  - `scripts/bump-version.sh 1.2.3` to set an explicit version.

## Tutorial mode

- Use the Tutorial button in the top bar to toggle hover tips for the main controls and panes.

## Starter template

- Place a `starter_template.zip` file at the site root to define the default project that loads on first visit.
- Replace the zip contents to customize the starter project for your install.

## Roadmap

- Project manager (list, rename, delete)
- Instructor template catalog

## License

MIT. See `LICENSE`.
