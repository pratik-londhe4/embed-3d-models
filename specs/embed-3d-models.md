# Embed 3D Models (`embed-3d-models`)

Namespace `PratikLondhe\Embed3DModels`, prefix `embed_3d_models`, CSS prefix `e3dm-`, JS hooks namespace `embed3dModels`. E2E port 8911.
Requires at least 6.6 (block API v3, editor iframe) and PHP 7.4.

## Problem

Bloggers and site owners want to show a 3D model (a product, a scan, a 3D print, a sculpture) in a post the way they show an image: upload, insert a block, publish. WordPress refuses the upload ("Sorry, you are not allowed to upload this file type"), and when a plugin lets it through, the file is not checked at all.

Competitors (directory, 2026-10-24): 3D Viewer by bPlugins (9,000 installs, Three.js, freemium with upsells), 3D Viewer Block (1,000), Embed STL (400, STL only), 3D Viewer by WPSE (90, glTF only). They mostly open the upload filter without checking what the file is, load their engine on every page, and pull decoders from Google's CDN.

What this plugin does differently:

- **Uploads are checked by content**, not just by extension: a `.glb` must be a real binary glTF 2.0 file, an `.stl` must parse as STL, and text formats carrying `<?php` or `<script` are refused. Every refusal gives a specific reason.
- **STL and OBJ are converted to GLB on the server at upload**, with streamed parsing and writing so memory stays flat. The frontend then needs one lightweight engine (`<model-viewer>`) for every format. The original file is kept.
- **Free and self-contained**: no upsells, no tracking, no CDN. Google's `<model-viewer>` and its Draco, KTX2 and meshopt decoders are bundled and only load on pages that contain the block.
- **Real thumbnails in the Media Library**: "Use current view as poster" captures the model in the editor, saves it as an image, and uses it as the block's poster and the model's Media Library thumbnail.
- **Blog-friendly defaults**: lazy loading, alt text up front, zoom off by default so the page scroll is never captured, optional "click to load" for heavy models.

## v1.0.0 scope

1. **Uploads.** Adds GLB (`model/gltf-binary`), glTF (`model/gltf+json`), USDZ (`model/vnd.usdz+zip`), STL (`model/stl`) and OBJ (`model/obj`) to the allowed types for users with `upload_files` (filterable). Content checks:
   - GLB: `glTF` magic, version 2, declared length equals file size, first chunk is JSON with `asset.version` 2.x.
   - glTF: valid JSON, `asset.version` 2.x, and every buffer and image is embedded (`data:` URI). Files that point to separate `.bin` or texture files are refused with "upload a .glb instead" (filterable).
   - USDZ: a zip whose first entry is a `.usd`, `.usda` or `.usdc` file.
   - STL: binary (size = 84 + 50 × triangle count) or ASCII (`solid` ... `facet` ... `vertex`).
   - OBJ: text with at least one `v` line and one `f` line, no NUL bytes.
   - Text formats (glTF, OBJ, ASCII STL) containing `<?php`, `<?=` or `<script` are refused.
   - Media Library gets a "3D models" type filter and a 3D file icon.
