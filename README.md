# Joomla Image Cleanup Script

A PHP CLI script to identify and clean up unused images in a Joomla website.  
It scans the `/images` folder, compares images against references in the database, and either moves or deletes unused images.

> [!CAUTION]
> Be Careful. Make sure you have a full site backup before running this script.
---

## Features

- Handles images referenced in **HTML** and **JSON** fields.
- Supports major Joomla tables:
  - `content`
  - `categories`
  - `contact_details`
  - `menu`
  - `modules`
  - `fields_values`
- Extracts image keys such as:
  - `image_intro`, `image_fulltext`, `image`, `menu_image`, `backgroundimage`, `imagefile`
- **Whitelist folders** (e.g., `banners`, `headers`, `sampledata`) are never touched.
- **Dry-run mode** (`--dry-run`) shows actions without modifying files.
- **Delete mode** (`--delete`) deletes unused images instead of moving them.
- **Quiet mode** (`--quiet`) suppresses console output, ideal for cron jobs.
- Logs all actions to `cleanup-log.txt`.
- Maintains original subfolder structure when moving unused images to `/unused`.

---

## Requirements

- PHP 7.4+ (CLI)
- Joomla website with database access
- Permissions to read/write the `/images` folder and create `/unused`

---

## Installation

1. Place the script in your Joomla root directory (same level as `configuration.php`).
2. Make sure the script has **execute permissions**:
   ```bash
   chmod +x cleanup.php
   ```
3. Ensure the `/unused` folder is writable (will be created automatically if it doesn’t exist, unless running dry-run).

---

## Usage

Run the script from the CLI in your Joomla root:

```bash
php cleanup.php [options]
```

### Options

| Option        | Description |
|---------------|-------------|
| `--dry-run`   | Show actions without moving or deleting files. |
| `--delete`    | Delete unused images instead of moving them to `/unused`. |
| `--quiet`     | Suppress console output (useful for automated cron jobs). |

### Examples

- **Dry run** (safe preview):
  ```bash
  php cleanup.php --dry-run
  ```

- **Move unused images to `/unused`**:
  ```bash
  php cleanup.php
  ```

- **Delete unused images**:
  ```bash
  php cleanup.php --delete
  ```

- **Quiet mode for cron jobs**:
  ```bash
  php cleanup.php --quiet
  ```

---

## How It Works

1. **Database Scan**  
   The script scans Joomla tables and columns for image references, including HTML and JSON fields.

2. **Filesystem Scan**  
   It scans the `/images` folder recursively and excludes whitelisted folders.

3. **Determine Unused Images**  
   Compares filesystem images against database references to identify unused files.

4. **Move or Delete**  
   - By default, unused images are moved to `/unused`, maintaining folder structure.  
   - With `--delete`, images are permanently removed.  
   - With `--dry-run`, actions are only logged without modifying files.

5. **Logging**  
   All actions are logged in `cleanup-log.txt` in the Joomla root.

---

## Notes

- Always **backup your site** before running the script, especially if using `--delete`.
- Whitelisted folders are never modified.
- Works only on Joomla sites using standard table structures. Custom extensions may require adjustments.

---

## License

GPLv2 or later. See the [LICENSE.txt](LICENSE.txt) file for details.

---

## Author

Brian Teeman – [https://brian.teeman.net](https://brian.teeman.net)

