#!/usr/bin/env bash
#
# One-shot hardening for the live server. Run it ONCE, as root:
#
#     sudo bash /var/www/vfi/deploy/harden-auth.sh
#
# Written as a single reviewable script on purpose. The alternative was a
# standing NOPASSWD sudo rule for the deploy account, which is a permanent
# widening of privilege to save a few minutes - this is one command you can read
# first and it leaves no standing grant behind.
#
# It is idempotent: run it twice and the second run changes nothing. It backs up
# .env before touching it, prints every before/after value, and prints NO
# secrets.
#
# WHAT IT DOES NOT DO, deliberately:
#   * It does not configure mail. That needs a real credential (a Postmark
#     server token, or SMTP host/port/user/pass) which nobody has supplied yet.
#   * It does not enable ADMIN_REQUIRE_TOTP. Turning that on without the
#     enrolled authenticator for superadmin@vfi-fc.com locks you out of /manage
#     entirely, and that question has not been answered. Do it separately, after
#     confirming you can generate a current 6-digit code.
#   * It does not touch the SSH password or sshd_config. See the notes at the
#     bottom - those are decisions, not chores.
# =============================================================================
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "This must run as root: sudo bash $0" >&2
  exit 1
fi

APP=/var/www/vfi
ENVF="$APP/backend/.env"
STAMP="$(date +%Y%m%d-%H%M%S)"

say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

# ---------------------------------------------------------------------------
say "0. Checks"
for f in "$ENVF" "$APP/deploy/logrotate/vfi" "$APP/deploy/cron/vfi"; do
  [ -f "$f" ] || { echo "missing: $f" >&2; exit 1; }
done
echo "app at $APP, .env present, deploy configs present"

# ---------------------------------------------------------------------------
# .env edits. `set_env KEY VALUE` replaces the line if the key exists (even
# commented out) and appends it otherwise, so it works whatever state the file
# is in. Values here contain no shell metacharacters; they are still passed
# through sed with a delimiter that cannot appear in them.
# ---------------------------------------------------------------------------
set_env() {
  local key="$1" val="$2"
  if grep -qE "^[#[:space:]]*${key}=" "$ENVF"; then
    sed -i -E "s|^[#[:space:]]*${key}=.*|${key}=${val}|" "$ENVF"
  else
    printf '%s=%s\n' "$key" "$val" >> "$ENVF"
  fi
}
show_env() {  # prints a key's value, or SET/EMPTY for anything sensitive
  local key="$1" v
  v="$(grep -E "^${key}=" "$ENVF" | head -1 | cut -d= -f2- || true)"
  case "$key" in
    *OTP*|*PASSWORD*|*TOKEN*|*SECRET*|*KEY*)
      printf '  %-22s %s\n' "$key" "$([ -z "$v" ] && echo 'EMPTY' || echo 'SET')" ;;
    *) printf '  %-22s %s\n' "$key" "${v:-(unset)}" ;;
  esac
}

say "1. .env before"
cp -a "$ENVF" "$ENVF.bak-$STAMP"
echo "backup: $ENVF.bak-$STAMP"
for k in AUTH_DEMO_OTP SESSION_LIFETIME ADMIN_REQUIRE_TOTP MAIL_MAILER; do show_env "$k"; done

say "2. Clear the demo OTP bypass"
cat <<'WHY'
  AUTH_DEMO_OTP makes OtpService accept one fixed 6-digit code instead of the
  emailed one. It gates NEW-ACCOUNT EMAIL VERIFICATION only - admin, student and
  partner sign-in are all password-only, and password reset uses an emailed
  token link, so clearing this logs nobody out and cannot lock anyone out.
  (Verified against the live server: /api/login, /api/partner/signin and the
  admin login all return 200 with a password alone.)

  What it costs: a genuine new registration cannot complete verification until
  mail works. That is already true - the real code is generated and then
  delivered nowhere, because MAIL_MAILER=log and LOG_LEVEL=warning means even
  the log transport records nothing. So registration only works today for
  someone who knows the secret code.

  What it stops: anyone registering an address they do not own - a rival's, a
  university's admissions inbox - and verifying it with that fixed code.
WHY
set_env AUTH_DEMO_OTP ""

say "3. Shorten the session lifetime"
echo "  43200 minutes is 30 days. 480 is 8 hours: one working day, so nobody is"
echo "  logged out mid-task, while a stolen session cookie stops being useful in"
echo "  hours rather than a month. Change the number here if you want tighter."
set_env SESSION_LIFETIME 480

