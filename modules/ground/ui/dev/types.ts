/**
 * What the bench knows about the plugin it is showing.
 *
 * Generated into `ui/.ground/src/ground.manifest.ts` by DevWorkspace, from the
 * plugin's own module.json and ui.json — so it is regenerated on every
 * `ground dev` and cannot describe a plugin that has since changed.
 *
 * It is deliberately STATIC. Anything that varies per request (what the fake
 * database was asked, what mail went nowhere, which events fired) is absent,
 * because `ground serve` boots fresh for every request and that data would be
 * a request out of date before it was read.
 */
export interface GroundManifest {
  /** The plugin's `name` from module.json. */
  name: string;
  /** Its `solves` domain. */
  solves: string;
  /** Module domains it declares a dependency on. */
  requires: string[];
  /** Contracts it publishes. */
  exposes: string[];
  /** Integration events it says it emits. */
  emits: string[];
  /** Surface name → the pages directory it globs, from ui.json. */
  surfaces: Record<string, string>;
  pages: GroundPage[];
  routes: GroundRoute[];
}

export interface GroundPage {
  /** The Pageflow component name, e.g. "User/Index". */
  component: string;
  /** Which face it was authored under: "admin" or "site". */
  face: string;
  /** The route path that renders it, when one could be matched. */
  path: string | null;
}

export interface GroundRoute {
  method: string;
  /** The FULL path, with every group and module prefix already applied. */
  path: string;
  handler: string;
  name: string | null;
  filters: string[];
  requires: string[];
  /** True when the path takes a {parameter} and cannot be visited as written. */
  dynamic: boolean;
}
