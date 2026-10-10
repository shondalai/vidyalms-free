<?php

/**
 * VidyaLMS i18n compiler.
 *
 * Reads a neutral catalogue and emits:
 *   - Joomla INI (en-GB) with uppercased underscore keys
 *   - a gettext POT template, only for a WordPress text domain that is not
 *     translated on translate.wordpress.org (see WORDPRESS_ORG_TEXT_DOMAINS)
 *   - with --wordpress=<dir>, the two PHP files a WordPress plugin ships:
 *     catalogue.php (key => English) and gettext.php (one literal _x() call
 *     per key, so the WordPress.org string extractor can see every message)
 *
 * Usage:
 *   php tooling/i18n/compile.php [--catalogue=<file|dir>] [--domain=<name>]
 *                                [--textdomain=<name>] [--out=<dir>]
 *                                [--wordpress=<dir>]
 *
 * Defaults compile the free build's catalogue
 * (packages/core/i18n/catalogue.php) into en-GB.com_vidyalms.ini. A second
 * package passes --catalogue and --domain: Pro compiles
 * packages/pro-core/i18n/catalogue with --domain=com_vidyalms_pro, which
 * lands beside the free files rather than over them and also writes
 * vidyalms-pro.pot.
 *
 * **--catalogue takes a directory as well as a file**, and Pro passes one.
 * Its strings are owned by provider groups discovered from the filesystem,
 * one catalogue file per group, so fourteen question types arriving at once
 * are fourteen new files rather than fourteen edits to one. Every *.php in
 * the directory is read in name order and merged, and **a key two files both
 * define is a hard error**: two families cannot see each other's catalogue,
 * so a silent override is a label changing for a reason nobody can find,
 * while a failed build names both files.
 *
 * The Joomla constant names do not follow the domain. Every VidyaLMS string
 * lives in one COM_VIDYALMS_ constant namespace whichever file ships it, so
 * a string that moves from Pro into the free build keeps its constant and
 * every template that names it keeps working; only the file it is packaged
 * in changes.
 *
 * Both platforms keep {name} placeholders verbatim; the adapters substitute
 * them at render time, so translators see one placeholder syntax.
 */

declare(strict_types=1);

/**
 * The WordPress text domains translate.wordpress.org translates.
 *
 * The free plugin is hosted in the WordPress.org directory, so its template is
 * built there from the plugin's own code (the literal calls in gettext.php) and
 * its translations arrive only as language packs. A POT for it would be a
 * second template nobody reads, and the directory review asked for the plugin's
 * languages folder to go. The Pro add-on is distributed outside the directory,
 * so its translators still need the POT this script writes.
 */
const WORDPRESS_ORG_TEXT_DOMAINS = ['vidyalms'];

$root = dirname(__DIR__, 2);

$cataloguePath = $root . '/packages/core/i18n/catalogue.php';
$outDir = $root . '/tooling/i18n/out';
$domain = 'com_vidyalms';
$textDomain = null;
$wordpressDir = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } elseif (str_starts_with($arg, '--wordpress=')) {
        $wordpressDir = rtrim(substr($arg, 12), '/\\');
    } elseif (str_starts_with($arg, '--catalogue=')) {
        $cataloguePath = substr($arg, 12);
    } elseif (str_starts_with($arg, '--domain=')) {
        $domain = substr($arg, 9);
    } elseif (str_starts_with($arg, '--textdomain=')) {
        $textDomain = substr($arg, 13);
    } else {
        fwrite(STDERR, "Unknown argument {$arg}\n");
        exit(1);
    }
}

if (!is_file($cataloguePath) && !is_dir($cataloguePath)) {
    fwrite(STDERR, "No catalogue at {$cataloguePath}\n");
    exit(1);
}

if (!preg_match('/^[a-z0-9_]+$/', $domain)) {
    fwrite(STDERR, "A domain is a lowercase identifier, got {$domain}\n");
    exit(1);
}

// com_vidyalms becomes vidyalms, com_vidyalms_pro becomes vidyalms-pro:
// the WordPress text domain of the plugin that ships the same strings.
$textDomain ??= str_replace('_', '-', preg_replace('/^com_/', '', $domain) ?? $domain);

// One file, or every *.php in a directory merged in name order. A duplicate
// key across two files is refused rather than resolved: the later file
// winning would change a label for a reason nobody looking at either file
// could find.
$catalogueFiles = is_dir($cataloguePath) ? (glob(rtrim($cataloguePath, '/\\') . '/*.php') ?: []) : [$cataloguePath];
sort($catalogueFiles);

