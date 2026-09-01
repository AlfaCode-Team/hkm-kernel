import type { ViewportChoice } from "./state";

/** Simulated widths, in CSS pixels. `full` means "use the real window". */
export const VIEWPORT_WIDTH: Record<ViewportChoice, number | null> = {
  full: null,
  tablet: 834,
  mobile: 390,
};

type Listener = (event: MediaQueryListEvent) => void;

interface Tracked {
  query: string;
  listeners: Set<Listener>;
  last: boolean;
}

let native: typeof window.matchMedia | null = null;
let simulated: number | null = null;

const tracked = new Set<Tracked>();

/**
 * Answer a width query against a simulated width.
 *
 * Only `min-width` / `max-width` in px are understood. Everything else —
 * `prefers-reduced-motion`, `orientation`, `hover`, `prefers-color-scheme` — is
 * returned as null so the caller delegates to the browser: a simulated WIDTH
 * says nothing about any of them, and answering anyway would be inventing a
 * result.
 */
function evaluate(query: string, width: number): boolean | null {
  const clauses = query.split(/\s+and\s+/i).map((clause) => clause.trim());
  let result = true;

  for (const clause of clauses) {
    const match = /^\(\s*(min|max)-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i.exec(clause);

    if (match === null) {
      return null;
    }

    result = result && (match[1].toLowerCase() === "min" ? width >= Number(match[2]) : width <= Number(match[2]));
  }

  return clauses.length > 0 ? result : null;
}

/** Is this a query the simulation is entitled to answer? */
function isWidthQuery(query: string): boolean {
  return evaluate(query, 0) !== null;
}

function currentlyMatches(query: string): boolean {
  return simulated === null
    ? (native?.(query).matches ?? false)
    : (evaluate(query, simulated) ?? false);
}

/**
 * Route `window.matchMedia` through the bench for the lifetime of the frame.
 *
 * ─── WHY IT INSTALLS EAGERLY, BEFORE ANY SIMULATION ─────────────────────────
 *
 * Installing only when a viewport is picked does not work, and fails in a way
 * that looks like the control is broken rather than absent: the shell has
 * already mounted and already called `window.matchMedia`, so its `change`
 * listener sits on the list the NATIVE function returned. Nothing the shim does
 * afterwards can reach that listener, so `matches` flips, the bench reports a
 * phone, and the desktop sidebar stays exactly where it was.
 *
 * So the shim is installed AT IMPORT and simply tells the truth until asked not
 * to — every subscription goes through it from the first render, and switching
 * viewport notifies the components that are actually listening.
 *
 * Import time, not a mount effect, because React runs effects BOTTOM-UP: the
 * shell is a child of the frame, so `useMediaQuery`'s effect subscribes before
 * any effect the frame could install from. Only a side effect at module load is
 * reliably ahead of the first render — and this module is reached through the
 * frame, which the generated entry imports before it mounts anything.
 *
 * ─── WHY IT IS A GLOBAL MUTATION AT ALL ─────────────────────────────────────
 *
 * `useIsMobile` is `matchMedia("(max-width: 767px)")`, which reports the WINDOW.
 * Constraining a wrapper element to 390px therefore proves nothing: the shell
 * keeps rendering its desktop rail inside a phone-width box, and the mobile
 * drawer — a whole branch of AdminLayout with its own Sheet and its own
 * overflow behaviour — stays unreachable. That branch is the entire reason a
 * viewport control exists, so the window is what has to lie.
 */
export function installViewportShim(): () => void {
  if (typeof window === "undefined" || native !== null) {
    return uninstall;
  }

  native = window.matchMedia.bind(window);

  window.matchMedia = ((query: string) => {
    if (!isWidthQuery(query)) {
      return native!(query);
    }

    const entry: Tracked = { query, listeners: new Set(), last: currentlyMatches(query) };
    tracked.add(entry);

    return {
      get matches() {
        return currentlyMatches(query);
      },
      media: query,
      onchange: null,
      addEventListener: (_type: string, listener: Listener) => void entry.listeners.add(listener),
      removeEventListener: (_type: string, listener: Listener) => {
        entry.listeners.delete(listener);
        if (entry.listeners.size === 0) tracked.delete(entry);
      },
      // Deprecated, and still what some libraries reach for first.
      addListener: (listener: Listener) => void entry.listeners.add(listener),
      removeListener: (listener: Listener) => void entry.listeners.delete(listener),
      dispatchEvent: () => false,
    } as unknown as MediaQueryList;
  }) as typeof window.matchMedia;

  return uninstall;
}

/** Restore the browser's own matchMedia. Exported for tests; the bench itself
 *  keeps the shim for the life of the document. */
export function uninstall(): void {
  simulated = null;

  // Tell every subscriber the truth BEFORE handing the native function back:
  // one that unsubscribes after the restore would otherwise keep the last
  // simulated answer until something unrelated made it re-render.
  notify();

  if (native !== null) {
    window.matchMedia = native;
  }

  native = null;
  tracked.clear();
}

// Installed on import — see installViewportShim() for why an effect is too late.
installViewportShim();

export function setSimulatedWidth(width: number | null): void {
  if (simulated === width) return;

  simulated = width;
  notify();
}

function notify(): void {
  for (const entry of tracked) {
    const now = currentlyMatches(entry.query);

    if (now === entry.last) continue;

    entry.last = now;

    for (const listener of [...entry.listeners]) {
      listener({ matches: now, media: entry.query } as MediaQueryListEvent);
    }
  }
}
