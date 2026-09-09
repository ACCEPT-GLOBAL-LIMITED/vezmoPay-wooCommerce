#!/usr/bin/env bash
# One release step, four version strings.
#
# They drifted before: the plugin header, VEZMOPAY_WC_VERSION and readme.txt
# said 0.2.19 while README.md and the .pot still said 0.1.0 — the .pot version
# is what translators see, and the README badge is what everyone else does.
#
# Usage: scripts/set-version.sh 0.3.0
set -euo pipefail

version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "usage: $0 <x.y.z>" >&2
	exit 1
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

# 1. Plugin header + runtime constant.
perl -pi -e "s/^( \* Version:\s+).*/\${1}$version/" vezmopay-woocommerce.php
perl -pi -e "s/(define\( 'VEZMOPAY_WC_VERSION', ')[^']+('\ \);)/\${1}$version\${2}/" vezmopay-woocommerce.php

# 2. readme.txt stable tag.
perl -pi -e "s/^(Stable tag: ).*/\${1}$version/" readme.txt

# 3. README.md badge + footer.
perl -pi -e "s{badge/version-[0-9.]+-blue\.svg}{badge/version-$version-blue.svg}" README.md
perl -pi -e "s/(\*\*VezmoPay for WooCommerce\*\* · v)[0-9.]+/\${1}$version/" README.md

# 4. Translation template (regenerated, so its Project-Id-Version follows the
#    plugin header above; requires wp-cli).
if command -v wp >/dev/null 2>&1; then
	wp i18n make-pot . languages/vezmopay-woocommerce.pot \
		--domain=vezmopay-woocommerce \
		--exclude=docs \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/ACCEPT-GLOBAL-LIMITED/vezmoPay-wooCommerce/issues"}'
else
	echo "note: wp-cli not found — regenerate languages/vezmopay-woocommerce.pot before tagging." >&2
fi

echo "Version set to $version. Verify:"
grep -n "Version:\|VEZMOPAY_WC_VERSION" vezmopay-woocommerce.php | head -2
grep -n "^Stable tag:" readme.txt
grep -n "badge/version-" README.md
grep -n "^\"Project-Id-Version" languages/vezmopay-woocommerce.pot
