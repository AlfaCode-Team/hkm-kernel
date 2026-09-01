import { useCallback, useEffect, useState } from "react";

export type LayoutChoice = "page" | "bare" | "admin" | "auth";
export type ViewportChoice = "full" | "tablet" | "mobile";
export type PanelChoice = "pages" | "routes" | "inspect" | "shell";

export interface GroundState {
  /** Is the side panel open, or is the bench collapsed to its bar? */
  open: boolean;
  panel: PanelChoice;
  /**
   * Which layout to wrap the page in.
   *
   * "page" is the default and the only one that is not a lie: it honours the
   * page's own `.layout` and falls back to the admin shell exactly as the
   * generated entry does, so what you see is what a project renders. The other
   * three FORCE a layout, which is useful for comparing and misleading if you
   * forget it is set — hence the bar showing it at all times.
   */
  layout: LayoutChoice;
  viewport: ViewportChoice;
  /** Overrides merged over the server's `adminShell` prop. */
  shell: Record<string, unknown> | null;
}

const KEY = "__ground_bench__";

const DEFAULTS: GroundState = {
  open: false,
  panel: "pages",
  layout: "page",
  viewport: "full",
  shell: null,
};

/**
 * Bench state, persisted per browser.
 *
 * localStorage rather than a URL parameter on purpose: these are preferences
 * about the BENCH, and putting them in the URL would change the address you
 * copy out of the bar to share or to curl — which is one of the things the
 * route list is for.
 *
 * Every read and write is guarded. jsdom under vitest, a private window, and a
 * browser set to block site data all produce a localStorage that is absent or
 * that throws on access, and a dev tool that takes the page down in those is
 * worse than one that forgets its panel was open.
 */
export function useGroundState(): [GroundState, (patch: Partial<GroundState>) => void] {
  const [state, setState] = useState<GroundState>(() => {
    try {
      const raw = window.localStorage.getItem(KEY);
      return raw ? { ...DEFAULTS, ...(JSON.parse(raw) as Partial<GroundState>) } : DEFAULTS;
    } catch {
      return DEFAULTS;
    }
  });

  useEffect(() => {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(state));
    } catch {
      /* not persisting is not a reason to fail the page */
    }
  }, [state]);

  const patch = useCallback(
    (next: Partial<GroundState>) => setState((current) => ({ ...current, ...next })),
    [],
  );

  return [state, patch];
}
