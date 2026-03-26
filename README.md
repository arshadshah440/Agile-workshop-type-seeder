# Workshop Type Seeder

A WordPress plugin that seeds pre-configured **Workshop Type** taxonomy terms — complete with featured images, badge images, and ACF custom field data — via the WordPress REST API. Designed for rapid setup of agile training and certification platforms.

---

## Features

- One-click seeding of 5 Workshop Type taxonomy terms from a JSON data file
- Automatic media sideloading: downloads remote images and adds them to the WordPress media library
- Full ACF custom field population (images, text, WYSIWYG, relationships)
- Admin dashboard page with status checks and real-time feedback
- REST API endpoints for integration with external tooling
- Secure: nonce verification and `manage_options` capability checks

---

## Workshop Types Included

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

---

## Installation

1. Download or clone this repository.
2. Copy the `workshop-type-seeder/` folder into your WordPress `wp-content/plugins/` directory.
3. In the WordPress admin, go to **Plugins** and activate **Workshop Type Seeder**.
4. Ensure the `workshop-type` taxonomy is registered (e.g. via The Events Calendar) and ACF is active.
5. Navigate to **WS Type Seeder** in the admin menu and click **Seed Workshop Type Terms**.

---

## Usage

### Admin UI

Go to **Dashboard → WS Type Seeder**. The page shows:

- Status checks for the `workshop-type` taxonomy, ACF activation, and the data file
- The number of terms that will be seeded
- A **Seed Workshop Type Terms** button

After clicking the button, a results table is displayed showing each term's creation status, image upload result, and ACF field update status. Any errors are listed in a separate table.

### REST API

**Seed all terms**
```
POST /wp-json/wts/v1/seed-workshop-types
Headers: X-WP-Nonce: <nonce>
```

**Check plugin status**
```
GET /wp-json/wts/v1/status
```

---

## Data File

Term definitions live in [`workshop-type-seeder/data/terms.json`](workshop-type-seeder/data/terms.json). Each entry contains:

```json
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
```

To seed different terms, edit this file before running the seeder.

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

For each term in `terms.json` the plugin performs the following steps:

1. **Download images** — uses `download_url()` to fetch featured image and badge to a temp file
2. **Upload to media library** — sends an internal REST request to `POST /wp/v2/media` and returns attachment IDs
3. **Create taxonomy term** — sends an internal REST request to `POST /wp/v2/workshop-type`
4. **Populate ACF fields** — calls `update_field()` for each mapped field using the term ID and attachment IDs

---

## Project Structure

```
workshop-type-seeder/
├── assets/
│   └── js/
│       └── admin.js                  # Admin UI: fetch, results rendering
├── data/
│   └── terms.json                    # Seed data for all workshop types
└── workshop-type-seeder.php          # Main plugin file (singleton class)
```

---

## License

[GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Author

**Arshad Shah** — [arshadwebstudio.com](https://arshadwebstudio.com)
