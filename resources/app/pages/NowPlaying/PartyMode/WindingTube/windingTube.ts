/******************************************************************************
 * windingTube
 * A tube that winds as you fly down it — the geometry both tunnel visualisers (EQWarp and
 * WarpTunnel) are drawn inside. This half is the CPU's: the path's shape, and where its rings
 * land on the screen each frame. windingTubeGlsl.ts is the shader's half.
 *
 * THE PATH. The tube's centre line is a sum of two sines per axis, with wavenumbers that share no
 * simple ratio, so the bends never settle into a pattern the eye can learn; the phases are rolled
 * afresh for every party, so no two flights wind the same way. It is taken relative to the
 * camera's own position and heading, so the near rings stay centred and the far end swings away.
 *
 * WHY THE CPU DRAWS THE PATH AND EVERY PIXEL WALKS IT (windingTubeGlsl → `hit`): with the tube
 * winding, one screen point can lie on more than one ring — the near wall on the outside of a bend,
 * and the far tube behind it. Only the NEAREST is what you see, and solving per pixel from a guess
 * finds whichever root it falls into: drawn, that is a worm wriggling at the end of the tunnel.
 * Walking forty sampled rings from near to far finds the first wall the line of sight leaves the
 * tube through, and costs the shader no trigonometry at all.
 *
 * IN DOUBLE PRECISION FROM THE UNWRAPPED TRAVEL. A shader's own travel is wrapped once a lap to
 * keep float32 precise, and a path read from that would snap back to its first bend.
 *****************************************************************************/

/**
 * The tube's scale: a pixel `r` from the centre (in units of the screen's short side) sees the
 * wall `TUBE / r` ahead. 0.32 puts the near edge of a 16:9 screen about a third of a unit away.
 */
export const TUBE = 0.32;

/** The inner tube the stars ride on: smaller, so they pass faster than the walls (parallax). */
export const STAR_TUBE = 0.11;

/** Star cells per world unit along the inner tube. */
export const STAR_ROWS = 3;

/**
 * The star field's period in cells. A renderer wraps the stars' travel at `STAR_PERIOD /
 * STAR_ROWS` units and the shader hashes cell rows modulo the same number, so the wrap swaps a row
 * for an identical one instead of reshuffling the sky.
 */
export const STAR_PERIOD = 3000;

/** One sine component of the path: how fast it winds (per world unit) and how far. */
export type CurveComponent = {
    /** Wavenumber, radians per world unit — 0.25 is one full swing every ~25 units of flight. */
    k: number;
    /** How far the tube's centre swings, in world units (the tube's radius is TUBE, 0.32). */
    amplitude: number;
};

/** The path's components, two per axis — see the module note. */
export const CURVE: { x: readonly [CurveComponent, CurveComponent]; y: readonly [CurveComponent, CurveComponent] } = {
    x: [
        { k: 0.25, amplitude: 1.2 },
        { k: 0.41, amplitude: 0.6 }
    ],
    y: [
        { k: 0.19, amplitude: 0.9 },
        { k: 0.53, amplitude: 0.45 }
    ]
};

/** How many rings of the path the shader is given each frame. */
export const PATH_SAMPLES = 40;

/** The nearest sampled ring's depth: closer than anything on screen, the stars' inner tube included. */
export const PATH_NEAR = 0.1;

/**
 * The farthest sampled ring's depth. A line of sight that has not left the tube by here sees past
 * its end, so a visualiser must have faded its walls out by this depth.
 */
export const PATH_FAR = 8;

/**
 * The depth of sample `index`, spaced geometrically from PATH_NEAR to PATH_FAR — evenly in what
 * the eye sees, since a ring's size on screen goes as one over its depth.
 */
export function pathDepth(index: number): number {
    return PATH_NEAR * (PATH_FAR / PATH_NEAR) ** (index / (PATH_SAMPLES - 1));
}

/**
 * How far the tube's centre `depth` units ahead of the camera sits from the camera's line of sight,
 * in world units: the path's value there, minus its value AND its slope at the camera. Taking the
 * slope out is what points the camera along the tube.
 */
export function pathOffset(travel: number, offsets: readonly number[], depth: number): [number, number] {
    /** One axis: the sum of its two components, relative to the camera's position and heading. */
    const axis = (components: readonly CurveComponent[], first: number): number =>
        components.reduce((total, { k, amplitude }, index) => {
            const phase = k * travel + (offsets[first + index] ?? 0);

            return (
                total + amplitude * (Math.sin(phase + k * depth) - Math.sin(phase) - k * depth * Math.cos(phase))
            );
        }, 0);

    return [axis(CURVE.x, 0), axis(CURVE.y, 2)];
}

/**
 * Where each sampled ring's centre lands on the screen, written into `out` as x, y pairs — the
 * shader's `uPath`. A ring `d` ahead is drawn `1 / d` the size, so its centre is the path's offset
 * divided by its depth.
 */
export function pathCentres(travel: number, offsets: readonly number[], out: Float32Array): void {
    for (let index = 0; index < PATH_SAMPLES; index++) {
        const depth = pathDepth(index);
        const [x, y] = pathOffset(travel, offsets, depth);
        out[index * 2] = x / depth;
        out[index * 2 + 1] = y / depth;
    }
}

/** The uniforms windingTubeGlsl declares, which {@link setTube} sets. */
export const TUBE_UNIFORMS = ["uPath", "uGlowCentre"] as const;

/** One party's path: its rolled phases, and the arrays its frames are written into. */
export type WindingPath = {
    /** Where on the path this party starts, per sine component — random, so every party winds its own way. */
    offsets: readonly number[];
    /** The sampled rings' screen centres, rewritten by {@link setTube}. */
    centres: Float32Array;
};

/** Roll a path for one party. `random` is injectable so a test can pin it. */
export function windingPath(random: () => number = Math.random): WindingPath {
    return {
        offsets: Array.from({ length: 4 }, () => random() * Math.PI * 2),
        centres: new Float32Array(PATH_SAMPLES * 2)
    };
}

/**
 * Sample `path` where the camera is (`travel`, unwrapped) and set the tube's uniforms: the rings,
 * and the light at the end, centred where the path has taken the ring at `glowDepth` — the last
 * the eye can see — so the light stays at the end of the visible tunnel as it winds.
 */
export function setTube(
    gl: WebGL2RenderingContext,
    uniforms: Record<string, WebGLUniformLocation | null>,
    path: WindingPath,
    travel: number,
    glowDepth: number
): void {
    pathCentres(travel, path.offsets, path.centres);
    gl.uniform2fv(uniforms.uPath ?? null, path.centres);
    const [x, y] = pathOffset(travel, path.offsets, glowDepth);
    gl.uniform2f(uniforms.uGlowCentre ?? null, x / glowDepth, y / glowDepth);
}
