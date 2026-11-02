=== PicSqueeze V2===
Contributors: roguerazor
Tags: images, optimization, compression, webp, avif
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
Licence: GPLv2

Reduce image file sizes, convert to next-gen formats, and bulk-optimize your media library — fully local, free and open source.

== Description ==

PicSqueeze compresses images on your own server using WordPress' built-in image engines. No external service, no API key, no quota, no account.

Features:

* Automatic optimization on upload
* Three compression levels: Normal, Aggressive, Ultra
* WebP and AVIF conversion (when your server supports them)
* Bulk optimization with a progress bar
* Original image backup and one-click restore
* Optimization stats per image in the Media library

== Installation ==

1. Upload the `picsqueeze` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open Settings → PicSqueeze and choose your compression level.

== Changelog ==

= 2.0.0 =
* Switched from a cloud service to fully local compression — no API key needed.
* Added server capability detection for WebP/AVIF.

= 1.0.0 =
* Initial release.