if ($catalogueFiles === []) {
    fwrite(STDERR, "The catalogue directory {$cataloguePath} holds no files.\n");
    exit(1);
}

// The include runs in the including scope, so a catalogue file that used a
// variable of the same name as one of these would overwrite it. Reading it
// through a closure gives it a scope of its own.
$readCatalogue = static function (string $file): mixed {
    return require $file;
};

/** @var array<string, string> $catalogue */
$catalogue = [];
/** @var array<string, string> $definedIn */
$definedIn = [];

foreach ($catalogueFiles as $catalogueFile) {
    $loaded = $readCatalogue($catalogueFile);

    if (!is_array($loaded)) {
        fwrite(STDERR, "The catalogue at {$catalogueFile} does not return an array.\n");
        exit(1);
    }

    foreach ($loaded as $key => $value) {
        if (!is_string($key) || !is_string($value)) {
            fwrite(STDERR, "The catalogue at {$catalogueFile} holds a non-string key or value.\n");
            exit(1);
        }

        if (isset($definedIn[$key])) {
            fwrite(
                STDERR,
                "The string key \"{$key}\" is defined twice:\n"
                . "  {$definedIn[$key]}\n"
                . "  {$catalogueFile}\n"
            );
            exit(1);
        }

        $definedIn[$key] = $catalogueFile;
        $catalogue[$key] = $value;
    }
}

