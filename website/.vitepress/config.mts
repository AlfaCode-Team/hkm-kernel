import { defineConfig } from 'vitepress'

const repo = 'https://github.com/AlfaCode-Team/hkm-kernel'

// Deploying under a sub-path (GitHub Pages project site: /hkm-kernel/)?
// Set DOCS_BASE at build time. A custom domain or a root deploy needs nothing.
const base = (process.env.DOCS_BASE ?? '/') as `/${string}/` | '/'

export default defineConfig({
  base,
  lang: 'en-US',
  title: 'HKM Kernel',
  description:
    'A modular PHP 8.4+ service framework built on the Gated Demand Architecture: security runs before any module loads, and only the modules a request needs are wired in.',
  cleanUrls: true,
  srcExclude: ['README.md'],
  lastUpdated: true,
  ignoreDeadLinks: false,

  head: [
    ['meta', { name: 'theme-color', content: '#3d7a57' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'HKM Kernel' }],
    ['meta', { property: 'og:image', content: `${base}og-image.png` }],
  ],

  markdown: {
    lineNumbers: false,
    theme: { light: 'github-light', dark: 'github-dark' },
  },

  themeConfig: {
    siteTitle: 'HKM Kernel',

    nav: [
      { text: 'Guide', link: '/guide/introduction', activeMatch: '/guide/' },
      {
        text: 'Concepts',
        items: [
          { text: 'Architecture', link: '/architecture/gda' },
          { text: 'Modules & Plugins', link: '/modules/module-contract' },
          { text: 'HTTP', link: '/http/request' },
          { text: 'Routing', link: '/routing/basics' },
          { text: 'Security', link: '/security/gateway' },
        ],
      },
      {
        text: 'Building',
        items: [
          { text: 'Application Layers', link: '/layers/domain' },
          { text: 'Errors', link: '/errors/exceptions' },
          { text: 'Infrastructure & Ports', link: '/infrastructure/ports' },
          { text: 'Workers & Scheduling', link: '/background/workers-and-jobs' },
          { text: 'CLI', link: '/cli/cli-pipeline' },
          { text: 'Testing', link: '/testing/' },
        ],
      },
      { text: 'Packages', link: '/packages/', activeMatch: '/packages/' },
      {
        text: 'Reference',
        items: [
          { text: 'Environment variables', link: '/reference/env-vars' },
          { text: 'Helper functions', link: '/infrastructure/helpers' },
          { text: 'Anti-patterns', link: '/reference/antipatterns' },
          { text: 'Glossary', link: '/reference/glossary' },
          { text: 'Changelog', link: '/reference/changelog' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        collapsed: false,
        items: [
          { text: 'Introduction', link: '/guide/introduction' },
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Quick Start', link: '/guide/quick-start' },
          { text: 'Directory Structure', link: '/guide/directory-structure' },
          { text: 'Request Lifecycle', link: '/guide/request-lifecycle' },
          { text: 'Configuration & Environment', link: '/guide/configuration' },
        ],
      },
      {
        text: 'Architecture',
        collapsed: false,
        items: [
          { text: 'Gated Demand Architecture', link: '/architecture/gda' },
          { text: 'The Kernel Builder', link: '/architecture/kernel-builder' },
          { text: 'Boot Pipeline & Manifests', link: '/architecture/boot-pipeline' },
          { text: 'Containers & Scopes', link: '/architecture/containers' },
          { text: 'Module Loading', link: '/architecture/module-loading' },
        ],
      },
      {
        text: 'Modules & Plugins',
        collapsed: true,
        items: [
          { text: 'Module Contract & Provider', link: '/modules/module-contract' },
          { text: 'module.json Reference', link: '/modules/module-json' },
          { text: 'Plugins', link: '/modules/plugins' },
          { text: 'Views & Translations', link: '/modules/views-and-lang' },
        ],
      },
      {
        text: 'HTTP',
        collapsed: true,
        items: [
          { text: 'Request', link: '/http/request' },
          { text: 'Response', link: '/http/response' },
          { text: 'HTTP Pipeline & Hooks', link: '/http/pipeline' },
          { text: 'Route Filters', link: '/http/filters' },
          { text: 'Controllers', link: '/http/controllers' },
        ],
      },
      {
        text: 'Routing',
        collapsed: true,
        items: [
          { text: 'Declaring Routes', link: '/routing/basics' },
          { text: 'Route Parameters', link: '/routing/parameters' },
          { text: 'Route Groups', link: '/routing/groups' },
          { text: 'Domains, Subdomains & Faces', link: '/routing/domains' },
          { text: 'Named Routes & URLs', link: '/routing/named-routes' },
          { text: 'Resolution & Overrides', link: '/routing/resolution' },
          { text: 'Routing Cookbook', link: '/routing/cookbook' },
        ],
      },
      {
        text: 'Security',
        collapsed: true,
        items: [
          { text: 'Security Gateway & Identity', link: '/security/gateway' },
          { text: 'CSRF Protection', link: '/security/csrf' },
        ],
      },
      {
        text: 'Application Layers',
        collapsed: true,
        items: [
          { text: 'Domain Layer', link: '/layers/domain' },
          { text: 'Service Layer', link: '/layers/service' },
          { text: 'Repository Layer', link: '/layers/repository' },
          { text: 'Gateway Layer', link: '/layers/gateway' },
          { text: 'Data Access Blueprint', link: '/layers/data-access' },
          { text: 'Events', link: '/layers/events' },
        ],
      },
      {
        text: 'Errors',
        collapsed: true,
        items: [
          { text: 'Exceptions', link: '/errors/exceptions' },
          { text: 'Error Pipeline & Notifiers', link: '/errors/error-pipeline' },
        ],
      },
      {
        text: 'Infrastructure',
        collapsed: true,
        items: [
          { text: 'Ports', link: '/infrastructure/ports' },
          { text: 'Database & Transactions', link: '/infrastructure/database' },
          { text: 'Cache & Locks', link: '/infrastructure/cache-and-locks' },
          { text: 'Observability', link: '/infrastructure/observability' },
          { text: 'Helper Functions', link: '/infrastructure/helpers' },
        ],
      },
      {
        text: 'Background Work',
        collapsed: true,
        items: [
          { text: 'Workers & Jobs', link: '/background/workers-and-jobs' },
          { text: 'Task Scheduling', link: '/background/scheduler' },
        ],
      },
      {
        text: 'CLI',
        collapsed: true,
        items: [
          { text: 'CLI Pipeline & Commands', link: '/cli/cli-pipeline' },
          { text: 'Built-in Commands', link: '/cli/built-in-commands' },
          { text: 'The hkm Launcher', link: '/cli/hkm' },
        ],
      },
      {
        text: 'Packages',
        collapsed: true,
        items: [
          { text: 'Overview', link: '/packages/' },
          { text: 'bind-it (DI engine)', link: '/packages/bind-it' },
          { text: 'http', link: '/packages/http' },
          { text: 'php-io-cli', link: '/packages/php-io-cli' },
          { text: 'let-migrate', link: '/packages/let-migrate' },
          { text: 'hkm-ppkg', link: '/packages/hkm-ppkg' },
          { text: 'ground', link: '/packages/ground' },
        ],
      },
      {
        text: 'Testing & Deployment',
        collapsed: true,
        items: [
          { text: 'Testing', link: '/testing/' },
          { text: 'Production Deployment', link: '/deployment/production' },
          { text: 'Safe Deployments', link: '/deployment/safe-deployments' },
        ],
      },
      {
        text: 'Reference',
        collapsed: true,
        items: [
          { text: 'Environment Variables', link: '/reference/env-vars' },
          { text: 'Anti-patterns', link: '/reference/antipatterns' },
          { text: 'Glossary', link: '/reference/glossary' },
          { text: 'Changelog', link: '/reference/changelog' },
        ],
      },
    ],

    socialLinks: [{ icon: 'github', link: repo }],

    editLink: {
      pattern: `${repo}/edit/main/website/:path`,
      text: 'Edit this page on GitHub',
    },

    search: { provider: 'local' },

    outline: { level: [2, 3], label: 'On this page' },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © AlfaCode Team',
    },
  },
})
