import svgLoader from 'vite-svg-loader'
import vuetify from 'vite-plugin-vuetify'
import { fileURLToPath } from 'node:url'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  app: {
    // Served from /admin-panel/ inside the web root, not from /. Without this
    // every asset resolves to the site root and the panel loads blank.
    baseURL: '/admin-panel/',

    head: {
      titleTemplate: '%s - VFI Admin',
      title: 'VFI Admin',

      link: [{
        rel: 'icon',
        type: 'image/png',
        href: '/assets/img/vfi-emblem.png',
      }],
    },
  },

  devtools: {
    // Off: the built output is committed and deployed, not run locally.
    enabled: false,
  },

  css: [
    '@core/scss/template/index.scss',
    '@styles/styles.scss',
    '@/plugins/iconify/icons.css',
    '@layouts/styles/index.scss',
  ],

  components: {
    dirs: [{
      path: '@/@core/components',
      pathPrefix: false,
    }, {
      path: '~/components/global',
      global: true,
    }, {
      path: '~/components',
      pathPrefix: false,
    }],
  },

  plugins: ['@/plugins/vuetify/index.js', '@/plugins/iconify/index.js'],

  imports: {
    dirs: ['./@core/utils', './@core/composable/', './plugins/*/composables/*'],
  },

  hooks: {},

  // Real HTML per route, so nginx's existing `try_files $uri $uri/` serves them
  // and no SPA-fallback rule is needed. Every route the panel has must be listed
  // here - an unlisted route 404s on a hard refresh.
  nitro: {
    prerender: {
      crawlLinks: false,
      failOnError: true,
      // Must match the pages that actually exist. failOnError above turns a
      // stale entry here into a build failure rather than a 404 discovered
      // later in production.
      routes: [
        '/',
        '/applications',
        // /content itself is only a redirect to /content/public, but it is
        // prerendered so an old link or a typed URL is not a 404.
        '/content',
        '/content/public',
        '/content/partner',
      ],
    },
  },

  experimental: {
    typedPages: true,
  },

  /*
    Do NOT inline stylesheets. Vuetify's CSS is ~1.4MB and Nuxt inlines it into
    every prerendered page by default, which made index.html 1.5MB. That is the
    wrong trade here: nginx serves *.html with Cache-Control no-cache (so the
    ?v= asset stamps are always read fresh) while /_nuxt/*.css is fingerprinted
    and cached for 7 days. Inlining forces the browser to re-download the whole
    stylesheet on every navigation; linking it means once.
  */
  features: {
    inlineStyles: false,
  },

  typescript: {
    tsConfig: {
      compilerOptions: {
        paths: {
          '@/*': ['../*'],
          '@layouts/*': ['../@layouts/*'],
          '@layouts': ['../@layouts'],
          '@core/*': ['../@core/*'],
          '@core': ['../@core'],
          '@images/*': ['../assets/images/*'],
          '@styles/*': ['../styles/*'],
        },
      },
    },
  },

  // ℹ️ Disable source maps until this is resolved: https://github.com/vuetifyjs/vuetify-loader/issues/290
  sourcemap: {
    server: false,
    client: false,
  },

  vue: {
    compilerOptions: {
      isCustomElement: tag => tag === 'swiper-container' || tag === 'swiper-slide',
    },
  },

  vite: {
    define: { 'process.env': {} },

    resolve: {
      alias: {
        '@': fileURLToPath(new URL('.', import.meta.url)),
        '@core': fileURLToPath(new URL('./@core', import.meta.url)),
        '@layouts': fileURLToPath(new URL('./@layouts', import.meta.url)),
        '@images': fileURLToPath(new URL('./assets/images/', import.meta.url)),
        '@styles': fileURLToPath(new URL('./assets/styles/', import.meta.url)),
        '@configured-variables': fileURLToPath(new URL('./assets/styles/variables/_template.scss', import.meta.url)),
      },
    },

    build: {
      chunkSizeWarningLimit: 5000,
    },

    optimizeDeps: {
      exclude: ['vuetify'],
      entries: [
        './**/*.vue',
      ],
    },

    plugins: [
      svgLoader(),
      vuetify({
        styles: {
          configFile: 'assets/styles/variables/_vuetify.scss',
        },
      }),
    ],
  },

  build: {
    transpile: ['vuetify'],
  },

  modules: ['@vueuse/nuxt', '@nuxtjs/device', '@pinia/nuxt'],
  compatibilityDate: '2025-01-01',
})