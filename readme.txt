=== Embed 3D Models ===
Contributors: pratiklondhe
Tags: 3d, glb, stl, 3d viewer, augmented reality
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload GLB, glTF, STL and OBJ files to the Media Library and show them in posts as 3D models visitors can rotate, with a block.

== Description ==

WordPress does not let you upload 3D files ("Sorry, you are not allowed to upload this file type"), and has no way to show one. Embed 3D Models adds both: upload the file like an image, insert the **3D Model** block, publish. Visitors can turn the model around with the mouse or a finger.

**Upload 3D files safely**

GLB, glTF, STL, OBJ and USDZ files can be uploaded by anyone who can upload images. Each file is checked by its content, not just its name: a `.glb` must really be a binary glTF 2.0 file, an `.stl` must really be an STL, and text files that carry PHP or a script tag are refused. When a file is refused, you are told why.

**STL and OBJ are converted for you**

STL files (from 3D printing and CAD) and OBJ files are converted to GLB when you upload them. The conversion streams the file, so large models do not run the server out of memory. STL files are turned upright and scaled from millimetres, so they look right straight away. The original file is kept.

**The 3D Model block**

* Upload a model, pick one from the Media Library, or insert one from a URL.
* The editor shows the real model, so what you see is what visitors get.
* Alternative text, a caption, height and background colour.
* Rotate automatically, let visitors rotate, let visitors zoom (off by default, so scrolling the page never gets stuck on the model), play animations.
* Brightness and shadow.
* **Save current camera angle** to start from the view you like.
* **Use current view as poster** saves a picture of the model. It shows while the model loads and becomes the model's thumbnail in the Media Library.
* Load when scrolled into view (default), right away, or only when the visitor clicks **Load 3D model**.
* Optional augmented reality ("View in your space") on phones and tablets, with an optional USDZ file for iPhone and iPad.

**Light on your site**

The viewer is Google's open source `<model-viewer>`, bundled with the plugin together with its decoders for compressed models. Nothing is loaded from a CDN, and the viewer only loads on pages that show a 3D model.

**For developers**

Filters and actions for every default, limit and message, JavaScript hooks in the editor, `wp embed-3d-models` WP-CLI commands, REST routes, and Abilities API abilities (WordPress 6.9+) so AI agents can list models, convert them and set posters. See [the reference](https://github.com/pratik-londhe4/embed-3d-models/blob/main/docs/REFERENCE.md).

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking.

== Installation ==

1. Install from Plugins > Add New Plugin, or upload the zip.
2. Activate it.
3. Upload a 3D file to the Media Library, or add the **3D Model** block to a post and upload it there.

== Frequently Asked Questions ==

= Which formats can I use? =

GLB (recommended), glTF with everything embedded, STL and OBJ. STL and OBJ are converted to GLB at upload. USDZ can be uploaded too, as the iPhone and iPad version of a model for augmented reality.

= My glTF file was refused. Why? =

glTF files often come with separate files (a `.bin` file and texture images) that cannot be uploaded together with them. Export the model as a single `.glb` file from your 3D tool (Blender: File > Export > glTF 2.0, format "glTF Binary") and upload that.

= My OBJ model is grey. =

OBJ colours and textures live in separate `.mtl` and image files, which cannot come along with a single upload, so converted OBJ and STL models get a neutral grey. For colours and textures, upload a GLB.

= A large STL was not converted. =

Files over 64 MB are not converted during the upload, to keep uploads fast. The block tells you so. Ask whoever manages the server to run `wp embed-3d-models convert <attachment ID> --ignore-limit`, or raise the limit with the `embed_3d_models_max_convert_bytes` filter.

= Who can upload 3D files? =

Everyone who can upload images (the `upload_files` capability). To limit 3D uploads to administrators: `add_filter( 'embed_3d_models_upload_capability', fn() => 'manage_options' );`

= Does it work on multisite? =

Yes. On a network, add `glb gltf usdz stl obj` to **Upload file types** in Network Admin > Settings. Tools > Site Health tells you when they are missing.

= Augmented reality does not start on my iPhone. =

iPhones and iPads need a USDZ version of the model (choose it in the block's Augmented reality panel), and your server must send USDZ files as `model/vnd.usdz+zip`. Tools > Site Health checks this.

== External services ==

The plugin makes no requests to other services itself. The viewer, its decoders and every file it uses are bundled.

When you turn on **augmented reality** in a block, visitors on Android who tap "View in your space" are handed over to Google's Scene Viewer app (part of Google Play Services), which downloads the model from your site to show it. The model's address is passed to that app; nothing else is sent. This only happens when a visitor taps the button. Google's [terms of service](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy) apply. On iPhone and iPad, AR Quick Look runs on the device.

== Source code and libraries ==

The plugin's own code is not minified. Its source, tests and build tools are on [GitHub](https://github.com/pratik-londhe4/embed-3d-models).

Bundled libraries, unchanged from their releases:

* [model-viewer](https://github.com/google/model-viewer) 4.4.0 by Google, Apache-2.0: `assets/vendor/model-viewer/model-viewer-umd.min.js` is `dist/model-viewer-umd.min.js` from the [@google/model-viewer npm package](https://www.npmjs.com/package/@google/model-viewer/v/4.4.0). Readable source: [packages/model-viewer/src](https://github.com/google/model-viewer/tree/v4.4.0/packages/model-viewer/src).
* Draco decoder and Basis Universal (KTX2) transcoder from [three.js](https://github.com/mrdoob/three.js/tree/r183/examples/jsm/libs) r183, MIT (sources: [Draco](https://github.com/google/draco), [Basis Universal](https://github.com/BinomialLLC/basis_universal)).
* [meshoptimizer](https://github.com/zeux/meshoptimizer) 1.3.0 decoder, MIT.

== Screenshots ==

1. A 3D model in a post, with its caption.
2. The 3D Model block in the editor, with its settings.
3. Uploading an STL file: it is converted to GLB and shown right away.
4. 3D models in the Media Library, with posters as thumbnails.

== Changelog ==

= 1.0.0 =
* First version.