if ($catalogue === []) {
    fwrite(STDERR, "The catalogue at {$cataloguePath} is empty; refusing to write empty files.\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

// How the banner names its own source and the command that rebuilds it, so
// a generated file always says which catalogue it came from.
$sourcePath = str_replace('\\', '/', (string) realpath($cataloguePath));
$rootPath = str_replace('\\', '/', (string) realpath($root)) . '/';
$source = str_starts_with($sourcePath, $rootPath) ? substr($sourcePath, \strlen($rootPath)) : $cataloguePath;

$command = 'php tooling/i18n/compile.php';

if ($source !== 'packages/core/i18n/catalogue.php') {
    $command .= ' --catalogue=' . $source;
}

if ($domain !== 'com_vidyalms') {
    $command .= ' --domain=' . $domain;
}

$iniFile = $outDir . '/en-GB.' . $domain . '.ini';
$potFile = $outDir . '/' . $textDomain . '.pot';

// Joomla INI: keys become COM_VIDYALMS_MAIL_ENROLLED_SUBJECT style; values are
// double-quoted with embedded quotes escaped as \" .
//
// It used to write "_QQ_", which is Joomla 3's token and which Joomla 4 and
// later do not convert: LanguageHelper::parseIniFile parses with
// INI_SCANNER_RAW and then post-processes exactly one form, str_replace('\"',
// '"'). The old escaping therefore reached a learner verbatim, and the first
// catalogue string that contained a quote was the first to show it, which is
// how it was found: a grade row read `The "_QQ_"vidyalms-pro"_QQ_" package
// provides ...`.
$ini = "; VidyaLMS language strings, generated from {$source}.\n";
$ini .= "; Do not edit by hand; run: {$command}\n\n";

foreach ($catalogue as $key => $value) {
    $iniKey = strtoupper(str_replace(['vidyalms.', '.'], ['com_vidyalms.', '_'], $key));
    $iniValue = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\n'], $value);
    $ini .= sprintf("%s=\"%s\"\n", $iniKey, $iniValue);
}

file_put_contents($iniFile, $ini);

// Read it back the way Joomla reads it, and fail the build when a string does
// not survive the round trip.
//
// LanguageHelper::parseIniFile parses with INI_SCANNER_RAW and then does
// exactly one post-process, str_replace('\"', '"'), so anything else this
// script invents about escaping reaches a learner verbatim. It did: the
// previous "_QQ_" form is Joomla 3's token, Joomla 4 and later do not convert
// it, and the first catalogue string that contained a quote was the first to
// show it. Asserting the round trip here is cheaper than finding it in a grade
// row again.
$roundTripped = parse_ini_string($ini, false, INI_SCANNER_RAW);

if (!is_array($roundTripped)) {
    fwrite(STDERR, "The generated Joomla INI does not parse.\n");

    exit(1);
}

/** @var array<string, string> $roundTripped */
$roundTripped = str_replace('\"', '"', $roundTripped);

foreach ($catalogue as $key => $value) {
    $iniKey = strtoupper(str_replace(['vidyalms.', '.'], ['com_vidyalms.', '_'], $key));
    $expected = str_replace("\n", '\n', $value);

    if (($roundTripped[$iniKey] ?? null) !== $expected) {
        fwrite(
            STDERR,
            "The Joomla INI does not round-trip \"{$key}\".\n"
            . "  wrote : {$expected}\n"
            . '  read  : ' . ($roundTripped[$iniKey] ?? '(missing)') . "\n"
        );

        exit(1);
    }
}

$written = [$iniFile];

// Gettext POT: msgid is the neutral English string; msgctxt carries the key
// so identical strings in different roles stay separately translatable. Only
// for a domain translate.wordpress.org does not serve, for the reason given at
// WORDPRESS_ORG_TEXT_DOMAINS.
if (!in_array($textDomain, WORDPRESS_ORG_TEXT_DOMAINS, true)) {
    $pot = "# VidyaLMS translation template, generated from {$source}.\n";
    $pot .= "# Do not edit by hand; run: {$command}\n";
    $pot .= "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Project-Id-Version: VidyaLMS\\n\"\n\n";

    $po = static function (string $text): string {
        $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $text);

        return '"' . $escaped . '"';
    };

    foreach ($catalogue as $key => $value) {
        $pot .= 'msgctxt ' . $po($key) . "\n";
        $pot .= 'msgid ' . $po($value) . "\n";
        $pot .= "msgstr \"\"\n\n";
    }

    file_put_contents($potFile, $pot);
    $written[] = $potFile;
}

// The two files a WordPress plugin ships. The translator port resolves a core
// key through catalogue.php at runtime and asks gettext.php for the
// translation, so both are runtime files and not only build inputs. The
// context, English text and domain of every call match the POT contract above
// exactly, so a key translated through a POT (Pro) and one translated through
// a language pack (free) are looked up the same way.
if ($wordpressDir !== null) {
    if (!is_dir($wordpressDir) && !mkdir($wordpressDir, 0775, true)) {
        fwrite(STDERR, "Cannot create {$wordpressDir}\n");
        exit(1);
    }

    $catalogueOut = $wordpressDir . '/catalogue.php';
    $gettextOut = $wordpressDir . '/gettext.php';

    $banner = " * GENERATED by tooling/i18n/compile.php from {$source}; never edit.\n"
        . " * Rebuild with: {$command} --wordpress=<dir>\n";

    // Flattened with var_export, so the shipped file does not depend on the
    // repository layout a directory catalogue is read from.
    $catalogueSource = "<?php\n\n/**\n * VidyaLMS string catalogue, key => English.\n *\n" . $banner
        . " *\n * @license GPL-3.0-or-later\n */\n\ndefined('ABSPATH') || exit;\n\nreturn "
        . var_export($catalogue, true) . ";\n";

    $domainLiteral = var_export($textDomain, true);
    $gettextSource = "<?php\n\n/**\n * VidyaLMS gettext calls for the {$textDomain} text domain.\n *\n" . $banner
        . " *\n"
        . " * This file exists so translate.wordpress.org can extract every string the\n"
        . " * plugin shows. Its extractor reads literal gettext calls only, and the\n"
        . " * engine names its strings by key, so each key is spelled out here as one\n"
        . " * _x() call whose context is the key. The plugin ships no translations of\n"
        . " * its own: they come only from the language packs WordPress installs for\n"
        . " * this text domain.\n"
        . " *\n * @license GPL-3.0-or-later\n */\n\ndefined('ABSPATH') || exit;\n\n"
        . "return static function (string \$key, string \$fallback): string {\n    return match (\$key) {\n";

    foreach ($catalogue as $key => $english) {
        $gettextSource .= '        ' . var_export($key, true) . ' => _x(' . var_export($english, true)
            . ', ' . var_export($key, true) . ', ' . $domainLiteral . "),\n";
    }

    $gettextSource .= "        default => \$fallback,\n    };\n};\n";

    foreach ([$catalogueOut => $catalogueSource, $gettextOut => $gettextSource] as $file => $contents) {
        if (file_put_contents($file, $contents) === false) {
            fwrite(STDERR, "Cannot write {$file}\n");
            exit(1);
        }

        $written[] = $file;
    }
}

printf("Compiled %d strings from %s.\n  %s\n", count($catalogue), $source, implode("\n  ", $written));
