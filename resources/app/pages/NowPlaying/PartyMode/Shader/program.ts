/******************************************************************************
 * program
 * The WebGL2 plumbing every visualiser needs and none of them is about: getting a context,
 * compiling and linking a program with the driver's own errors, looking uniforms up by name, and
 * surviving a lost context.
 *
 * A SHADER THAT WILL NOT COMPILE THROWS, it does not return null. Null means "this browser cannot"
 * (no WebGL2) and the host shows a party without a picture; a compile error is a bug in a plugin,
 * which the host reports. Only a real browser compiles GLSL, which is why the E2E suite asserts
 * that every registered plugin reaches `running`.
 *****************************************************************************/
import type { Rgb, VisualizerPalette } from "../visualizers";
import { FULLSCREEN_VERTEX } from "./glsl";

/** Uniform locations by name. A null location is an unused uniform the driver optimised away. */
export type Uniforms<Name extends string> = Record<Name, WebGLUniformLocation | null>;

/** The palette's uniforms, as every shader built on glsl.ts declares them. */
export const PALETTE_UNIFORMS = ["uSpace", "uGrid", "uLow", "uMid", "uHigh", "uGlow"] as const;

/**
 * A WebGL2 context on `canvas`, or null where there is none.
 *
 * No alpha, depth, stencil or antialiasing: every picture is one opaque full-screen triangle, and
 * each of those buffers would be memory and fill-rate spent on nothing.
 */
export function createContext(canvas: HTMLCanvasElement): WebGL2RenderingContext | null {
    return canvas.getContext("webgl2", {
        alpha: false,
        antialias: false,
        depth: false,
        stencil: false,
        premultipliedAlpha: false,
        preserveDrawingBuffer: false,
        powerPreference: "high-performance"
    });
}

/** Compile one shader stage, throwing with the driver's log when it fails — see the module note. */
function compile(gl: WebGL2RenderingContext, type: number, source: string, name: string): WebGLShader {
    const shader = gl.createShader(type);
    if (shader === null) throw new Error(`${name}: could not create a shader`);

    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS) && !gl.isContextLost()) {
        const log = gl.getShaderInfoLog(shader);
        gl.deleteShader(shader);
        throw new Error(`${name}: shader did not compile — ${log ?? "no log"}`);
    }

    return shader;
}

/**
 * Compile and link `fragment` over the full-screen triangle, throwing with the driver's log on
 * failure. `name` labels the error, so a report says which plugin is broken.
 */
export function buildProgram(gl: WebGL2RenderingContext, fragment: string, name: string): WebGLProgram {
    const vertexShader = compile(gl, gl.VERTEX_SHADER, FULLSCREEN_VERTEX, name);
    const fragmentShader = compile(gl, gl.FRAGMENT_SHADER, fragment, name);
    const program = gl.createProgram();

    gl.attachShader(program, vertexShader);
    gl.attachShader(program, fragmentShader);
    gl.linkProgram(program);
    // The stages are part of the program once linked; nothing else will ever ask for them.
    gl.deleteShader(vertexShader);
    gl.deleteShader(fragmentShader);

    if (!gl.getProgramParameter(program, gl.LINK_STATUS) && !gl.isContextLost()) {
        throw new Error(`${name}: program did not link — ${gl.getProgramInfoLog(program) ?? "no log"}`);
    }

    return program;
}

/** Look every name in `names` up in `program`. */
export function uniformLocations<Name extends string>(
    gl: WebGL2RenderingContext,
    program: WebGLProgram,
    names: readonly Name[]
): Uniforms<Name> {
    return Object.fromEntries(names.map(name => [name, gl.getUniformLocation(program, name)])) as Uniforms<Name>;
}

/** Set the six palette uniforms. The program must be in use. */
export function setPalette(
    gl: WebGL2RenderingContext,
    uniforms: Uniforms<(typeof PALETTE_UNIFORMS)[number]>,
    palette: VisualizerPalette
): void {
    /** One colour uniform from one palette entry. */
    const colour = (location: WebGLUniformLocation | null, rgb: Rgb): void => {
        gl.uniform3f(location, rgb[0], rgb[1], rgb[2]);
    };

    colour(uniforms.uSpace, palette.space);
    colour(uniforms.uGrid, palette.grid);
    colour(uniforms.uLow, palette.low);
    colour(uniforms.uMid, palette.mid);
    colour(uniforms.uHigh, palette.high);
    colour(uniforms.uGlow, palette.glow);
}

/**
 * Keep a renderer alive through a lost context: `lost` runs when the GPU takes it away and
 * `restored` when it comes back. Answers the function that removes both listeners.
 *
 * The loss's default is cancelled, which is what makes a restore possible at all — without it a
 * lost WebGL context stays lost. A phone does this to a backgrounded tab when it needs the memory.
 */
export function guardContext(canvas: HTMLCanvasElement, lost: () => void, restored: () => void): () => void {
    /** Ask for the context back, and drop everything that belonged to the old one. */
    const onLost = (event: Event): void => {
        event.preventDefault();
        lost();
    };

    canvas.addEventListener("webglcontextlost", onLost);
    canvas.addEventListener("webglcontextrestored", restored);

    return () => {
        canvas.removeEventListener("webglcontextlost", onLost);
        canvas.removeEventListener("webglcontextrestored", restored);
    };
}

/**
 * Hand the GPU memory back now rather than whenever the canvas is collected — a party can open
 * and close many times in one session, and the host gives every plugin a fresh canvas.
 */
export function releaseContext(gl: WebGL2RenderingContext): void {
    gl.getExtension("WEBGL_lose_context")?.loseContext();
}
