/*
  The VFI palette, as the console sees it.

  Every value below is either a token from the public site (css/style.css :root
  and css/admin.css :root) or a shade derived from one by mixing it with black
  or white. Nothing is invented, and the derivations exist for one reason:
  Materio's stock slots do not survive a contrast check, and three of the site's
  own tokens do not either when they have to carry text.

  Measured contrast of every on-* pair against its own background, in this file,
  is recorded beside it. WCAG AA for body text is 4.5:1.

  The two slots that are derived rather than lifted straight from the site:
    success  --green #12a06a is 3.35:1 under white and is also used as small
             text (`text-success`), where it needs 4.5:1 against the card. The
             green mixed 25% with black clears both at 5.50:1.
    error    --red #e04b3c cannot carry readable text at all: 4.01:1 under white
             and 4.28:1 under the ink. Mixed 20% with black it reaches 5.82:1.
             The pure #e04b3c is used in the dark theme instead, where a light
             red is what a dark ground wants.

  --gold #f5a623 is kept exactly, because a gold dark enough for white text is
  no longer gold; it carries the ink instead, at 8.47:1. Note that a *tonal*
  warning chip paints the gold as text on a 16% gold tint, which measures
  1.81:1 - no choice of gold fixes that, and it would take a per-chip text
  colour to solve. Every other tonal chip lands between 4.37:1 and 5.64:1.
*/

// The brand blue, kept as named exports because the template's own tooling
// expects them. The dark theme carries its own, lighter pair.
export const staticPrimaryColor = '#2f62a8'
export const staticPrimaryDarkenColor = '#234f88'

