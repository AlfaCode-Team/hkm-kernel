import { createHeadManager, PageHandler, router } from '@pageflow/core'
import { createElement, useEffect, useMemo, useState } from 'react'
import HeadContext from './HeadContext'
import PageContext from './PageContext'

let currentIsInitialPage = true
let routerIsInitialized = false
let swapComponent: PageHandler = async () => {
  // Dummy function so we can init the router outside of the useEffect hook. This is
  // needed so `router.reload()` works right away (on mount) in any of the user's
  // components. We swap in the real function in the useEffect hook below.
  currentIsInitialPage = false
}

export default function App({
  children,
  initialPage,
  initialComponent,
  resolveComponent,
  titleCallback,
  onHeadUpdate,
}) {
  const [current, setCurrent] = useState({
    component: initialComponent || null,
    page: initialPage,
    key: null,
  })

  const headManager = useMemo(() => {
    return createHeadManager(
      typeof window === 'undefined',
      titleCallback || ((title) => title),
      onHeadUpdate || (() => {}),
    )
  }, [])

  if (!routerIsInitialized) {
    router.init({
      initialPage,
      resolveComponent,
      swapComponent: async (args) => swapComponent(args),
    })

    routerIsInitialized = true
  }

  useEffect(() => {
    swapComponent = async ({ component, page, preserveState }) => {
      if (currentIsInitialPage) {
        // We block setting the current page on the initial page to
        // prevent the initial page from being re-rendered again.
        currentIsInitialPage = false
        return
      }

      setCurrent((current) => ({
        component,
        page,
        key: preserveState ? current.key : Date.now(),
      }))
    }

    router.on('navigate', () => headManager.forceUpdate())
  }, [])

  // Server-driven tab title. On an XHR navigation the server's seoFor()/
  // seoPrivate() helpers send the page's suffixed TITLE STRING as the reserved
  // `seoHead` prop (on a full page load the same prop is the rendered SEO HTML
  // block, which the HTML shell consumes and strips from the client payload —
  // so it never reaches here). Writing document.title updates the FIRST <title>
  // element in the document, i.e. the server-rendered one, so no duplicate tags
  // are ever created. Anything containing markup is ignored — plain text only.
  useEffect(() => {
    const seoHead = current.page?.props?.seoHead
    if (typeof seoHead === 'string' && seoHead !== '' && !seoHead.includes('<')) {
      document.title = seoHead
    }
  }, [current.page])

  if (!current.component) {
    return createElement(
      HeadContext.Provider,
      { value: headManager },
      createElement(PageContext.Provider, { value: current.page }, null),
    )
  }

  const renderChildren =
    children ||
    (({ Component, props, key }) => {
      const child = createElement(Component, { key, ...props })

      if (typeof Component.layout === 'function') {
        return Component.layout(child)
      }

      if (Array.isArray(Component.layout)) {
        return Component.layout
          .concat(child)
          .reverse()
          .reduce((children, Layout) => createElement(Layout, { children, ...props }))
      }

      return child
    })

  return createElement(
    HeadContext.Provider,
    { value: headManager },
    createElement(
      PageContext.Provider,
      { value: current.page },
      renderChildren({
        Component: current.component,
        key: current.key,
        props: current.page.props,
      }),
    ),
  )
}

App.displayName = 'Pageflow'
