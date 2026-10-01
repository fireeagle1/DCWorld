# Wardrobe framework

## Install
1. Import `wardrobe_schema.sql` using phpMyAdmin.
2. Upload the application files, including the hidden file `wardrobe_uploads/.htaccess`.
3. Ensure `wardrobe_uploads` is writable by PHP (typically permissions 0750 or 0755 depending on the host).
4. Keep the existing root `config.php` in place; this archive intentionally does not add database credentials.
5. Confirm PHP has `mysqli`, `fileinfo`, and image functions (`getimagesize`) available.

## Web routes
- `/wardrobe.php` — shared catalogue, search, category filter, and recycle bin.
- `/wardrobe_add.php` — add an item with required front and back images.
- `/wardrobe_item.php?id=...` — view/edit, soft-delete, and restore.
- `/wardrobe_image.php?id=...&view=front|back` — authenticated image delivery.

## API routes
All API routes use the existing bearer JWT authentication.
- `GET /api/wardrobe.php`
- `GET /api/wardrobe.php?id=1`
- `POST /api/wardrobe.php`
- `PUT /api/wardrobe.php?id=1`
- `DELETE /api/wardrobe.php?id=1`
- `POST /api/wardrobe_upload.php` as multipart form data with `name`, `category`, `front`, and `back`.
- `POST /api/wardrobe_restore.php` with JSON `{ "id": 1 }`.

## Notes
- Wardrobe data is shared by all authenticated users.
- Deleted records are retained through `DeletedAt`.
- Images are blocked from direct HTTP access by `.htaccess` and served through PHP after authentication.
## AI garment analysis
1. If you already imported the original schema, import `wardrobe_ai_migration.sql` once. New installations only need the updated `wardrobe_schema.sql`.
2. Configure the API key outside public source files using the `OPENAI_API_KEY` environment variable. If the host cannot set environment variables, define `WARDROBE_OPENAI_API_KEY` in the existing root `config.php`, which must not be publicly downloadable.
3. Optionally define `WARDROBE_OPENAI_MODEL`; otherwise `gpt-4.1-mini` is used.
4. PHP cURL is required. GD is optional but recommended because large images are resized before analysis.
5. `/wardrobe_analyse.php?id=1` performs synchronous web analysis. `POST /api/wardrobe_analyse.php` with JSON `{ "id": 1 }` exposes the same operation for the future iOS app.

AI suggestions overwrite the editable catalogue fields but preserve the original images. Saving the review form changes `MetadataSource` from `ai_suggested` to `user_confirmed`. Failed analysis does not delete the garment and can be retried.

## Brand detection and catalogue images
AI analysis stores a visible brand in the dedicated Brand field. It also includes a conservative fallback for common brands when the analysis places the brand in the generated item name.

Catalogue images use the same server-side OpenAI API key as analysis and model-image generation. No remove.bg account or API key is required.

Open an item and select **Generate or refresh catalogue images**. OpenAI creates garment-only front/back catalogue photographs on a consistent studio background. Both originals remain untouched. Generated PNGs are stored as `processed_front` and `processed_back` and become the preferred catalogue images. Always compare logos, lettering and fine garment details with the originals.

## Three image types

Each wardrobe item can retain three visual forms, with front and back roles:

1. `front` / `back` — original evidence photographs.
2. `processed_front` / `processed_back` — OpenAI-generated garment-only catalogue images.
3. `model_front` / `model_back` — OpenAI-generated visualisations on a faceless adult male model.

Run `wardrobe_model_image_migration.sql` once when upgrading an existing database.

Configure the image model in the private root `config.php`:

```php
define('WARDROBE_OPENAI_API_KEY', '...');
define('WARDROBE_OPENAI_IMAGE_MODEL', 'gpt-image-1'); // optional override
```

Web actions:

- `wardrobe_process_images.php?id=ITEM_ID`
- `wardrobe_generate_model_images.php?id=ITEM_ID`

Authenticated API actions:

- `POST /api/wardrobe_process_images.php?id=ITEM_ID`
- `POST /api/wardrobe_generate_model_images.php?id=ITEM_ID`

Generated model imagery is stored separately and must not be treated as an exact product record. Always retain and display the original photographs for verification.

## Image quality and cost control

The default image quality is `medium`. Override it in `config.php` when required:

```php
define('WARDROBE_OPENAI_IMAGE_QUALITY', 'medium'); // low, medium, or high
```

The setting applies to catalogue and male-model generation.

## July 2026 view/edit and AI service update

- `wardrobe_item.php` is now a read-only detail screen.
- `wardrobe_item_edit.php` contains metadata editing, deletion/restoration, and image defaults.
- Each item has separate catalogue-card and detail-page image preferences: original, clean, or model.
- Missing preferred images fall back automatically.
- OpenAI image calls are centralised in `lib/WardrobeAI.php`.
- MIME type is explicitly detected and supplied to `CURLFile`, fixing `application/octet-stream` failures.
- Existing installations must import `wardrobe_view_preferences_migration.sql` once.

## Product gallery and image backlog

- `wardrobe_item.php` is read-only and displays every available original, clean and AI-model image in a product-gallery layout. The saved detail preference is shown first.
- Image generation and analysis controls are on `wardrobe_item_edit.php`.
- `wardrobe_backlog.php` identifies missing clean/model derivatives and processes selected garments in batches of up to 10, reducing shared-hosting timeout risk.

## Modern wardrobe interface update

This build adds a wardrobe-specific, image-first interface inspired by modern fashion retail sites while preserving the existing PHP/MySQL backend and APIs.

New or updated presentation files:

- `wardrobe-modern.css`
- `wardrobe.php`
- `wardrobe_item.php`
- `header.php` (conditionally loads the wardrobe stylesheet)

The add, edit and image backlog pages also load the wardrobe-specific stylesheet. No database migration is required for this visual update.

## Wear tracking update

Run `wardrobe_wear_log_migration.sql` once in phpMyAdmin before using the **Worn today** action.

The item page now:

- Fits the complete preferred image within the initial viewport.
- Shows all additional image variants beneath it.
- Provides a **Worn today** dialog.
- Lets the user optionally select compatible items worn alongside it.
- Filters obvious clashes such as another top when the current item is a top.

The wardrobe landing page no longer displays numerical KPI cards or category counts.
