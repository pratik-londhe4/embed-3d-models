# Reference

## Formats and checks

| Extension | Stored as | Check | Shown by the block |
| --- | --- | --- | --- |
| `.glb` | `model/gltf-binary` | `glTF` magic, version 2, declared length = file size, first chunk is JSON with `asset.version` 2.x, no external files | as uploaded |
| `.gltf` | `model/gltf+json` | JSON, `asset.version` 2.x, every buffer and image embedded (`data:` URI), no code | as uploaded |
| `.usdz` | `model/vnd.usdz+zip` | zip whose first entry is a `.usd`, `.usda` or `.usdc` file | never (iPhone and iPad AR file only) |
| `.stl` | `model/stl` | binary (size = 84 + 50 × triangles) or ASCII (`solid`, `facet`, `vertex`), no code | converted GLB |
| `.obj` | `model/obj` | text with `v` and `f` lines, no NUL bytes, no code | converted GLB |

"No code" means the file does not contain `<?php`, `<?=` or `<script`, anywhere (scanned in 1 MB chunks).

WordPress's own type check (`wp_check_filetype_and_ext`) refuses these files, because PHP's fileinfo reports `application/octet-stream`, `text/plain` or `application/zip`. The plugin re-decides only for its own extensions, only for users allowed to upload them, and only after the content check passed.

## Conversion

STL and OBJ are converted to GLB on `wp_generate_attachment_metadata`, next to the original: `part.stl` becomes `part-stl.glb`.

- Triangles are streamed to a temporary file as interleaved position + normal (24 bytes per vertex), so memory stays flat for STL. OBJ keeps its vertices packed at 12 bytes each, because faces refer back to them.
- Normals come from the triangle corners (STL normals are often wrong). OBJ `vn` normals are used when a face has them.
- STL: Z-up to Y-up, millimetres to metres (0.001). OBJ: unchanged. Both filterable.
- One material, slate grey, double-sided. Filterable.
- Files over 64 MB are not converted during upload (status `too_large`). `wp embed-3d-models convert <id> --ignore-limit` converts them.

What the plugin knows about a model is stored in its attachment metadata under `embed_3d_models`:

| Key | Value |
| --- | --- |
| `format` | `glb`, `gltf`, `usdz`, `stl` or `obj` |
| `status` | `ready` (shown as uploaded), `converted`, `failed`, `too_large` |
| `glb` | file name of the converted GLB, in the same folder as the original |
| `glb_size` | its size in bytes |
| `triangles` | triangle count |
| `skipped` | triangles that could not be read (bad numbers, faces pointing to missing vertices) |
| `error` | why the conversion failed |

A model's poster is its `_thumbnail_id`. The Media Library shows it as the thumbnail.

## Block

`embed-3d-models/model`, a dynamic block: the model's address is looked up on every view, so a model converted again still shows.

