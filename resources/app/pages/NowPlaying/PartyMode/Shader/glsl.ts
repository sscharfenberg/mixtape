/******************************************************************************
 * glsl
 * The GLSL every party-mode visualiser shares: the one-triangle vertex shader, the uniforms the
 * host sets for every plugin, and a small library — hashing, value noise, fbm, the level ramp and
 * the bounded beat lift.
 *
 * ONE COPY, BECAUSE EACH OF THESE IS A RULE RATHER THAN A HELPER. The ramp is the Now Playing
 * bars' magenta → violet → blue, and a plugin that wrote its own would drift from it; `finish` is
 * where a beat may brighten a picture and by how much, which is a photosensitivity limit (WCAG
 * 2.3.1, with the beat itself rate-limited in audioFrame) that no plugin should be able to forget.
 *
 * GLSL ARRIVES AS STRINGS, assembled at runtime and compiled by the driver (see program.ts). The
 * `// language=GLSL` comments let an IDE with a GLSL plugin highlight them as what they are.
 *****************************************************************************/

/** A number as a GLSL float literal — `7` is an int in GLSL and would not compile as a float. */
export function glslFloat(value: number): string {
    return Number.isInteger(value) ? `${value}.0` : String(value);
}

/**
 * One triangle, three times the screen's size, from `gl_VertexID` alone — no vertex buffer and no
 * attribute at all, which WebGL2 allows against its default vertex array. Every visualiser draws
 * its whole picture in the fragment shader over this.
 */
// language=GLSL
export const FULLSCREEN_VERTEX = `#version 300 es
void main() {
    vec2 corner = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
    gl_Position = vec4(corner * 2.0 - 1.0, 0.0, 1.0);
}
`;

/**
 * The uniforms the host sets for every plugin built with `shaderPlugin` (see there for what each
 * carries). The energies are `uMids`, not `uMid`, because `uMid` is the ramp's middle colour.
 */
// language=GLSL
const COMMON_UNIFORMS = `
uniform vec2 uResolution;
uniform float uTime;
uniform float uBass;
uniform float uMids;
uniform float uTreble;
uniform float uLevel;
uniform float uBeat;
uniform vec3 uSpace;
uniform vec3 uGrid;
uniform vec3 uLow;
uniform vec3 uMid;
uniform vec3 uHigh;
uniform vec3 uGlow;
`;

/** The shared functions. Each is commented where it is defined, in GLSL. */
// language=GLSL
const LIBRARY = `
const float TAU = 6.28318530718;

// A cheap, sin-free hash, 0-1: sin() of large arguments loses precision on mobile GPUs and the
// pattern visibly bands.
float hash12(vec2 p) {
    p = fract(p * vec2(123.34, 456.21));
    p += dot(p, p + 45.32);
    return fract(p.x * p.y);
}

// Two independent hashes of one point.
vec2 hash22(vec2 p) {
    float first = hash12(p);
    return vec2(first, hash12(p + first + 17.17));
}

// Value noise, 0-1, smoothly interpolated between hashed lattice points.
float noise(vec2 p) {
    vec2 cell = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash12(cell), hash12(cell + vec2(1.0, 0.0)), u.x),
        mix(hash12(cell + vec2(0.0, 1.0)), hash12(cell + vec2(1.0, 1.0)), u.x),
        u.y
    );
}

// Five octaves of noise, each a little turned so the lattice never lines up: about 0-1.
float fbm(vec2 p) {
    float total = 0.0;
    float amplitude = 0.5;
    mat2 turn = mat2(0.8, 0.6, -0.6, 0.8);
    for (int octave = 0; octave < 5; octave++) {
        total += amplitude * noise(p);
        p = turn * p * 2.02;
        amplitude *= 0.5;
    }
    return total;
}

// The level ramp, quiet to loud — the Now Playing bars' three stops.
vec3 ramp(float v) {
    v = clamp(v, 0.0, 1.0);
    return v < 0.5 ? mix(uLow, uMid, v * 2.0) : mix(uMid, uHigh, v * 2.0 - 1.0);
}

// A rotation by angle, radians.
mat2 rotation(float angle) {
    float c = cos(angle);
    float s = sin(angle);
    return mat2(c, s, -s, c);
}

// This pixel, centred on the screen and scaled so the screen's SHORT side spans -0.5 to 0.5.
vec2 centred() {
    return (gl_FragCoord.xy - 0.5 * uResolution) / min(uResolution.x, uResolution.y);
}

// The last step every picture takes: a beat lifts it by at most a tenth — felt, never a flash —
// and nothing leaves brighter than white.
vec3 finish(vec3 colour) {
    return min(colour * (1.0 + 0.1 * uBeat), vec3(1.0));
}
`;

/**
 * A complete fragment shader: version, precision, the common uniforms, the plugin's own `uniforms`
 * declarations, the library, then the plugin's `body` (which declares `void main()` and writes
 * `outColor`).
 */
export function fragmentShader(uniforms: string, body: string): string {
    return `#version 300 es
precision highp float;
${COMMON_UNIFORMS}${uniforms}
out vec4 outColor;
${LIBRARY}
${body}`;
}
