# Workshop Type Seeder

A WordPress plugin that seeds pre-configured **Workshop Type** taxonomy terms — complete with featured images, badge images, ACF custom field data, and optional WPML translations — via the WordPress REST API. Designed for rapid setup of agile training and certification platforms.

---

## Features

- Upload a JSON file and seed any number of Workshop Type taxonomy terms in one click
- Automatic media sideloading: downloads remote images and adds them to the WordPress media library
- Full ACF custom field population (images, text, WYSIWYG, relationships)
- **WPML multilingual support**: seed terms in multiple languages in a single import; falls back gracefully when WPML is not installed
- Admin dashboard page with status checks and real-time feedback
- Client-side JSON validation: required field checks before submission, optional field warnings in import log
- REST API endpoints for integration with external tooling
- Secure: nonce verification and `manage_options` capability checks

---

## Workshop Types Included

The plugin ships no hardcoded data. You supply a JSON file containing any terms you need. Typical agile platform setup includes:

| Name | Slug | Abbreviation |
|---|---|---|
| Scrum Master | `scrum-master` | SM |
| Product Owner | `product-owner` | PO |
| Agile Coach | `agile-coach` | AC |
| Kanban Practitioner | `kanban-practitioner` | KMP |
| DevOps Engineer | `devops-engineer` | DOE |

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8 or later |
| PHP | 7.4 or later |
| [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) | Any (registers `workshop-type` taxonomy) |
| [Advanced Custom Fields](https://www.advancedcustomfields.com/) | Any (optional, for ACF field population) |
| [WPML Multilingual CMS](https://wpml.org/) | Any (optional, for multilingual term seeding) |

---

## Installation

1. Download or clone this repository.
2. Copy the `workshop-type-seeder/` folder into your WordPress `wp-content/plugins/` directory.
3. In the WordPress admin, go to **Plugins** and activate **Workshop Type Seeder**.
4. Ensure the `workshop-type` taxonomy is registered (e.g. via The Events Calendar) and ACF is active.
5. Navigate to **WS Type Seeder** in the admin menu, upload your JSON file, and click **Import & Seed Terms**.

---

## Usage

### Admin UI

Go to **Dashboard → WS Type Seeder**. The page shows:

- Status notices for the `workshop-type` taxonomy, ACF activation, and WPML activation
- A **file input** — select your `.json` file
- Instant client-side validation feedback:
  - Blocks submit if any term is missing `name` or `slug`, listing the exact problems
  - When WPML is active, blocks submit if any translation entry is missing a `name`
  - Warns about missing optional fields (won't block import)
- An **Import & Seed Terms** button (enabled only after the file passes validation)

After clicking the button, a results table is displayed showing each term's creation status, image upload result, import log (missing optional fields), and ACF field update status. When WPML is active, a **Translations** column is added showing the per-language import result and translation group ID (trid). Any errors are listed in a separate table.

### REST API

**Seed all terms**
```
POST /wp-json/wts/v1/seed-workshop-types
Headers: X-WP-Nonce: <nonce>
Body: { "terms": [ ... ] }
```

**Check plugin status**
```
GET /wp-json/wts/v1/status
```

---

## JSON File Format

Prepare a `.json` file with an array of term objects and upload it via the admin UI.

### Single-language (no WPML)

```json
[
  {
    "name": "Scrum Master",
    "slug": "scrum-master",
    "description": "...",
    "images": {
      "featured_image": { "url": "...", "filename": "...", "title": "...", "alt": "..." },
      "workshop_badge":  { "url": "...", "filename": "...", "title": "...", "alt": "..." }
    },
    "acf": {
      "workshop_description": "<p>...</p>",
      "workshop_tagline": "...",
      "abbreviation": "SM"
    }
  }
]
```

### Multilingual (with WPML)

Add an optional `translations` object keyed by WPML language code. Each language entry supports the same fields as a primary term (`name`, `slug`, `description`, `images`, `acf`). The `name` field is required per translation; all others are optional.

```json
[
  {
    "name": "Scrum Master",
    "slug": "scrum-master",
    "description": "...",
    "images": {
      "featured_image": { "url": "...", "filename": "...", "title": "...", "alt": "..." },
      "workshop_badge":  { "url": "...", "filename": "...", "title": "...", "alt": "..." }
    },
    "acf": {
      "workshop_description": "<p>...</p>",
      "workshop_tagline": "...",
      "abbreviation": "SM"
    },
    "translations": {
      "de": {
        "name": "Scrum Master",
        "slug": "scrum-master-de",
        "description": "...",
        "acf": {
          "workshop_description": "<p>...</p>",
          "workshop_tagline": "...",
          "abbreviation": "SM"
        }
      },
      "fr": {
        "name": "Scrum Master",
        "slug": "scrum-master-fr",
        "description": "..."
      }
    }
  }
]
```

**Required:** `name`, `slug` on the primary term.
**Required per translation:** `name` (when WPML is active).
**Optional:** all other fields — missing ones are skipped and noted in the import log.

> **Note:** If WPML is not installed, the `translations` key is silently ignored and the plugin behaves identically to a single-language setup.

---

## WPML Multilingual Support

When WPML is active the plugin performs additional steps for each term:

1. The **primary term** is created in the site's default language and registered with WPML, creating a new translation group (trid).
2. For each language code in the `translations` object, a **translated term** is created, linked to the same translation group, and its ACF fields are populated independently.
3. Translation slugs are auto-generated as `{sanitized-name}-{lang-code}` if not provided in the JSON.
4. The import results table shows a **Translations** column with per-language status and the shared trid.

If a `translations` key is present in the JSON but WPML is **not** active, the translations are skipped with no error — the primary terms are still created normally.

---

## ACF Field Mapping

| Field Key | Field Label |
|---|---|
| `field_695645eac1f1c` | Featured Image |
| `field_69564622c1f1d` | Workshop Badge |
| `field_6959662681c4c` | Certification Body (relationship) |
| `field_695798931d1f9` | Workshop Description (WYSIWYG) |
| `field_69596558ec5b6` | Workshop Tagline |
| `field_695966d7807ed` | Abbreviation |

ACF field group: `group_695645e713735` (attached to `workshop-type` terms)

---

## How It Works

For each term in the uploaded JSON file the plugin performs the following steps:

1. **Validate** — rejects any term missing `name` or `slug` (server-side mirror of client validation)
2. **Download images** — uses `download_url()` to fetch featured image and badge to a temp file
3. **Upload to media library** — sends an internal REST request to `POST /wp/v2/media` and returns attachment IDs
4. **Create taxonomy term** — sends an internal REST request to `POST /wp/v2/workshop-type`
5. **Populate ACF fields** — calls `update_field()` for each mapped field using the term ID and attachment IDs
6. **Register WPML language** *(WPML only)* — links the term to a translation group via `wpml_set_element_language_details`
7. **Seed translations** *(WPML only)* — repeats steps 2–6 for each language entry, joining the same translation group

---

## Project Structure

```
workshop-type-seeder/
├── assets/
│   └── js/
│       └── admin.js                  # Admin UI: file input, validation, fetch, results rendering
└── workshop-type-seeder.php          # Main plugin file (singleton class, v1.3.0)
```

---

## License

[GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Author

**Arshad Shah** — [arshadwebstudio.com](https://arshadwebstudio.com)