2. **Conversion.** On `wp_generate_attachment_metadata`, STL and OBJ files are converted to a GLB beside the original (`name.stl` to `name-stl.glb`), streaming triangles to a temp file with O(1) memory for STL and 12 bytes per vertex for OBJ. Flat normals from the geometry (STL normals are often wrong); OBJ `vn` normals are used when present. STL is Z-up and in millimetres, so it is rotated to Y-up and scaled by 0.001 (both filterable per format). One PBR material in a neutral grey (filterable). Files over 64 MB (filterable) are not converted at upload; WP-CLI can convert them with `--ignore-limit`. The result, triangle count and any error are stored in the attachment metadata under `embed_3d_models`. Deleting the attachment deletes the derived GLB.
3. **Block `embed-3d-models/model`.** Dynamic block (PHP render), category media. Placeholder: upload, Media Library (only 3D types) or URL. Inspector: alt text (required, warned when empty), height, background colour, auto-rotate, camera controls, zoom, AR (off by default, with an optional USDZ file for iPhone), exposure, shadow, loading (lazy, eager), "click to load" with poster, animation autoplay, poster image, "Use current view as poster", "Save current camera angle", "Reset camera". Caption. Editor preview is the real viewer. Shows triangle count and conversion state; explains conversion failures.
4. **Posters as thumbnails.** `POST /embed-3d-models/v1/models/<id>/poster` (`edit_post` on the model) stores the poster as the model attachment's `_thumbnail_id`; the Media Library grid shows it.
5. **Assets.** `<model-viewer>` 4.4.0 UMD, Draco, KTX2 and meshopt decoders vendored under `assets/vendor/`, decoder locations pointed at them. Loaded on the frontend only when the block renders; loaded in the editor (iframe and non-iframe) through `enqueue_block_assets`.
6. **Site Health.** Warns when another plugin removed the 3D types from uploads, when a USDZ file is served with the wrong Content-Type (iPhone AR then fails), and when conversions failed.
7. **Edge cases.** Multisite: per site, network upload file types (`upload_filetypes`) must include the extensions; Site Health says so. Large files: streamed conversion, size cap. External URLs: work if the host sends CORS headers; the editor says so on load failure. Older WordPress: abilities guarded by `function_exists`.
8. **Privacy.** No personal data stored. AR on Android hands the model URL to Google's Scene Viewer app; documented under External services. No other external calls.
9. **Uninstall.** Deletes every derived GLB and the `embed_3d_models` attachment metadata key. Original uploads and posters stay (they are the user's media).

## Hooks

Filters: `embed_3d_models_upload_capability`, `embed_3d_models_formats`, `embed_3d_models_validate_file`, `embed_3d_models_allow_external_resources`, `embed_3d_models_max_convert_bytes`, `embed_3d_models_conversion_scale`, `embed_3d_models_conversion_up_axis`, `embed_3d_models_conversion_color`, `embed_3d_models_viewer_attributes`, `embed_3d_models_render`, `embed_3d_models_decoder_locations`, `embed_3d_models_message`.
Actions: `embed_3d_models_upload_rejected`, `embed_3d_models_converted`, `embed_3d_models_conversion_failed`, `embed_3d_models_poster_set`, `embed_3d_models_derived_deleted`.
JS (`wp.hooks`, namespace `embed3dModels`): filters `embed3dModels.viewerAttributes`, `embed3dModels.allowedTypes`, `embed3dModels.posterOptions`; actions `embed3dModels.posterCreated`, `embed3dModels.modelSelected`.

## Abilities

`embed-3d-models/list-models` (readonly), `embed-3d-models/get-model` (readonly), `embed-3d-models/convert-model` (idempotent), `embed-3d-models/set-model-poster` (idempotent).

## WP-CLI

`wp embed-3d-models list [--format=]`, `get <id> [--format=]`, `convert <id>... [--ignore-limit]`, `set-poster <model-id> <image-id>`, `check <file>` (validate a local file), `formats [--format=]`.

## E2E ideas

- Upload a GLB through the Media Library: attachment exists with `model/gltf-binary` (WP-CLI).
- Upload an STL: derived GLB file exists on disk, metadata has `status=converted` and the triangle count.
- Upload a fake `.glb` (renamed text file) and a glTF with an external `.bin`: refused with the specific message, no attachment created.
- An author (has `upload_files`) can upload; a contributor cannot.
- Insert the block, pick a model, set alt text, publish: frontend has `<model-viewer>` with the alt and the GLB URL, the vendored script loads, no request leaves the site.
- A page without the block does not load model-viewer.
- "Use current view as poster": an image attachment is created and set as the model's `_thumbnail_id`.
- Delete the STL attachment: derived GLB is gone.
- `wp.hooks` filter `embed3dModels.viewerAttributes` changes the editor preview.
- Runs on 6.6 / PHP 7.4, 6.9 and latest.

## Hard parts

- WordPress's own type check (`wp_check_filetype_and_ext`) rejects these files because `finfo` reports `application/octet-stream`, `text/plain` or `application/zip`. The plugin re-decides only for its own extensions and only after its content check passed.
- The editor canvas is an iframe from 6.3 when every block is API v3; custom elements must be defined inside it, so the viewer is enqueued through `enqueue_block_assets`, not `enqueue_block_editor_assets`.
- GLB writing: 4-byte aligned chunks, JSON padded with spaces, BIN with zeros, accessor min/max required for POSITION.
