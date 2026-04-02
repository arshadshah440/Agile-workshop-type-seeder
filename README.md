# Workshop Type Seeder

A WordPress plugin that seeds pre-configured **Workshop Type** taxonomy terms and **Certification Body** custom posts — complete with featured images, logos, gallery images, ACF custom field data, and optional WPML translations — via the WordPress REST API. Designed for rapid setup of agile training and certification platforms.

---

## Features

- **Two seeders in one plugin** — Workshop Type terms and Certification Body CPT posts
- Upload a JSON file and seed any number of records in one click
- Automatic media sideloading: downloads remote images and adds them to the WordPress media library
- **Image reuse**: if an image definition includes a valid WordPress attachment `id`, the existing media item is reused — no re-download
- Full ACF custom field population (images, text, WYSIWYG, URL, gallery, relationships)
- **WPML multilingual support** on both seeders: seed terms and posts in multiple languages in a single import; images not supplied for a translation fall back to the primary language's attachment IDs
- Separate admin submenu pages for Workshop Types and Certification Bodies
- Client-side JSON validation: required field checks before submission, optional field warnings in import log
- REST API endpoints for each seeder and their corresponding status checks
- Secure: nonce verification and `manage_options` capability checks on every endpoint

---

## Modules

### 1 — Workshop Type Seeder

Seeds terms into the `workshop-type` taxonomy (registered by The Events Calendar or equivalent). Each term supports a featured image, a workshop badge image, ACF text/WYSIWYG fields, a certification body relationship field, and optional WPML translations.

### 2 — Certification Body Seeder

