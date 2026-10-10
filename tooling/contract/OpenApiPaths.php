<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

/**
 * Reads the operations openapi.yaml declares, without a YAML dependency.
 *
 * The contract is authored in three shapes that all parse as YAML, because
 * three slices were written by different hands: block mappings, a flow
 * mapping on the path key's own line, and a flow mapping pretty-printed
 * under the key or under one method of it. A general YAML parser is not
 * available to a PHP tool here (the core takes no runtime dependencies and
 * neither adapter ships one), so this reader handles exactly those shapes
 * and refuses anything else rather than guessing.
 *
 * What it must never do is silently under-report: a path item it cannot read
 * would look like an operation nobody declared, which is the one answer that
 * makes a parity check worse than useless. An unreadable item raises.
 */
final class OpenApiPaths
{
    /** The methods an OpenAPI path item may carry. */
    public const METHODS = ['get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'];

    /**
     * The root extension that allow-lists the operations no free build
     * serves. It is one list rather than a marker on each of seventy-four
     * operations because the three authoring shapes above make a per
     * operation insertion three different edits, and because one list is
     * reviewable in a single diff. The parity check enforces it in both
     * directions, so it cannot rot into a comment.
     */
    public const EDITIONS_EXTENSION = 'x-vidyalms-editions';

    /**
     * Every declared operation as "METHOD /path", sorted.
     *
     * @return list<string>
     */
    public static function operations(string $file): array
    {
        $operations = [];

        foreach (self::items(self::lines($file)) as $item) {
            foreach (self::methodsOf($item) as $method) {
                $operations[] = strtoupper($method) . ' ' . $item['key'];
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * Every declared operation with the parameter references it carries and
     * the status codes it declares.
     *
     * This is what binds a parameter to a status. RequiredIdempotencyKey means
     * the write commits its claim with its mutation and refuses an absent
     * header with 400, and thirty eight operations referenced the parameter
     * while none declared the status, so the refusal was unreachable through
     * the generated client on eleven of them that carry no `default` either.
     *
     * Same rule as everywhere else in this reader: a shape it cannot read
     * raises rather than reporting an operation with no parameters and no
     * responses, which is the answer that would make the check pass wrongly.
     *
     * @return list<array{operationId: string, path: string, method: string,
     *     parameters: list<string>, responses: list<string>}>
     */
    public static function operationContracts(string $file): array
    {
        $contracts = [];

        foreach (self::items(self::lines($file)) as $item) {
            $decoded = self::decode($item);

            $operations = $decoded !== null
                ? self::flowContracts($item, $decoded)
                : self::blockContracts($item);

            foreach ($operations as $operation) {
                $contracts[] = $operation;
            }
        }

        return $contracts;
    }

    /**
     * @param array{key: string, start: int, end: int, inline: bool, text: string} $item
     * @param array<string, mixed>                                                 $decoded
     *
     * @return list<array{operationId: string, path: string, method: string,
     *     parameters: list<string>, responses: list<string>}>
     */
    private static function flowContracts(array $item, array $decoded): array
    {
        $shared = self::references($decoded['parameters'] ?? []);
        $found = [];

        foreach ($decoded as $method => $operation) {
            $method = strtolower((string) $method);

            if (!\in_array($method, self::METHODS, true) || !\is_array($operation)) {
                continue;
            }

            $found[] = self::contract(
                $item['key'],
                $method,
                \is_string($operation['operationId'] ?? null) ? $operation['operationId'] : '',
                array_merge($shared, self::references($operation['parameters'] ?? [])),
                \is_array($operation['responses'] ?? null) ? array_map(strval(...), array_keys($operation['responses'])) : [],
                $item['start'] + 1,
            );
        }

        return $found;
    }

    /**
     * The "$ref" values of a parameter list, ignoring inline parameters.
     *
     * @param mixed $parameters
     *
     * @return list<string>
     */
    private static function references(mixed $parameters): array
    {
        if (!\is_array($parameters)) {
            return [];
        }

        $found = [];

        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['$ref'] ?? null)) {
                $found[] = $parameter['$ref'];
            }
        }

        return $found;
    }

    /**
     * A block-mapping path item, read by indentation: methods four spaces in,
     * their members six, and a member's own entries eight.
     *
     * @param array{key: string, start: int, end: int, inline: bool, text: string} $item
     *
     * @return list<array{operationId: string, path: string, method: string,
     *     parameters: list<string>, responses: list<string>}>
     */
    private static function blockContracts(array $item): array
    {
        /** @var list<string> $shared */
        $shared = [];
        // Three parallel maps rather than one map of shapes: the reader
        // mutates the parameter and response lists as it walks, and a nested
        // array-shape loses its element types the moment it is appended to.
        /** @var array<string, string> $operationIds */
        $operationIds = [];
        /** @var array<string, list<string>> $operationParameters */
        $operationParameters = [];
        /** @var array<string, list<string>> $operationResponses */
        $operationResponses = [];
        $method = null;
        $section = null;
        $lines = explode("\n", $item['text']);
        $count = \count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];

