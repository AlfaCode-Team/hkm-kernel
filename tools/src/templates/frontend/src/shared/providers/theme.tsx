import * as React from "react";

type Theme = "light" | "dark";
const ThemeContext = React.createContext<{ theme: Theme; toggle: () => void }>({
  theme: "light",
  toggle: () => {},
});

/** Minimal light/dark provider — swap for your real one as the app grows. */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setTheme] = React.useState<Theme>(
    () => (localStorage.getItem("theme") as Theme) || "light",
  );
  React.useEffect(() => {
    document.documentElement.classList.toggle("dark", theme === "dark");
    localStorage.setItem("theme", theme);
  }, [theme]);
  const toggle = () => setTheme((t) => (t === "light" ? "dark" : "light"));
  return <ThemeContext.Provider value={{ theme, toggle }}>{children}</ThemeContext.Provider>;
}

export const useTheme = () => React.useContext(ThemeContext);
