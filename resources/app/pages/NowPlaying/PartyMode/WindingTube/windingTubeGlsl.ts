/******************************************************************************
 * windingTubeGlsl
 * The shader's half of the winding tube (windingTube.ts is the CPU's): the uniforms it reads, the
 * per-pixel walk that finds which ring of the tube a pixel sees, and the star streaks that ride an
 * inner tube along the same path.
 *
 * Meant to open a plugin's fragment body, after glsl.ts's library (it uses `hash12` and the
 * palette). The constants are interpolated from windingTube.ts, so the renderer's sampling and the
 * shader's walk cannot disagree about where the rings are.
 *****************************************************************************/
import { glslFloat } from "../Shader/glsl";
import { PATH_SAMPLES, STAR_PERIOD, STAR_ROWS, STAR_TUBE, TUBE, pathDepth } from "./windingTube";

/** One over each sampled ring's depth, as a GLSL array literal — the ring's size on screen per unit of tube. */
function inverseDepths(): string {
    const values = Array.from({ length: PATH_SAMPLES }, (_, index) => glslFloat(1 / pathDepth(index)));

    return `float[${PATH_SAMPLES}](${values.join(", ")})`;
}

/** The tube's uniform declarations, for a plugin's `uniforms` block. */
// language=GLSL
export const TUBE_UNIFORMS_GLSL = `
uniform vec2 uPath[${PATH_SAMPLES}];
uniform vec2 uGlowCentre;
`;

/** The tube's constants and functions, each commented where it is defined. */
// language=GLSL
export const TUBE_GLSL = `
const float TUBE = ${glslFloat(TUBE)};
const float STAR_TUBE = ${glslFloat(STAR_TUBE)};
const float STAR_ROWS = ${glslFloat(STAR_ROWS)};
const float STAR_PERIOD = ${glslFloat(STAR_PERIOD)};
const int PATH_SAMPLES = ${PATH_SAMPLES};
const float INVERSE_DEPTHS[PATH_SAMPLES] = ${inverseDepths()};

// An angle difference folded back into (-PI, PI]: across the seam at the roof, atan jumps a full
// turn between two neighbouring pixels, and an unfolded derivative there draws a hairline.
float folded(float d) {
    return d - TAU * floor(d / TAU + 0.5);
}

// Which ring of a winding tube of radius tube the screen point p sees: the point's position in that
// ring's own cross-section (xy), the ring's depth (z), and whether it sees a wall at all (w).
//
// Each sampled ring is a circle on the screen, centred where the path puts it (uPath), radius tube
// over its depth. Nearer rings are bigger, so p starts inside them; the first ring p lies OUTSIDE
// of is where its line of sight leaves the tube, i.e. the nearest wall. Walking from near to far is
// what makes that the visible one where a bend lines two walls up. Between the last ring that
// contains p and the first that does not, the circle is interpolated and the crossing found by two
// false-position steps; interpolating the RADIUS linearly is exact for a straight tube, since a
// ring's size goes as one over its depth. A line of sight that never leaves the sampled tube sees
// past its end (w = 0).
vec4 hit(vec2 p, float tube) {
    vec2 nearCentre = uPath[0];
    float nearRadius = tube * INVERSE_DEPTHS[0];
    float nearGap = length(p - nearCentre) - nearRadius;
    // Outside even the nearest ring: only an extreme corner of a very wide screen, on the stars'
    // narrow tube. It sees the nearest wall.
    if (nearGap >= 0.0) return vec4(p - nearCentre, tube / nearRadius, 1.0);

    for (int index = 1; index < PATH_SAMPLES; index++) {
        vec2 farCentre = uPath[index];
        float farRadius = tube * INVERSE_DEPTHS[index];
        float farGap = length(p - farCentre) - farRadius;
        if (farGap >= 0.0) {
            float low = 0.0;
            float high = 1.0;
            float lowGap = nearGap;
            float highGap = farGap;
            float t = 0.0;
            for (int refine = 0; refine < 2; refine++) {
                t = mix(low, high, lowGap / (lowGap - highGap));
                float gap = length(p - mix(nearCentre, farCentre, t)) - mix(nearRadius, farRadius, t);
                if (gap < 0.0) { low = t; lowGap = gap; } else { high = t; highGap = gap; }
            }
            t = mix(low, high, lowGap / (lowGap - highGap));
            return vec4(p - mix(nearCentre, farCentre, t), tube / mix(nearRadius, farRadius, t), 1.0);
        }
        nearCentre = farCentre;
        nearRadius = farRadius;
        nearGap = farGap;
    }
    return vec4(p - nearCentre, tube / nearRadius, 0.0);
}

// Star streaks on a narrower tube along the same path, so they bend with the walls: travel is how
// far they have come (wrapped at STAR_PERIOD / STAR_ROWS by the renderer), speed stretches them,
// density is the share of cells holding a star, and brightness scales the lot. They fade out well
// before the far end, which on a winding tube is dark.
vec3 starStreaks(vec2 p, mat2 roll, float travel, float speed, float density, float brightness) {
    vec4 inner = hit(p, STAR_TUBE);
    vec2 point = roll * inner.xy;
    vec2 cell = vec2(atan(point.x, -point.y) / TAU * 96.0, (inner.z + travel) * STAR_ROWS);
    vec2 id = vec2(floor(cell.x), mod(floor(cell.y), STAR_PERIOD));
    float pick = hash12(id);
    if (pick <= 1.0 - density) return vec3(0.0);

    vec2 offset = vec2(hash12(id + 7.1), hash12(id + 3.3)) - 0.5;
    vec2 d = fract(cell) - 0.5 - offset * 0.6;
    float stretch = 1.0 + speed * 1.5;
    float star = exp(-(d.x * d.x) / 0.004 - (d.y * d.y) / (0.004 * stretch * stretch));
    float fade = (1.0 - smoothstep(3.0, 6.0, inner.z)) * smoothstep(0.04, 0.2, length(point)) * inner.w;
    float strength = (pick - (1.0 - density)) / density;
    return mix(uHigh, uGlow, offset.x + 0.5) * star * strength * brightness * fade;
}
`;
