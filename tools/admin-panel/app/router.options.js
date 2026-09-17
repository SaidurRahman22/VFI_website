/*
  The template shipped a synthetic '/' route that redirected to '/dashboard'.
  The dashboard here IS '/' (pages/index.vue), so that redirect pointed at a
  page that no longer exists and turned the panel's entry point into a
  meta-refresh to a 404.

  Nothing custom is needed now: Nuxt scans pages/ and index.vue owns '/'.
  Kept as a file rather than deleted so the next person sees why.
*/
export default {}
