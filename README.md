# Glitchlet

Glitchlet is a lightweight, browser-based creative-coding environment (HTML/CSS/JS only). Think "Glitch-lite" for shared hosting: students edit multi-file projects in the browser with instant preview, save locally via IndexedDB, and later publish to a class projects folder.

## Highlights

- Multi-file editor with a file tree and live preview
- Local-first persistence (autosave to IndexedDB)
- ZIP import/export for templates and backups
- Static front end (no Node/React required)
- Account-based publishing with manager + editor roles
- Manager console for accounts, projects, and email setup links

## Status

Frontend editor scaffold is in place. Server-side publishing (through a PHP-endpoint) also works.

## Install via browser (recommended)

1) Upload the project files to your document root.
2) Visit `/install.php` in the browser.
3) Enter DB + SMTP details and create the manager account.
4) The installer writes `publish/config.php`, creates `/projects`, and runs `publish/schema.sql`.
5) The installer writes `install.lock`. Delete `install.php` or keep it locked.

## Getting started (shared hosting)

These instructions assume a typical shared host (e.g., Reclaim Hosting) with PHP enabled.

1) Create a subdomain in your hosting control panel (e.g., `glitchlet.yoursite.net`).
2) Upload the project files into the subdomain document root (e.g., `~/glitchlet.yoursite.net/`).
3) Create a `/projects` directory inside that document root.
4) Configure a MySQL database and run `publish/schema.sql` (includes users/projects/password resets).
5) Copy `publish/config.php.template` to `publish/config.php` and set DB credentials + app URLs (or use env vars), or use `/install.php`.
6) (Optional) Configure SMTP for Gmail with app password (see config values).
7) Set `GLITCHLET_BOOTSTRAP_TOKEN` and create the first manager via `/publish/bootstrap.php?token=...` (or use `/install.php`).
8) Confirm PHP is enabled for the `/publish` folder (a simple `test.php` should run).
9) Open the app at your subdomain, sign in, and publish a test project.

## Quick start: manager + SMTP

1) Set `GLITCHLET_BOOTSTRAP_TOKEN` in `publish/config.php`.
2) Configure SMTP in `publish/config.php` (`SMTP_ENABLED`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`).
3) Visit `/publish/bootstrap.php?token=...` and create the manager account (or use `/install.php`).
4) Open `/publish/manager.php`, create editor accounts, and verify the email links.

## Local development (optional)

If you want to run it locally:

```sh
python3 -m http.server
```

Then visit `http://localhost:8000`.

## Install checklist (shared hosting)

- Create a MySQL database and user.
- Run `publish/schema.sql` against the database.
- Confirm the `/projects` folder exists and is writable by PHP.
- Set DB creds + `GLITCHLET_BOOTSTRAP_TOKEN` in `publish/config.php` or environment variables.
- If using email setup links, configure `GLITCHLET_SMTP_*` values in `publish/config.php`.
- Create the manager account via `/publish/bootstrap.php?token=...`.
- Ensure PHP is enabled for the `/publish` folder.
- Optionally use `/install.php` to automate these steps.

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

## Roadmap

- PHP publishing endpoint + token auth
- Project manager (list, rename, delete)
- Instructor template catalog

## License

MIT. See `LICENSE`.