            if (trim($line) === '') {
                continue;
            }

            $indent = self::indentOf($line);

            if ($indent === 4) {
                $method = null;
                $section = null;

                if (preg_match('/^ {4}"?([A-Za-z$][A-Za-z0-9_-]*)"?:(.*)$/', $line, $m) !== 1) {
                    continue;
                }

                $name = strtolower($m[1]);
                self::refuseInline($item, $line, $name, trim($m[2]));

                if (\in_array($name, self::METHODS, true)) {
                    // The fourth authoring shape: one method of a block path
                    // item written as pretty JSON under its own key.
                    $json = self::jsonUnder($lines, $index + 1, $count);

                    if ($json !== null) {
                        [$decoded, $index] = $json;
                        $operationIds[$name] = \is_string($decoded['operationId'] ?? null)
                            ? $decoded['operationId'] : '';
                        $operationParameters[$name] = self::references($decoded['parameters'] ?? []);
                        $operationResponses[$name] = \is_array($decoded['responses'] ?? null)
                            ? array_values(array_map(strval(...), array_keys($decoded['responses'])))
                            : [];

                        continue;
                    }

                    $method = $name;
                    $operationIds[$method] = '';
                    $operationParameters[$method] = [];
                    $operationResponses[$method] = [];
                } elseif ($name === 'parameters') {
                    $section = 'shared';
                }

                continue;
            }

            if ($section === 'shared') {
                if ($indent === 6 && preg_match('/^ {6}- \$ref: "([^"]+)"\s*$/', $line, $m) === 1) {
                    $shared[] = $m[1];
                }

                continue;
            }

            if ($method === null) {
                continue;
            }

            if ($indent === 6) {
                $section = null;

                if (preg_match('/^ {6}"?([A-Za-z$][A-Za-z0-9_-]*)"?:(.*)$/', $line, $m) !== 1) {
                    continue;
                }

                $key = $m[1];
                $rest = trim($m[2]);

                if ($key === 'operationId') {
                    $operationIds[$method] = $rest;

                    continue;
                }

                if ($key === 'parameters' || $key === 'responses') {
                    self::refuseInline($item, $line, $key, $rest);
                    $section = $key;
                }

                continue;
            }

            if ($indent !== 8) {
                continue;
            }

            if ($section === 'parameters' && preg_match('/^ {8}- \$ref: "([^"]+)"\s*$/', $line, $m) === 1) {
                $operationParameters[$method][] = $m[1];

                continue;
            }