| Attribute | Default | Notes |
| --- | --- | --- |
| `id` | | model attachment |
| `src` | `""` | model URL when not from the Media Library (the other site must send CORS headers) |
| `alt` | `""` | alternative text |
| `caption` | `""` | |
| `posterId`, `poster` | | poster image (falls back to the model's own poster) |
| `height` | `480px` | px, vh, svh, em, rem or % |
| `backgroundColor` | `""` | hex colour |
| `autoRotate` | `true` | |
| `cameraControls` | `true` | |
| `allowZoom` | `false` | off, so page scrolling is never captured |
| `ar` | `false` | adds `ar ar-modes="webxr scene-viewer quick-look"` |
| `iosId`, `iosSrc` | | USDZ file for iPhone and iPad AR |
| `exposure` | `1` | 0 to 4 |
| `shadowIntensity` | `1` | 0 to 2 |
| `cameraOrbit` | `""` | e.g. `0.785rad 1.047rad 2.5m` |
| `loading` | `lazy` | `lazy` or `eager` |
| `reveal` | `auto` | `manual` shows a "Load 3D model" button |
| `autoplay` | `false` | play the model's animation |

## Scripts

There is no build step.

| Script | Loads | Job |
| --- | --- | --- |
| `assets/vendor/model-viewer/model-viewer-umd.min.js` | front end with the block; editor canvas | Google's `<model-viewer>` 4.4.0 (Apache-2.0) |
| `assets/vendor/draco/`, `basis/`, `meshopt/` | when a compressed model needs them | decoders from three.js 0.183 and meshoptimizer (MIT), used instead of Google's CDN |
| `assets/js/view.js` | front end with the block | "Load 3D model" button, error message |
| `assets/js/attributes.js` | editor | block attributes to viewer attributes; no WordPress dependency, unit tested in Node |
| `assets/js/block.js` | editor | the block |

## Hooks

PHP filters:

| Hook | Arguments | Use |
| --- | --- | --- |
| `embed_3d_models_upload_capability` | `( string $capability )` | Who may upload 3D files. Default `upload_files` |
| `embed_3d_models_formats` | `( array $formats )` | Accepted formats keyed by extension. Remove a key to refuse that format; `mime` and `label` can be changed |
| `embed_3d_models_validate_file` | `( true\|WP_Error $result, string $path, string $ext )` | Final say on an upload. Return a `WP_Error` (or `false`) to refuse |
| `embed_3d_models_allow_external_resources` | `( bool $allow )` | Accept glTF files that point to separate `.bin` or texture files. Default `false` |
| `embed_3d_models_max_convert_bytes` | `( int $bytes )` | Largest STL or OBJ converted during upload. Default 64 MB |
| `embed_3d_models_conversion_scale` | `( float $scale, string $ext )` | Default 0.001 for STL, 1 for OBJ |
| `embed_3d_models_conversion_up_axis` | `( string $axis, string $ext )` | `y` or `z`. Default `z` for STL, `y` for OBJ |
| `embed_3d_models_conversion_color` | `( float[] $rgba )` | Colour of converted models, 0 to 1. Default `[0.42, 0.45, 0.5, 1]`, a slate grey |
| `embed_3d_models_viewer_attributes` | `( array $attributes, array $block_attributes, WP_Block\|null $block )` | Attributes of the `<model-viewer>` element. `true` adds a bare attribute, `false`/`null` removes one |
| `embed_3d_models_render` | `( string $html, array $block_attributes )` | The block's HTML |
| `embed_3d_models_decoder_locations` | `( array $locations )` | `dracoDecoderLocation`, `ktx2TranscoderLocation`, `meshoptDecoderLocation` |
| `embed_3d_models_message` | `( string $message, string $key )` | Any message shown to people, by key (`invalid_glb`, `external_resources`, `embedded_code`, `too_large`, `load_failed`, ...) |

PHP actions:

| Hook | Arguments | When |
| --- | --- | --- |
| `embed_3d_models_upload_rejected` | `( array $file, WP_Error $error )` | A 3D upload was refused by its content |
| `embed_3d_models_converted` | `( int $id, array $meta, string $glb_path )` | An STL or OBJ became a GLB |
| `embed_3d_models_conversion_failed` | `( int $id, WP_Error $error )` | It could not be converted |
| `embed_3d_models_poster_set` | `( int $id, int $poster_id )` | A model's poster was set (`0` when removed) |
| `embed_3d_models_derived_deleted` | `( int $id, string $path )` | A converted GLB was deleted with its model |

```php
// Only administrators upload 3D files, and STL prints show in orange.
add_filter( 'embed_3d_models_upload_capability', fn() => 'manage_options' );
add_filter( 'embed_3d_models_conversion_color', fn() => array( 1.0, 0.45, 0.1, 1.0 ) );

// Use a studio environment on every viewer.
add_filter( 'embed_3d_models_viewer_attributes', function ( $attributes ) {
	$attributes['environment-image'] = 'neutral';
	return $attributes;
} );
```

JavaScript, through `wp.hooks` in the editor:

| Hook | Type | Use |
| --- | --- | --- |
| `embed3dModels.viewerAttributes` | filter | `( Object attributes, Object blockAttributes )`. Attributes of the editor preview |
| `embed3dModels.allowedTypes` | filter | `( string[] mimeTypes )`. Types offered by the block's Media Library |
| `embed3dModels.posterOptions` | filter | `( Object options, Object blockAttributes )`. Options for `toBlob()`: `mimeType`, `qualityArgument`, `idealAspect` |
| `embed3dModels.modelSelected` | action | `( Object media, Object blockAttributes )`. A model was picked or uploaded |
| `embed3dModels.posterCreated` | action | `( Object media, Object blockAttributes )`. A poster image was saved |

## REST

Namespace `embed-3d-models/v1`. Each route needs `edit_post` on the model.

| Route | Method | Does |
| --- | --- | --- |
| `/models/<id>` | GET | The model: format, `viewer_url`, status, triangles, poster |
| `/models/<id>/poster` | POST `{ poster_id }` | Sets the poster (`0` removes it). Needs `edit_post` on the image too |
| `/models/<id>/convert` | POST | Converts again, within the size limit |

## WP-CLI

```sh
wp embed-3d-models list [--type=stl] [--limit=100] [--format=table|csv|json|ids|count]
wp embed-3d-models get <id> [--format=table|json|yaml]
wp embed-3d-models convert <id>... [--ignore-limit]
wp embed-3d-models set-poster <model-id> <image-id>
wp embed-3d-models check <file>
wp embed-3d-models formats [--format=table|csv|json]
```

To import models with `wp media import`, pass `--user=<someone who can upload>`: the 3D types are only allowed for users with the upload capability.

## Abilities

On WordPress 6.9 and later, in the `embed-3d-models` category, shown in REST under `/wp-abilities/v1/` and marked public for the MCP adapter:

| Ability | Annotations | Input |
| --- | --- | --- |
| `embed-3d-models/list-models` | readonly | `{ limit?, format? }` |
| `embed-3d-models/get-model` | readonly | `{ id }` |
| `embed-3d-models/convert-model` | idempotent | `{ id }` |
| `embed-3d-models/set-model-poster` | idempotent | `{ id, poster_id }` |

## Site Health

**3D model uploads** (Tools > Site Health) warns when 3D types are not allowed (another plugin, or the network's upload file types), when conversions failed, and when USDZ files are served with a Content-Type other than `model/vnd.usdz+zip`.

## Data and uninstall

No options, tables or cron events. Deleting the plugin removes every converted GLB and the `embed_3d_models` metadata key. The uploaded models and poster images stay: they are the site's media.