say "4. .env after"
for k in AUTH_DEMO_OTP SESSION_LIFETIME ADMIN_REQUIRE_TOTP MAIL_MAILER; do show_env "$k"; done

# ---------------------------------------------------------------------------
say "5. Log rotation"
install -o root -g root -m 644 "$APP/deploy/logrotate/vfi" /etc/logrotate.d/vfi
echo "installed /etc/logrotate.d/vfi"
echo "  dry run (must show three 'considering log' lines and NO 'insecure permissions'):"
logrotate -d /etc/logrotate.d/vfi 2>&1 | grep -iE "considering log|skipping|insecure" | sed 's/^/    /' || true

# ---------------------------------------------------------------------------
say "6. One scheduler instead of two"
echo "  current /etc/cron.d/vfi:"
grep -vE '^\s*(#|$)' /etc/cron.d/vfi 2>/dev/null | sed 's/^/    /' || echo "    (none)"
echo "  www-data's personal crontab (the duplicate), shown BEFORE removing it:"
crontab -u www-data -l 2>/dev/null | sed 's/^/    /' || echo "    (none)"
cat <<'WHY'
  The scheduler has been running TWICE a minute: once from /etc/cron.d/vfi with
  its output discarded, once from www-data's own crontab. Every task uses
  withoutOverlapping(), which only blocks a tick while the previous one is still
  RUNNING - anything finishing inside the minute simply ran again. For the two
  that matter that means programs:ingest twice an hour and
  documents:purge-expired deleting 1,000 files a night where the schedule says
  500.
WHY
install -o root -g root -m 644 "$APP/deploy/cron/vfi" /etc/cron.d/vfi
echo "installed /etc/cron.d/vfi"
if crontab -u www-data -l >/dev/null 2>&1; then
  crontab -u www-data -l > "/root/www-data-crontab.bak-$STAMP" 2>/dev/null || true
  echo "saved a copy to /root/www-data-crontab.bak-$STAMP before removing it"
  crontab -u www-data -r
  echo "removed www-data's crontab"
else
  echo "www-data has no crontab - nothing to remove"
fi
systemctl restart cron
echo "cron restarted"

# ---------------------------------------------------------------------------
say "7. Make the config changes take effect"
# The app runs on a cached config, so .env edits do nothing until it is rebuilt.
cd "$APP/backend"
sudo -u www-data php artisan config:clear >/dev/null
sudo -u www-data php artisan config:cache >/dev/null
echo "config cache rebuilt"
sudo -u www-data php -r '
  $c = require "bootstrap/cache/config.php";
  printf("  auth.demo_otp           : %s\n", ($c["auth"]["demo_otp"] ?? "") === "" ? "EMPTY (bypass off)" : "STILL SET");
  printf("  session.lifetime (mins) : %s\n", $c["session"]["lifetime"] ?? "?");
  printf("  auth.admin_require_totp : %s\n", var_export($c["auth"]["admin_require_totp"] ?? null, true));
  printf("  mail.default            : %s\n", $c["mail"]["default"] ?? "?");
'

say "8. Confirm the site still serves"
for u in / /admin-panel/ /api/universities/meta; do
  printf '  %-26s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: 103.14.23.151' "http://127.0.0.1$u")"
done

say "Done"
cat <<'NEXT'
Still outstanding, and each needs a decision rather than a command:

  1. MAIL. Nothing can send email until MAIL_MAILER is a real transport. Give
     either a Postmark server token (the app already reads POSTMARK_TOKEN and
     wires services.postmark.token) or SMTP host/port/username/password, plus a
     From address on a domain you control. Without SPF and DKIM on that domain
     the mail will be delivered to spam, which fails the same way as not
     sending it. Until this is done, NOBODY CAN REGISTER OR RESET A PASSWORD.

  2. ADMIN 2FA. ADMIN_REQUIRE_TOTP is false, so /manage is password-only. Turn
     it on ONLY after confirming you can produce a current code for
     superadmin@vfi-fc.com - otherwise you lock yourself out:
         ADMIN_REQUIRE_TOTP=true   then re-run step 7 of this script

  3. HTTPS. There is no TLS, so the admin password and every session cookie
     cross the network in cleartext. This outranks 1 and 2: two-factor auth and
     OTP hardening protect a door whose key is readable in transit. It needs a
     domain pointed at this server, then certbot.

  4. THE SSH PASSWORD is still the one published in the repo's git history.
     Change it (`passwd` as vfi), and once key login is confirmed working,
     consider PasswordAuthentication no in /etc/ssh/sshd_config. Note
     PermitRootLogin is currently yes.
NEXT
