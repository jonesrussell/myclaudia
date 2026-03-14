<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class PublicAccountDeployValidationScript
{
    public function build(string $baseUrl): string
    {
        $normalizedBaseUrl = rtrim($baseUrl, '/');

        return strtr(<<<'BASH'
homepage_file=$(mktemp)
signup_form_file=$(mktemp)
signup_probe_file=$(mktemp)
login_form_file=$(mktemp)
login_probe_file=$(mktemp)
app_entry_headers=$(mktemp)
signup_cookie_jar=$(mktemp)
login_cookie_jar=$(mktemp)
trap 'rm -f "$homepage_file" "$signup_form_file" "$signup_probe_file" "$login_form_file" "$login_probe_file" "$app_entry_headers" "$signup_cookie_jar" "$login_cookie_jar"' EXIT

curl --silent --show-error --fail \
  __BASE_URL__/ > "$homepage_file"

grep -q 'Create your account' "$homepage_file"
grep -q 'href="/signup"' "$homepage_file"
grep -q 'href="/login"' "$homepage_file"

curl --silent --show-error \
  --output /dev/null \
  --dump-header "$app_entry_headers" \
  __BASE_URL__/app

grep -q '302' "$app_entry_headers"
grep -q 'Location: /login' "$app_entry_headers"

curl --silent --show-error --fail \
  --cookie-jar "$signup_cookie_jar" \
  __BASE_URL__/signup > "$signup_form_file"

grep -q 'Create Your Claudriel Account' "$signup_form_file"

signup_token=$(php -r '$html = file_get_contents($argv[1]); if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $matches)) { fwrite(STDERR, "Missing signup CSRF token\n"); exit(1);} echo $matches[1];' "$signup_form_file")

signup_status=$(curl --silent --show-error \
  --cookie "$signup_cookie_jar" \
  --output "$signup_probe_file" \
  --write-out '%{http_code}' \
  --data-urlencode "_csrf_token=${signup_token}" \
  --data-urlencode "name=" \
  --data-urlencode "email=" \
  --data-urlencode "password=" \
  __BASE_URL__/signup)

test "$signup_status" = "422"
grep -q 'Name, email, and password are required.' "$signup_probe_file"

curl --silent --show-error --fail \
  --cookie-jar "$login_cookie_jar" \
  __BASE_URL__/login > "$login_form_file"

grep -q 'Log in to Claudriel' "$login_form_file"

login_token=$(php -r '$html = file_get_contents($argv[1]); if (!preg_match("/name=\"_csrf_token\" value=\"([^\"]+)\"/", $html, $matches)) { fwrite(STDERR, "Missing login CSRF token\n"); exit(1);} echo $matches[1];' "$login_form_file")

login_status=$(curl --silent --show-error \
  --cookie "$login_cookie_jar" \
  --output "$login_probe_file" \
  --write-out '%{http_code}' \
  --data-urlencode "_csrf_token=${login_token}" \
  --data-urlencode "email=deploy-validation@example.com" \
  --data-urlencode "password=invalid-password" \
  __BASE_URL__/login)

test "$login_status" = "401"
grep -q 'Invalid credentials.' "$login_probe_file"
BASH, ['__BASE_URL__' => $normalizedBaseUrl]);
    }
}
