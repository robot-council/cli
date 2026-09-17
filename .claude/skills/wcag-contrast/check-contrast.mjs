#!/usr/bin/env node
/**
 * WCAG contrast checker — dependency-free.
 *
 * Computes the WCAG 2.x contrast ratio between a foreground and a background
 * color and reports AA / AAA pass-fail for normal and large text. Accepts
 * `oklch()`, hex (`#rgb` / `#rrggbb`), and `rgb()` colors — which covers both
 * the values DevTools reports for OKLCH-based themes (e.g. Tailwind v4)
 * and ordinary hex from a design spec.
 *
 * Usage:
 *   node check-contrast.mjs "<foreground>" "<background>"
 *   node check-contrast.mjs "oklch(0.37 0.013 285.805)" "oklch(0.236 0.006 286.015)"
 *   node check-contrast.mjs "#71717a" "#27272a"
 *
 * The ratio is symmetric, so foreground / background order does not matter.
 */

/**
 * Parse a CSS color string into linear-light sRGB components in [0, 1].
 *
 * @param  {string}  input  An `oklch()`, hex, or `rgb()` color string.
 * @return {[number, number, number]}  Linear-light sRGB.
 */
function toLinearSrgb(input) {
    const value = input.trim().toLowerCase();

    if (value.startsWith('oklch')) {
        return oklchToLinearSrgb(...parseOklch(value));
    }

    if (value.startsWith('#')) {
        return srgbToLinear(parseHex(value));
    }

    if (value.startsWith('rgb')) {
        return srgbToLinear(parseRgb(value));
    }

    throw new Error(`Unrecognized color: "${input}". Use oklch(), #hex, or rgb().`);
}

/**
 * Parse `oklch(L C H)` into [L (0-1), C, H (degrees)]. Tolerates commas, a
 * percentage lightness, a trailing `/ alpha`, and an omitted hue.
 *
 * @param  {string}  value
 * @return {[number, number, number]}
 */
function parseOklch(value) {
    const body = value.replace(/^oklch\(/, '').replace(/\)$/, '').split('/')[0];
    const parts = body.trim().split(/[\s,]+/).filter(Boolean);
    const lightness = parts[0].endsWith('%') ? parseFloat(parts[0]) / 100 : parseFloat(parts[0]);
    const chroma = parts[1] !== undefined ? parseFloat(parts[1]) : 0;
    const hue = parts[2] !== undefined ? parseFloat(parts[2]) : 0;

    return [lightness, chroma, hue];
}

/**
 * Parse a `#rgb` or `#rrggbb` string into gamma-encoded sRGB in [0, 1].
 *
 * @param  {string}  value
 * @return {[number, number, number]}
 */
function parseHex(value) {
    let hex = value.replace('#', '');

    if (hex.length === 3) {
        hex = hex.split('').map((c) => c + c).join('');
    }

    return [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
}

/**
 * Parse `rgb(r g b)` / `rgb(r, g, b)` (0-255) into gamma-encoded sRGB in [0, 1].
 *
 * @param  {string}  value
 * @return {[number, number, number]}
 */
function parseRgb(value) {
    const body = value.replace(/^rgba?\(/, '').replace(/\)$/, '').split('/')[0];

    return body.trim().split(/[\s,]+/).filter(Boolean).slice(0, 3).map((n) => parseFloat(n) / 255);
}

/**
 * Linearize gamma-encoded sRGB channels (the WCAG transfer function).
 *
 * @param  {[number, number, number]}  rgb
 * @return {[number, number, number]}
 */
function srgbToLinear(rgb) {
    return rgb.map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
}

/**
 * Convert OKLCH to linear-light sRGB via OKLab (Björn Ottosson's matrices).
 *
 * @param  {number}  lightness  0-1.
 * @param  {number}  chroma
 * @param  {number}  hueDegrees
 * @return {[number, number, number]}
 */
function oklchToLinearSrgb(lightness, chroma, hueDegrees) {
    const hue = (hueDegrees * Math.PI) / 180;
    const a = chroma * Math.cos(hue);
    const b = chroma * Math.sin(hue);

    const l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;

    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ];
}

/**
 * WCAG relative luminance from linear-light sRGB.
 *
 * @param  {[number, number, number]}  linear
 * @return {number}
 */
function relativeLuminance(linear) {
    const clamp01 = (c) => Math.min(1, Math.max(0, c));

    return 0.2126 * clamp01(linear[0]) + 0.7152 * clamp01(linear[1]) + 0.0722 * clamp01(linear[2]);
}

/**
 * WCAG contrast ratio between two colors.
 *
 * @param  {string}  foreground
 * @param  {string}  background
 * @return {number}  Ratio in the range 1-21.
 */
function contrastRatio(foreground, background) {
    const l1 = relativeLuminance(toLinearSrgb(foreground));
    const l2 = relativeLuminance(toLinearSrgb(background));
    const [hi, lo] = l1 >= l2 ? [l1, l2] : [l2, l1];

    return (hi + 0.05) / (lo + 0.05);
}

const [, , foreground, background] = process.argv;

if (!foreground || !background) {
    console.error('Usage: node check-contrast.mjs "<foreground>" "<background>"');
    console.error('Colors may be oklch(), #hex, or rgb().');
    process.exit(1);
}

const ratio = contrastRatio(foreground, background);
const mark = (pass) => (pass ? 'PASS' : 'FAIL');

console.log(`foreground : ${foreground}`);
console.log(`background : ${background}`);
console.log(`ratio      : ${ratio.toFixed(2)}:1`);
console.log('');
console.log(`AA  normal text (>= 4.5:1) : ${mark(ratio >= 4.5)}`);
console.log(`AA  large text  (>= 3.0:1) : ${mark(ratio >= 3)}`);
console.log(`AAA normal text (>= 7.0:1) : ${mark(ratio >= 7)}`);
console.log(`AAA large text  (>= 4.5:1) : ${mark(ratio >= 4.5)}`);

// Exit non-zero when the pair fails AA for normal text, so the script is usable
// as a gate in scripts and CI.
process.exit(ratio >= 4.5 ? 0 : 1);