            if ($section === 'responses' && preg_match('/^ {8}"?([A-Za-z0-9]+)"?:/', $line, $m) === 1) {
                $operationResponses[$method][] = $m[1];
            }
        }

        $found = [];

        foreach ($operationIds as $name => $operationId) {
            $found[] = self::contract(
                $item['key'],
                $name,
                $operationId,
                array_merge($shared, $operationParameters[$name] ?? []),
                $operationResponses[$name] ?? [],
                $item['start'] + 1,
            );
        }

        return $found;
    }

    /**
     * The pretty JSON object that starts at $from, when one does, with the
     * index of its last line.
     *
     * @param list<string> $lines
     *
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    private static function jsonUnder(array $lines, int $from, int $count): ?array
    {
        while ($from < $count && trim($lines[$from]) === '') {
            $from++;
        }

        if ($from >= $count || trim($lines[$from]) !== '{') {
            return null;
        }

        $block = [];
        $last = $from;

        for ($i = $from; $i < $count; $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }

            if (self::indentOf($lines[$i]) < 6) {
                break;
            }

            $block[] = $lines[$i];
            $last = $i;
        }

        $decoded = json_decode(implode("\n", $block), true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                'A method written as flow JSON at line %d does not parse: %s.',
                $from + 1,
                json_last_error_msg(),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return [$decoded, $last];
    }

    /**
     * A flow mapping where this reader expects a block one is a shape it does
     * not understand, and reporting no parameters for it would be a silent
     * under-report.
     *
     * @param array{key: string, start: int, end: int, inline: bool, text: string} $item
     */
    private static function refuseInline(array $item, string $line, string $key, string $rest): void
    {
        if ($rest === '' || !\in_array($key, ['parameters', 'responses'], true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Path item "%s" writes "%s" as a flow value this reader does not parse: %s',
            $item['key'],
            $key,
            trim($line),
        ));
    }

    /**
     * @param list<string> $parameters
     * @param list<string> $responses
     *
     * @return array{operationId: string, path: string, method: string,
     *     parameters: list<string>, responses: list<string>}
     */
    private static function contract(
        string $path,
        string $method,
        string $operationId,
        array $parameters,
        array $responses,
        int $line,
    ): array {
        if ($operationId === '' || $responses === []) {
            throw new \RuntimeException(sprintf(
                '%s %s at line %d has no operationId or declares no responses, which this reader will not '
                . 'report as an empty contract.',
                strtoupper($method),
                $path,
                $line,
            ));
        }

        return [
            'operationId' => $operationId,
            'path' => $path,
            'method' => $method,
            'parameters' => array_values(array_unique($parameters)),
            'responses' => array_values(array_unique($responses)),
        ];
    }

    /**
     * The operations the contract says only the Pro tier serves.
     *
     * @return list<string>
     */
    public static function proOperations(string $file): array
    {
        return self::editionOperations($file, 'pro');
    }

    /**
     * The operations the contract says only the Business tier serves.
     *
     * A second list rather than a second marker, and a second list rather than
     * one merged one, because the two answer different questions. Route parity
     * asks "is this served by a free build", for which the union is the right
     * answer and {@see paidOperations()} gives it. The edition table and the
     * SDK's own documentation ask "which tier buys this", which only the split
     * can answer.
     *
     * There is no `business-core` package in this repository: Business
     * providers are families inside packages/pro-core flagged by
     * `BusinessEdition`, registered through the same discovery seam, so
     * attaching Pro attaches Business too and both lists are the routes one
     * attach adds. That is a deliberate deviation, recorded in ADR 0021 and in
     * the edition table, and it is why nothing here boots a third kernel.
     *
     * @return list<string>
     */
    public static function businessOperations(string $file): array
    {
        return self::editionOperations($file, 'business');
    }

    /**
     * Every operation no free build serves, of either paid tier.
     *
     * @return list<string>
     */
    public static function paidOperations(string $file): array
    {
        $paid = array_values(array_unique([
            ...self::proOperations($file),
            ...self::businessOperations($file),
        ]));

        sort($paid);

        return $paid;
    }

    /**
     * One edition's list out of the root extension block.
     *
     * An edition the block does not name answers an empty list rather than
     * raising: a contract written before a tier existed is not malformed. A
     * missing block, on the other hand, still raises, because then nothing at
     * all says which operations are paid and every one of them would read as
     * free.
     *
     * @return list<string>
     */
    private static function editionOperations(string $file, string $edition): array
    {
        $lines = self::lines($file);
        $start = null;

        foreach ($lines as $i => $line) {
            if (rtrim($line) === self::EDITIONS_EXTENSION . ':') {
                $start = $i;
                break;
            }
        }

        if ($start === null) {
            throw new \RuntimeException(sprintf(
                'openapi.yaml declares no root "%s" block, so nothing says which operations are Pro.',
                self::EDITIONS_EXTENSION,
            ));
        }

        $inEdition = false;
        $found = [];

        for ($i = $start + 1; $i < \count($lines); $i++) {
            $line = $lines[$i];

            if (trim($line) === '') {
                continue;
            }

            if (self::indentOf($line) === 0) {
                break;
            }

            if (preg_match('/^  ([a-z_]+):/', $line, $m) === 1) {
                $inEdition = $m[1] === $edition;

                continue;
            }

            if ($inEdition && preg_match('/^    - "?([A-Z]+ \/\S*?)"? *$/', $line, $m) === 1) {
                $found[] = $m[1];
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    public static function lines(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false || $contents === '') {
            throw new \RuntimeException('Cannot read ' . $file . '.');
        }

        return explode("\n", str_replace("\r\n", "\n", $contents));
    }

    /**
     * The raw path items, each with the exact lines that carry it, so a
     * writer can splice one back without reformatting the rest of the file.
     *
     * @param list<string> $lines
     *
     * @return list<array{key: string, start: int, end: int, inline: bool, text: string}>
     */
    public static function items(array $lines): array
    {
        return self::sliceItems($lines, 'paths:', 2);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{key: string, start: int, end: int, inline: bool, text: string}>
     */
    public static function sliceItems(array $lines, string $section, int $indent): array
    {
        $start = null;

        foreach ($lines as $i => $line) {
            if (rtrim($line) === $section) {
                $start = $i;
                break;
            }
        }

        if ($start === null) {
            throw new \RuntimeException('No "' . $section . '" section.');
        }

        $sectionIndent = \strlen($section) - \strlen(ltrim($section));
        $end = \count($lines);

        for ($i = $start + 1; $i < \count($lines); $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }

            if (self::indentOf($lines[$i]) <= $sectionIndent) {
                $end = $i;
                break;
            }
        }

        $pad = str_repeat(' ', $indent);
        $pattern = '/^' . $pad . '("(?<quoted>[^"]+)"|(?<bare>[^\s"][^:]*)):(?<rest>.*)$/';
        $items = [];
        $current = null;

        for ($i = $start + 1; $i < $end; $i++) {
            $line = $lines[$i];

            if (self::indentOf($line) === $indent && preg_match($pattern, $line, $m) === 1) {
                if ($current !== null) {
                    $items[] = self::close($current, $i - 1, $lines);
                }

                $key = ($m['quoted'] ?? '') !== '' ? $m['quoted'] : trim($m['bare']);
                $rest = trim($m['rest']);
                $current = ['key' => $key, 'start' => $i, 'end' => $i, 'inline' => $rest !== '', 'text' => $rest];

                continue;
            }

            if ($current !== null) {
                $current['text'] .= "\n" . $line;
            }
        }

        if ($current !== null) {
            $items[] = self::close($current, $end - 1, $lines);
        }

        return $items;
    }

    /**
     * The decoded item when it is written as flow JSON, or null when it is a
     * block mapping this reader only scans.
     *
     * @param array{key: string, start: int, end: int, inline: bool, text: string} $item
     *
     * @return array<string, mixed>|null
     */
    public static function decode(array $item): ?array
    {
        $text = trim($item['text']);

        if (!str_starts_with($text, '{')) {
            return null;
        }

        $decoded = json_decode($text, true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                'Item "%s" at line %d opens as flow JSON but does not parse: %s.',
                $item['key'],
                $item['start'] + 1,
                json_last_error_msg(),
            ));
        }

        return $decoded;
    }

    /**
     * The HTTP methods one path item declares.
     *
     * @param array{key: string, start: int, end: int, inline: bool, text: string} $item
     *
     * @return list<string>
     */
    public static function methodsOf(array $item): array
    {
        $decoded = self::decode($item);

        if ($decoded !== null) {
            $found = [];

            foreach ($decoded as $method => $operation) {
                $method = strtolower((string) $method);

                if (!\in_array($method, self::METHODS, true)) {
                    continue;
                }

                if (!\is_array($operation)) {
                    throw new \RuntimeException(sprintf('"%s" %s is not an operation object.', $item['key'], $method));
                }

                $found[] = $method;
            }

            return $found;
        }

        return self::scanBlock($item);
    }

    /**
     * A block-mapping path item, read by indentation.
     *
     * Method keys sit four spaces in, quoted or bare, and their members six,
     * so a method name inside a block scalar cannot be mistaken for a method:
     * a scalar's content is always indented past the key that introduced it.
     *
     * @param array{key: string, start: int, end: int, inline: bool, text: string} $item
     *
     * @return list<string>
     */
    private static function scanBlock(array $item): array
    {
        $found = [];
        $members = 0;

        foreach (explode("\n", $item['text']) as $line) {
            if (trim($line) === '' || self::indentOf($line) !== 4) {
                continue;
            }

            if (preg_match('/^ {4}"?([A-Za-z$][A-Za-z0-9_-]*)"?:/', $line, $m) !== 1) {
                continue;
            }

            $members++;

            if (\in_array(strtolower($m[1]), self::METHODS, true)) {
                $found[] = strtolower($m[1]);
            }
        }

        // A path item may legitimately carry only shared parameters, but one
        // whose members this reader could not see at all is a shape it does
        // not understand and must not report as empty.
        if ($members === 0) {
            throw new \RuntimeException(sprintf(
                'Path item "%s" at line %d is in a shape this reader does not understand.',
                $item['key'],
                $item['start'] + 1,
            ));
        }

        return $found;
    }

    /**
     * @param array{key: string, start: int, end: int, inline: bool, text: string} $current
     * @param list<string>                                                         $lines
     *
     * @return array{key: string, start: int, end: int, inline: bool, text: string}
     */
    private static function close(array $current, int $end, array $lines): array
    {
        while ($end > $current['start'] && trim($lines[$end]) === '') {
            $end--;
        }

        $current['end'] = $end;
        $current['text'] = rtrim($current['text']);

        return $current;
    }

    public static function indentOf(string $line): int
    {
        return \strlen($line) - \strlen(ltrim($line, ' '));
    }
}
