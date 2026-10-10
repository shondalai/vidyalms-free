# lib_vidyalms_core

The Joomla library extension that ships the VidyaLMS core.

This directory holds only two things: the manifest (`vidyalms_core.xml`) and the language files under
`language/en-GB`. Everything else in the installed library is generated.

`src/` is a build-time copy of `packages/core/src`, made by `build/build.php` on every run and ignored by git.
Never edit it, never commit it, and never fix a core bug here: fix it in `packages/core` and rebuild.

The manifest declares `<namespace path="src">Shondalai\VidyaLMS\Core</namespace>`, so Joomla's own PSR-4
autoloader serves the core from `libraries/vidyalms_core/src` once the library is installed. The core takes no
dependencies beyond `psr/clock` and `psr/log`, both bundled with Joomla 6, so no Composer vendor directory is
shipped.

The library installs no tables. The VidyaLMS schema is owned and installed by `com_vidyalms`, which is also the
extension whose uninstall drops it.
