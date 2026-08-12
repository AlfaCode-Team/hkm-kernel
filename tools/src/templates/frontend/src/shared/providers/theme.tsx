import * as React from "react";

/**
 * Three-state theme: an explicit choice, or "system" (the default) which follows
 * the OS and keeps following it if the OS setting changes mid-session.
 *
 * The theme belongs to the PROJECT, not to a plugin — `@pageflow/admin`'s
 * `<ThemeToggle>` is only a control over this context, so two plugins can never
 * end up fighting over the app's appearance.
 */
export type Theme = "light" | "dark" | "system";

/** What is actually painted — "system" resolved against the OS preference. */
export type ResolvedTheme = "light" | "dark";

interface ThemeContextValue {
  /** The user's choice, including "system". */
  theme: Theme;
  /** What that currently resolves to. */
  resolvedTheme: ResolvedTheme;
  setTheme: (theme: Theme) => void;
  /** Flip between light and dark. From "system" it flips away from the OS value. */
  toggle: () => void;
}

const STORAGE_KEY = "theme";

const ThemeContext = React.createContext<ThemeContextValue>({
  theme: "system",
  resolvedTheme: "light",
  setTheme: () => {},
  toggle: () => {},
});

function systemTheme(): ResolvedTheme {
  if (typeof window === "undefined") return "light";
  return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

function storedTheme(): Theme {
  if (typeof window === "undefined") return "system";
  const stored = window.localStorage.getItem(STORAGE_KEY);
  return stored === "light" || stored === "dark" || stored === "system" ? stored : "system";
}

export function ThemeProvider({
  children,
  defaultTheme = "system",
}: {
  children: React.ReactNode;
  defaultTheme?: Theme;
}) {
  const [theme, setThemeState] = React.useState<Theme>(() => storedTheme() ?? defaultTheme);
  const [systemPref, setSystemPref] = React.useState<ResolvedTheme>(systemTheme);

  // Keep following the OS while the choice is "system".
  React.useEffect(() => {
    const query = window.matchMedia("(prefers-color-scheme: dark)");
    const onChange = (event: MediaQueryListEvent) =>
      setSystemPref(event.matches ? "dark" : "light");

    setSystemPref(query.matches ? "dark" : "light");
    query.addEventListener("change", onChange);
    return () => query.removeEventListener("change", onChange);
  }, []);

  const resolvedTheme: ResolvedTheme = theme === "system" ? systemPref : theme;

  React.useEffect(() => {
    document.documentElement.classList.toggle("dark", resolvedTheme === "dark");
    document.documentElement.style.colorScheme = resolvedTheme;
  }, [resolvedTheme]);

  const setTheme = React.useCallback((next: Theme) => {
    setThemeState(next);
    window.localStorage.setItem(STORAGE_KEY, next);
  }, []);

  const value = React.useMemo<ThemeContextValue>(
    () => ({
      theme,
      resolvedTheme,
      setTheme,
      toggle: () => setTheme(resolvedTheme === "dark" ? "light" : "dark"),
    }),
    [theme, resolvedTheme, setTheme],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export const useTheme = () => React.useContext(ThemeContext);
