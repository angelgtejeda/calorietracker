# Calorie Tracker

`calorietracker.html` is the tracker. `calorie-api.php` provides private NAS storage with record-level synchronization (PHP 8.0+). Keep the site private.

## Deploy the sync fix

1. Close tracker tabs on all devices. Export device JSON and download the NAS backup before changing or loading data. Keep both copies even if their record counts differ.
2. Preserve `/volume1/leadville-private/calorie-tracker.json` and the entire `calorie-backups` directory. **Do not replace the data file with a device export or demo data.** The API update does not require changing the database.
3. Copy `calorie-config.example.php` to `/volume1/leadville-private/calorie-config.php`, outside the website. Set `token` to a **new random secret** (24+ characters). The previously uploaded API contained a token; rotate it. The private file must be readable by the PHP service, and the data folder writable by that service. Keep the populated configuration out of GitHub. Alternatively, configure `CALORIE_TRACKER_TOKEN` and `CALORIE_TRACKER_DATA_DIR` in the PHP service environment.
4. Deploy **both** `calorie-api.php` and `calorietracker.html` to the existing website directory, with the API beside the HTML. Deploy the API first: it rejects whole-dataset saves from old open pages. The updated HTML refuses writes to an old API. Other site files need no changes.
5. Hard-refresh each device and enter the new token in NAS Setup. Export any unsaved device edits before using **Load NAS to device**. Review the NAS copy first; if it already lost records, recover a reviewed backup before resuming edits. Do not load an incomplete NAS copy over the only good device copy.
6. Once the correct NAS data is loaded, edits automatically save record changes. Wait for **NAS connected • record auto-save ON** after saving. A returning device with unsynced edits pauses for review; **Save device changes to NAS** can submit changes only when a valid protocol-2 baseline exists. A new or legacy device must back up its local copy and load NAS to establish that baseline.

GitHub updates do **not** deploy to the NAS automatically. This setup has been tested in an isolated PHP server, not against your live NAS.

## Save and recovery behavior

- Each device keeps a local copy. Reads and reconnect checks never upload local changes.
- Saves submit goal-field changes and record upserts/deletions, not a replacement dataset. The server resolves the device's baseline from its own revision history, verifies the baseline tag, and merges those changes under a file lock. Records added on another device remain intact.
- Concurrent changes to different records or goal fields merge. Competing edits to the same record, including delete-versus-edit, return HTTP 409. The entire request is rejected; device edits remain local.
- Records missing from an old device but absent from its baseline are **not deletions**. Intentional deletes affect only records removed since its verified baseline.
- Before every actual write, the server backs up the prior NAS JSON in the private `calorie-backups` directory. Backups also supply revision history, so retain them. An unavailable baseline pauses saving instead of guessing.
- **Whole-NAS replacement is disabled.** First upload is allowed only if NAS is empty, after explicit confirmation. Reset and JSON import stay local and invalidate the device baseline, requiring a fresh NAS load before normal saving.
- An HTTP 409 needs review: export device JSON and back up NAS, then compare the conflicting records. Load NAS and re-enter only the intended edits. No automatic recovery, full replacement, or data deletion is performed during deployment.

If the NAS copy was already overwritten, preserve the current JSON and all backups first. Review timestamped files under `/volume1/leadville-private/calorie-backups` and both exported copies for the missing records. Restore a reviewed backup only with all tracker pages closed and the API stopped, then reload each device from the restored NAS. File timestamps and record counts alone do not prove which copy is complete.

## Development and tests

Use the existing checkout; a separate Git worktree is unnecessary. No package installation or build is needed. For local-only development:

```sh
python -m http.server 8000
```

PHP API tests use temporary directories and a temporary localhost PHP server; they never contact the NAS. Requires PHP 8.0+, Python 3, and Node.js:

```sh
php -l calorie-api.php
python -m unittest discover -s tests -v
node tests/nas-client.test.cjs
```

Set `PHP_BIN` if PHP is not on PATH. The suite covers authentication, legacy-write rejection, initialization protection, simultaneous devices, conflicting records/goals, intentional deletions, duplicate dates, baseline mismatch, retries, backup integrity, and the actual JavaScript client talking to PHP.
