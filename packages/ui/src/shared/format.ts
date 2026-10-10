/*
 * Formatting every app shares. Anything an app formats for itself stays in
 * that app (src/instructor/lib/format.ts holds the workbench's datetime
 * readings), so this file only grows when two apps need the same words.
 */

const BYTE_UNITS = ['bytes', 'KB', 'MB', 'GB'];

/** A byte count in the shortest unit that keeps it readable: "2.1 MB", "812 bytes". */
export function formatBytes(bytes: number): string {
    let value = Math.max(0, bytes);
    let unit = 0;
    while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }
    const rounded = unit === 0 ? Math.round(value) : Math.round(value * 10) / 10;
    return `${rounded} ${BYTE_UNITS[unit] ?? 'bytes'}`;
}
