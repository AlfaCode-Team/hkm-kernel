import * as React from "react";

/**
 * What the user CHOSE. `system` defers to the OS, and is a real third state —
 * not a synonym for whichever of the two the OS currently reports, because a
 * choice of `system` has to keep tracking the OS when it changes.
 */
export type Theme = "light" | "dark" | "system";

/** What is actually on screen. Never `system`. */
export type ResolvedTheme = "light" | "dark";

interface ThemeContextValue {
  theme: Theme;
  resolvedTheme: ResolvedTheme;
  setTheme: (theme: Theme) => void;
  /** Flip light↔dark. From `system`, flips away from whatever the OS resolves to. */
  toggle: () => void;
}

const STORAGE_KEY = "theme";
const QUERY = "(prefers-color-scheme: dark)";

const ThemeContext = React.createContext<ThemeContextValue>({
  theme: "system",
  resolvedTheme: "light",
  setTheme: () => {},
  toggle: () => {},
});

/**
 * Light / dark / system, applied by toggling `.dark` on the document element.
 *
 * ─── WHY THIS IS NOT THE TWO-STATE VERSION IT USED TO BE ────────────────────
 *
 * It exposed `{ theme, toggle }` with `theme: "light" | "dark"`, and both of its
 * consumers were already written against a THREE-state API:
 *
 *   @pageflow/admin's ThemeToggle destructures `setTheme` and calls it with
 *   "light" | "dark" | "system" — so every click called `undefined`, and the
 *   theme switch in the admin sidebar did nothing at all;
 *
 *   the shared sonner.tsx does `const { theme = 'system' } = useTheme()` and
 *   passes it straight to <Sonner theme={…}>, which understands all three.
 *
 * Neither failure is visible to a type-check or a build: the toggle throws only
 * when a menu item is clicked, and sonner's default silently never applied
 * because `theme` was always defined.
 *
 * `toggle()` is kept because it is a reasonable thing to want and removing it
 * would be a breaking change for anything outside this repo.
 */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setThemeState] = React.useState<Theme>(() => read());
  const [systemDark, setSystemDark] = React.useState<boolean>(() => prefersDark());

  // A `system` choice must keep tracking the OS, so this subscribes for the
  // life of the provider rather than only while `system` is selected —
  // subscribing conditionally would mean the first change after switching away
  // and back is missed.
  React.useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
      return;
    }

    const list = window.matchMedia(QUERY);
    const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches);

    setSystemDark(list.matches);
    list.addEventListener("change", onChange);

    return () => list.removeEventListener("change", onChange);
  }, []);

  const resolvedTheme: ResolvedTheme =
    theme === "system" ? (systemDark ? "dark" : "light") : theme;

  React.useEffect(() => {
    document.documentElement.classList.toggle("dark", resolvedTheme === "dark");
    // Lets CSS and the browser's own form controls follow the theme.
    document.documentElement.style.colorScheme = resolvedTheme;
  }, [resolvedTheme]);

  const setTheme = React.useCallback((next: Theme) => {
    setThemeState(next);
    write(next);
  }, []);

  const toggle = React.useCallback(
    () => setTheme(resolvedTheme === "dark" ? "light" : "dark"),
    [resolvedTheme, setTheme],
  );

  const value = React.useMemo(
    () => ({ theme, resolvedTheme, setTheme, toggle }),
    [theme, resolvedTheme, setTheme, toggle],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export const useTheme = () => React.useContext(ThemeContext);

/**
 * Storage access is guarded in both directions.
 *
 * A private window, a browser set to block site data, and Node 24+ without
 * --localstorage-file all produce a `localStorage` that is missing or that
 * THROWS on access. Reading it unguarded — as this did — takes the whole app
 * down before first paint, for a remembered preference.
 */
function read(): Theme {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);

    return stored === "light" || stored === "dark" || stored === "system" ? stored : "system";
  } catch {
    return "system";
  }
}

function write(theme: Theme): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, theme);
  } catch {
    /* not remembering the choice is not a reason to fail the page */
  }
}

function prefersDark(): boolean {
  try {
    return window.matchMedia(QUERY).matches;
  } catch {
    return false;
  }
}