Seeds posts into the `certifications-body` custom post type. Each post supports a featured image, four logo variants (Logo, Logo + Text, Initials, Watermark), a gallery field, scalar ACF fields (ID, abbreviation, slogan, URL, description), and optional WPML translations.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8 or later |
| PHP | 7.4 or later |
| [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) | Any (registers `workshop-type` taxonomy) |
| [Advanced Custom Fields](https://www.advancedcustomfields.com/) | Any (optional — ACF fields are skipped when inactive) |
| [WPML Multilingual CMS](https://wpml.org/) | Any (optional — translations are skipped when inactive) |

---

## Installation

1. Download or clone this repository.
2. Copy the `workshop-type-seeder/` folder into your WordPress `wp-content/plugins/` directory.
3. In the WordPress admin, go to **Plugins** and activate **Workshop Type Seeder**.
4. Ensure the `workshop-type` taxonomy and `certifications-body` post type are registered, and ACF is active.
5. Navigate to **WS Type Seeder** in the admin sidebar to seed workshop types, or **WS Type Seeder → Cert Body Seeder** for certification bodies.

---

## Usage

### Workshop Type Seeder

**Dashboard → WS Type Seeder**

- Status notices for the `workshop-type` taxonomy, ACF, and WPML
- Select a `.json` file; client validates required fields (`name`, `slug`) and WPML translation fields (`name`) before enabling the import button
- Warnings shown for missing optional fields — these do not block the import
- Results table shows per-term: ID, Name, Slug, Images (featured + badge), Import Log (missing optional fields), ACF fields, and (when WPML) a Translations column

### Certification Body Seeder

**Dashboard → WS Type Seeder → Cert Body Seeder**

- Status notices for the `certifications-body` CPT, ACF, and WPML
- Select a `.json` file; client validates required fields (`title`, `slug`) and WPML translation fields (`title`)
- Results table shows per-post: ID, Title, Slug, Featured Image, Logos (4 variants), Gallery summary, Import Log, ACF fields, and (when WPML) a Translations column

### REST API

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/wp-json/wts/v1/seed-workshop-types` | Seed workshop type terms |
| `GET` | `/wp-json/wts/v1/status` | Workshop type plugin status |
| `POST` | `/wp-json/wts/v1/seed-certification-bodies` | Seed certification body posts |
| `GET` | `/wp-json/wts/v1/cert-status` | Certification body plugin status |

All POST endpoints require the `X-WP-Nonce` header and `manage_options` capability.

---

## JSON File Format

### Workshop Types — single language

```json
[
  {
    "name": "Scrum Master",
    "slug": "scrum-master",
    "description": "...",
    "images": {
      "featured_image": { "id": 0, "url": "...", "filename": "scrum-master-featured.jpg", "title": "...", "alt": "..." },
      "workshop_badge":  { "id": 0, "url": "...", "filename": "scrum-master-badge.jpg",   "title": "...", "alt": "..." }
    },
    "acf": {
      "workshop_description": "<p>...</p>",
      "workshop_tagline": "Lead teams with clarity and purpose",
      "abbreviation": "SM"
    }
  }
]
```

### Workshop Types — multilingual (WPML)

Add a `translations` object keyed by WPML language code. Each language entry supports the same fields as the primary term. `name` is required per translation; all other fields are optional and fall back to the primary language's attachment IDs when omitted.

```json
[
  {
    "name": "Scrum Master",
    "slug": "scrum-master",
    "images": {
      "featured_image": { "id": 0, "url": "...", "filename": "scrum-master-featured.jpg", "title": "...", "alt": "..." },
      "workshop_badge":  { "id": 0, "url": "...", "filename": "scrum-master-badge.jpg",   "title": "...", "alt": "..." }
    },
    "acf": { "workshop_description": "<p>...</p>", "workshop_tagline": "...", "abbreviation": "SM" },
    "translations": {
      "de": {
        "name": "Scrum Master",
        "slug": "scrum-master-de",
        "acf": { "workshop_tagline": "Teams mit Klarheit führen" }
      },
      "fr": {
        "name": "Scrum Master",
        "slug": "scrum-master-fr",
        "images": {
          "featured_image": { "id": 0, "url": "...", "filename": "scrum-master-featured-fr.jpg", "title": "...", "alt": "..." }
        },
        "acf": { "workshop_tagline": "Diriger les équipes avec clarté" }
      }
    }
  }
]
```

> **Image fallback:** a translation with no `images` key (or no value for a specific image) automatically inherits the primary language's already-uploaded attachment ID — no duplicate download occurs.

> **Slug auto-generation:** if a translation omits `slug`, one is generated as `{sanitized-name}-{lang-code}`.

---

### Certification Bodies — single language

```json
[
  {
    "title": "Scrum Alliance",
    "slug": "scrum-alliance",
    "excerpt": "...",
    "featured_image": { "id": 0, "url": "...", "filename": "scrum-alliance-featured.jpg", "title": "...", "alt": "..." },
    "acf": {
      "certification_body_aj_id": 1,
      "certification_body_abbreviation": "SA",
      "certification_body_slogan": "...",
      "certification_body_website_url": "https://www.scrumalliance.org",
      "certification_body_description": "...",
      "certification_body_logo":           { "id": 0, "url": "...", "filename": "logo.jpg",          "title": "...", "alt": "..." },
      "certification_body_logo_with_text": { "id": 0, "url": "...", "filename": "logo-text.jpg",     "title": "...", "alt": "..." },
      "certification_body_logo_initials":  { "id": 0, "url": "...", "filename": "logo-initials.jpg", "title": "...", "alt": "..." },
      "certification_body_watermark":      { "id": 0, "url": "...", "filename": "watermark.jpg",     "title": "...", "alt": "..." },
      "certification_body_gallery": [
        { "id": 0, "url": "...", "filename": "gallery-1.jpg", "title": "...", "alt": "..." }
      ]
    }
  }
]
```

### Certification Bodies — multilingual (WPML)

Add a `translations` object keyed by WPML language code. `title` is required per translation. All image fields fall back to the primary language's attachment IDs when omitted — logos and gallery items individually.

```json
[
  {
    "title": "Scrum Alliance",
    "slug": "scrum-alliance",
    "excerpt": "...",
    "featured_image": { "id": 0, "url": "...", "filename": "scrum-alliance-featured.jpg", "title": "...", "alt": "..." },
    "acf": {
      "certification_body_aj_id": 1,
      "certification_body_abbreviation": "SA",
      "certification_body_slogan": "...",
      "certification_body_website_url": "https://www.scrumalliance.org",
      "certification_body_description": "...",
      "certification_body_logo": { "id": 0, "url": "...", "filename": "logo.jpg", "title": "...", "alt": "..." }
    },
    "translations": {
      "de": {
        "title": "Scrum Alliance",
        "slug": "scrum-alliance-de",
        "excerpt": "...",
        "acf": {
          "certification_body_slogan": "Die Arbeitswelt verändern.",
          "certification_body_description": "..."
        }
      },
      "fr": {
        "title": "Scrum Alliance",
        "slug": "scrum-alliance-fr",
        "featured_image": { "id": 0, "url": "...", "filename": "scrum-alliance-featured-fr.jpg", "title": "...", "alt": "..." },
        "acf": {
          "certification_body_slogan": "Changer le monde du travail.",
          "certification_body_description": "..."
        }
      }
    }
  }
]
```

**Required (Workshop Types):** `name`, `slug` on primary; `name` per translation.
**Required (Certification Bodies):** `title`, `slug` on primary; `title` per translation.
**Optional:** everything else — missing fields are skipped and noted in the import log.

> If WPML is not installed, the `translations` key is silently ignored and both seeders behave identically to a single-language setup.

---

## WPML Multilingual Support

Both seeders apply the same WPML logic:

1. The **primary record** is created in the site's default language and registered with WPML, creating a new translation group (`trid`).
2. For each language code in the `translations` object, a **translated record** is created, linked to the same `trid`, and its ACF fields and images are populated independently.
3. **Image fallback** — if a translation omits an image field, the primary record's already-uploaded attachment ID is reused for that field automatically. Each image field (featured image, each logo variant, gallery) is resolved independently, so a translation can supply some images and inherit others.
4. Translation slugs are auto-generated as `{sanitized-name}-{lang-code}` if not provided.
5. The import results table shows a **Translations** column with per-language status, linked post/term title, and image upload summary.

**Workshop Types** register WPML with `element_type = tax_workshop-type` and `element_id = term_taxonomy_id`.
**Certification Bodies** register WPML with `element_type = post_certifications-body` and `element_id = post_id`.

---

## ACF Field Mapping

### Workshop Type terms (`group_695645e713735`)

| Field Key | JSON Property | Type |
|---|---|---|
| `field_695645eac1f1c` | `images.featured_image` | Image (ID) |
| `field_69564622c1f1d` | `images.workshop_badge` | Image (array) |
| `field_6959662681c4c` | *(auto — all cert body post IDs)* | Relationship |
| `field_695798931d1f9` | `acf.workshop_description` | WYSIWYG |
| `field_69596558ec5b6` | `acf.workshop_tagline` | Text |
| `field_695966d7807ed` | `acf.abbreviation` | Text |

### Certification Body posts

| Field Key | JSON Property | Type |
|---|---|---|
| `field_69278863dbe63` | `acf.certification_body_aj_id` | Number |
| `field_692788acdbe64` | `acf.certification_body_abbreviation` | Text |
| `field_69278982dbe69` | `acf.certification_body_slogan` | Text |
| `field_692788f4dbe65` | `acf.certification_body_website_url` | URL |
| `field_693a776cfff6c` | `acf.certification_body_description` | Textarea |
| `field_693a765d93403` | `acf.certification_body_logo` | Image |
| `field_6927892bdbe66` | `acf.certification_body_logo_with_text` | Image |
| `field_69278948dbe67` | `acf.certification_body_logo_initials` | Image |
| `field_6927896edbe68` | `acf.certification_body_watermark` | Image |
| `field_693a768c373ff` | `acf.certification_body_gallery` | Gallery |

---

## Image Reuse

Every image definition object supports an optional `id` field:

```json
{ "id": 123, "url": "...", "filename": "...", "title": "...", "alt": "..." }
```

When `id` is a positive integer that corresponds to an existing WordPress attachment, the plugin reuses that attachment directly — skipping the download and upload entirely. If the `id` is `0`, missing, or does not reference a valid attachment, the image is downloaded from `url` and uploaded as a new media item.

The results table marks reused images with a distinct ↩ icon.

---

## How It Works

### Workshop Type Seeder — per term

1. **Validate** — rejects any term missing `name` or `slug`
2. **Resolve images** — reuse existing attachment by `id`, or download from `url` and upload via `POST /wp/v2/media`
3. **Create term** — `POST /wp/v2/workshop-type`
4. **Populate ACF fields** — `update_field()` for each mapped field
5. **Register WPML** *(WPML only)* — `wpml_set_element_language_details` (`element_type = tax_workshop-type`)
6. **Seed translations** *(WPML only)* — repeat steps 2–5 per language, with image fallback to primary term's attachment IDs

### Certification Body Seeder — per post

1. **Validate** — rejects any entry missing `title` or `slug`
2. **Resolve featured image** — reuse or upload
3. **Resolve logo images** — reuse or upload each of the four logo variants
4. **Resolve gallery images** — reuse or upload each gallery item
5. **Create post** — `POST /wp/v2/certifications-body` (sets `featured_media` directly)
6. **Populate ACF fields** — logos, gallery, scalar fields via `update_field()`
7. **Register WPML** *(WPML only)* — `wpml_set_element_language_details` (`element_type = post_certifications-body`)
8. **Seed translations** *(WPML only)* — repeat steps 2–6 per language, with image fallback to primary post's attachment IDs

---

## Example Files

The `examples/` directory contains ready-to-use JSON files:

| File | Description |
|---|---|
| `examples/terms.json` | Workshop type terms — single language |
| `examples/terms-multilingual.json` | Workshop type terms — with WPML translations (de, fr) demonstrating partial image fallbacks |
| `examples/certification-bodies.json` | Certification body posts — single language |
| `examples/certification-bodies-multilingual.json` | Certification body posts — with WPML translations (de, fr) demonstrating text-only and image-per-lang translations |

---

## Project Structure

```
Agile-workshop-type-seeder/
├── examples/
│   ├── terms.json                             # Workshop type sample data (single language)
│   ├── terms-multilingual.json                # Workshop type sample data (WPML)
│   ├── certification-bodies.json              # Certification body sample data (single language)
│   └── certification-bodies-multilingual.json # Certification body sample data (WPML)
└── workshop-type-seeder/
    ├── assets/
    │   └── js/
    │       ├── admin.js                       # Workshop Type seeder UI (v1.4)
    │       └── admin-cert.js                  # Certification Body seeder UI (v1.1)
    └── workshop-type-seeder.php               # Main plugin file (singleton class, v1.5.0)
```

---

## License

[GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Author

**Arshad Shah** — [arshadwebstudio.com](https://arshadwebstudio.com)