export const themes = {
  light: {
    dark: false,
    colors: {
      'primary': staticPrimaryColor,                // --blue, the logo royal blue
      'on-primary': '#fff',                         // 6.12:1
      'primary-darken-1': staticPrimaryDarkenColor, // --blue-dark
      'secondary': '#b81e4e',                       // --coral, the logo crimson
      'secondary-darken-1': '#951245',              // --coral-dark
      'on-secondary': '#fff',                       // 6.30:1
      'success': '#0e7850',                         // --green, 25% black
      'success-darken-1': '#0c6a46',
      'on-success': '#fff',                         // 5.50:1
      'info': '#5b4a9e',                            // --hero-2, the hero gradient's violet end
      'info-darken-1': '#50418b',
      'on-info': '#fff',                            // 7.20:1
      'warning': '#f5a623',                         // --gold
      'warning-darken-1': '#d8921f',
      'on-warning': '#141c26',                      // --text, 8.47:1
      'error': '#b33c30',                           // --red, 20% black
      'error-darken-1': '#a8382d',
      'on-error': '#fff',                           // 5.82:1
      'background': '#f6f7fb',                      // --bg-soft
      'on-background': '#141c26',                   // --text, 16.03:1
      'surface': '#fff',
      'on-surface': '#141c26',                      // 17.16:1

      /*
        A navy-tinted neutral ramp instead of Materio's pure greys, so a divider
        or a placeholder sits in the same family as the rest of the screen. The
        first three steps are the site's --bg-soft, --line-2 and --line; the last
        is --text; the middle is interpolated between them.
      */
      'grey-50': '#f6f7fb',
      'grey-100': '#eef0f6',
      'grey-200': '#e7e9f0',
      'grey-300': '#d7dae4',
      'grey-400': '#b6bbc9',
      'grey-500': '#9a9fb0',
      'grey-600': '#767c8f',
      'grey-700': '#5b6172',
      'grey-800': '#3a4356',
      'grey-900': '#141c26',

      'perfect-scrollbar-thumb': '#c7ccd9',
      'skin-bordered-background': '#fff',
      'skin-bordered-surface': '#fff',
      'expansion-panel-text-custom-bg': '#f6f7fb',
      'track-bg': '#eef0f6',
      'chat-bg': '#f6f7fb',

      /*
        Not a Vuetify slot: the far end of the active sidebar item's gradient,
        read by assets/styles/styles.scss. The old console ran that item from
        --blue to --violet and it is the one place the brand's blue-to-violet
        sweep belongs in a console this dense.
      */
      'nav-active-end': '#643976', // --violet
    },
    variables: {
      'code-color': '#b81e4e',                 // --coral, 6.30:1 on the card
      'overlay-scrim-background': '#152440',   // --navy, so dialogs dim toward navy not aubergine
      'tooltip-background': '#152440',         // white text on it, 15.46:1
      'overlay-scrim-opacity': 0.5,
      'hover-opacity': 0.04,
      'focus-opacity': 0.1,
      'selected-opacity': 0.08,
      'activated-opacity': 0.16,
      'pressed-opacity': 0.14,
      'dragged-opacity': 0.1,
      'disabled-opacity': 0.4,

      // --navy at 12% over white lands on #e3e5e8, a shade off the site's own
      // --line #e7e9f0, which is how the site draws its hairlines.
      'border-color': '#152440',
      'border-opacity': 0.12,
      'table-header-color': '#f6f7fb',
      'high-emphasis-opacity': 0.9,
      'medium-emphasis-opacity': 0.7,

      /*
        👉 shadows

        The umbra colour is the site's own shadow ink, rgba(14,27,44,…), and
        these opacities are the site's: --shadow-sm is .06, --shadow is .08 and
        --shadow-lg is .14. The blur radii that go with them are in
        assets/styles/variables/_vuetify.scss, which is where the elevation ramp
        lives; the two halves have to be read together.
      */
      'shadow-key-umbra-color': '#0e1b2c',
      'shadow-xs-opacity': '0.05',
      'shadow-sm-opacity': '0.06',
      'shadow-md-opacity': '0.08',
      'shadow-lg-opacity': '0.10',
      'shadow-xl-opacity': '0.14',
    },
  },
  dark: {
    dark: true,
    colors: {
      /*
        The dark theme is not the light one on a dark background. Every brand
        hue is lifted 30% toward white so it is legible AS TEXT on navy - a link
        or a tonal chip in the light blue would measure 2.53:1 on #152440 - and
        the on-* colour becomes the site's --ink instead of white. That is one
        consistent rule across all six slots, so nothing looks like an exception.

        --gold and --red are already light enough to stay exactly as the site
        writes them.
      */
      'primary': '#6d91c2',              // --blue, 30% white
      'on-primary': '#0a0217',           // --ink, 6.27:1
      'primary-darken-1': '#5981b9',     // --blue, 20% white
      'secondary': '#cd6283',            // --coral, 30% white
      'secondary-darken-1': '#c64b71',
      'on-secondary': '#0a0217',         // 5.48:1
      'success': '#59bd97',              // --green, 30% white
      'success-darken-1': '#41b388',
      'on-success': '#0a0217',           // 8.85:1
      /*
        These two are lifted further than the "30% white" rule would give,
        because a semantic colour here has to pass AA TWICE. Vuetify's tonal
        variant paints the colour as TEXT over a 16% tint of itself, so the
        colour is read against the surface as well as against its own on-* pair.
        At --hero-2 + 30% white info measured 4.36:1 on #152440 and --red
        unchanged measured 3.86:1 — so every tonal info and tonal error alert in
        the console was below 4.5:1 in dark mode, which on the backup screen is
        the sentence telling someone their site is about to be replaced.
        Measured, not estimated: see the ratios on each line.
      */
      'info': '#8f84bd',                 // --hero-2, 32% white — 4.57:1 on surface
      'info-darken-1': '#7f75a8',
      'on-info': '#0a0217',              // 6.00:1
      'warning': '#f5a623',              // --gold, unchanged — 7.63:1, already clear
      'warning-darken-1': '#e19920',
      'on-warning': '#0a0217',           // 10.02:1
      'error': '#e46153',                // --red, 12% white — 4.52:1 on surface
      'error-darken-1': '#cb564a',
      'on-error': '#0a0217',             // 5.93:1

      // --navy is the raised surface and a darker navy is the ground behind it,
      // the same way the site's own sidebar sits on its page.
      'background': '#0e172a',           // --navy, 35% black
      'on-background': '#dde4f0',        // 13.99:1
      'surface': '#152440',              // --navy
      'on-surface': '#dde4f0',           // 12.10:1

      'grey-50': '#141f36',
      'grey-100': '#1b2842',
      'grey-200': '#2a3a58',
      'grey-300': '#3b4d6d',
      'grey-400': '#566b8c',
      'grey-500': '#7286a5',
      'grey-600': '#98a9c2',
      'grey-700': '#b7c4d8',
      'grey-800': '#d3dcec',
      'grey-900': '#e8edf6',

      'perfect-scrollbar-thumb': '#3b4d6d',
      'skin-bordered-background': '#152440',
      'skin-bordered-surface': '#152440',
      'expansion-panel-text-custom-bg': '#1b2842',
      'track-bg': '#2a3a58',
      'chat-bg': '#111c33',

      'nav-active-end': '#9a7ea6', // --violet, 35% white, to pair with the lifted primary
    },
    variables: {
      'code-color': '#dc8fa7',                 // --coral, 50% white, 6.28:1 on the surface
      'overlay-scrim-background': '#0a0217',   // --ink
      'tooltip-background': '#eef0f6',         // --line-2; the tooltip's text is the navy surface, 13.57:1
      'overlay-scrim-opacity': 0.5,
      'hover-opacity': 0.04,
      'focus-opacity': 0.1,
      'selected-opacity': 0.08,
      'activated-opacity': 0.16,
      'pressed-opacity': 0.14,
      'disabled-opacity': 0.4,
      'dragged-opacity': 0.1,
      'border-color': '#dde4f0',
      'border-opacity': 0.12,
      'table-header-color': '#1b2842',
      'high-emphasis-opacity': 0.9,
      'medium-emphasis-opacity': 0.7,

      // Held higher than the light theme's: the same soft, wide blur needs more
      // alpha to register at all against navy.
      'shadow-key-umbra-color': '#05080f',
      'shadow-xs-opacity': '0.24',
      'shadow-sm-opacity': '0.28',
      'shadow-md-opacity': '0.34',
      'shadow-lg-opacity': '0.40',
      'shadow-xl-opacity': '0.48',
    },
  },
}
export default themes
