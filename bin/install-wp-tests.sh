#!/usr/bin/env bash
#
# Installs WordPress (and optionally WooCommerce) for the PHPUnit suite and
# writes the tests config from tests/wp-tests-config.php.dist.
#
# Required: WP_VERSION, WC_VERSION (a version, or "none").
# Optional: WP_CORE_DIR (.wp-core), TESTS_CONFIG (tests/wp-tests-config.php),
#           DB_NAME, DB_USER, DB_PASSWORD, DB_HOST (defaults of the .dist file),
#           FORCE=1 to overwrite an existing config.
#
# Usage: WP_VERSION=7.1.1 WC_VERSION=11.2.1 bin/install-wp-tests.sh

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$PLUGIN_DIR"

die() {
	echo "Error: $*" >&2
	exit 1
}

[ -n "${WP_VERSION:-}" ] || die "WP_VERSION is not set (e.g. WP_VERSION=7.1.1)."
[ -n "${WC_VERSION:-}" ] || die "WC_VERSION is not set (a WooCommerce version, or 'none')."

WP_CORE_DIR="${WP_CORE_DIR:-.wp-core}"
TESTS_CONFIG="${TESTS_CONFIG:-tests/wp-tests-config.php}"
DIST_FILE="tests/wp-tests-config.php.dist"

[ -f "$DIST_FILE" ] || die "$DIST_FILE not found."

# Resolve WP_CORE_DIR to an absolute path without creating it, then refuse to
# touch a real site: the core folders are removed below.
if [ -d "$WP_CORE_DIR" ]; then
	ABS_WP_CORE_DIR="$(cd "$WP_CORE_DIR" && pwd -P)"
else
	case "$WP_CORE_DIR" in
		/*) ABS_WP_CORE_DIR="$WP_CORE_DIR" ;;
		*) ABS_WP_CORE_DIR="$PLUGIN_DIR/$WP_CORE_DIR" ;;
	esac
fi
if [ "$ABS_WP_CORE_DIR" = "/" ]; then
	echo "Refusing to install into /: point WP_CORE_DIR at a dedicated test folder." >&2
	exit 1
fi
if [ -e "$ABS_WP_CORE_DIR/wp-config.php" ]; then
	echo "Refusing to install into $ABS_WP_CORE_DIR: it looks like a real WordPress site (wp-config.php found). Point WP_CORE_DIR at a dedicated test folder." >&2
	exit 1
fi

# WordPress also accepts wp-config.php one level above ABSPATH, unless that
# folder is itself a WordPress install (wp-settings.php): WordPress's own rule.
if [ -e "$ABS_WP_CORE_DIR/../wp-config.php" ] && [ ! -e "$ABS_WP_CORE_DIR/../wp-settings.php" ]; then
	echo "Refusing to install into $ABS_WP_CORE_DIR: it looks like part of a real WordPress site (../wp-config.php found). Point WP_CORE_DIR at a dedicated test folder." >&2
	exit 1
fi

# Download a URL to a file, failing with a clear message on HTTP errors.
download() {
	local url="$1" dest="$2"
	curl --silent --show-error --fail --location --retry 3 "$url" --output "$dest" \
		|| die "Download failed: $url"
}

# Escape a value for a single-quoted PHP string, then for a sed replacement.
php_sed_escape() {
	printf '%s' "$1" | sed -e "s/\\\\/\\\\\\\\/g" -e "s/'/\\\\'/g" \
		| sed -e 's/[\\&|]/\\&/g'
}

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# 1. WordPress.
mkdir -p "$WP_CORE_DIR"
if [ -f "$WP_CORE_DIR/wp-includes/version.php" ]; then
	echo "WordPress already present in $WP_CORE_DIR, replacing wp-admin and wp-includes with $WP_VERSION (wp-content is kept)."
fi
download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" "$TMP_DIR/wordpress.tar.gz"
tar -xzf "$TMP_DIR/wordpress.tar.gz" -C "$TMP_DIR"
[ -d "$TMP_DIR/wordpress" ] || die "Unexpected WordPress archive layout."
# Remove the old core directories first so files dropped by the new version do not linger.
rm -rf "$WP_CORE_DIR/wp-admin" "$WP_CORE_DIR/wp-includes"
cp -R "$TMP_DIR/wordpress/." "$WP_CORE_DIR/"

# 2. WooCommerce.
if [ "$WC_VERSION" != "none" ]; then
	command -v unzip >/dev/null 2>&1 || die "unzip is required to install WooCommerce."
	mkdir -p "$WP_CORE_DIR/wp-content/plugins"
	download "https://downloads.wordpress.org/plugin/woocommerce.$WC_VERSION.zip" "$TMP_DIR/woocommerce.zip"
	rm -rf "$WP_CORE_DIR/wp-content/plugins/woocommerce"
	unzip -q -o "$TMP_DIR/woocommerce.zip" -d "$WP_CORE_DIR/wp-content/plugins/"
else
	# The test bootstrap loads WooCommerce whenever the file exists, so an earlier
	# install would turn a "no WooCommerce" run into a false green.
	rm -rf "$WP_CORE_DIR/wp-content/plugins/woocommerce"
fi

# 3. Tests config.
ABS_CORE_DIR="$(cd "$WP_CORE_DIR" && pwd -P)"
if [ -e "$TESTS_CONFIG" ] && [ "${FORCE:-}" != "1" ]; then
	echo "Config $TESTS_CONFIG already exists, left untouched (set FORCE=1 to overwrite)."
else
	mkdir -p "$(dirname "$TESTS_CONFIG")"
	sed_args=(
		-e "s|^define( 'ABSPATH',.*|define( 'ABSPATH', '$(php_sed_escape "$ABS_CORE_DIR")/' );|"
		-e "s|^\\(define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH',\\).*|\\1 '$(php_sed_escape "$PLUGIN_DIR")/vendor/yoast/phpunit-polyfills' );|"
	)
	for name in DB_NAME DB_USER DB_PASSWORD DB_HOST; do
		if [ -n "${!name+x}" ]; then
			sed_args+=( -e "s|^define( '$name',.*|define( '$name', '$(php_sed_escape "${!name}")' );|" )
		fi
	done
	sed "${sed_args[@]}" "$DIST_FILE" > "$TESTS_CONFIG"
	echo "Wrote $TESTS_CONFIG."
fi

# 4. Report.
WP_INSTALLED="$(sed -n "s/^\\\$wp_version = '\\(.*\\)';/\\1/p" "$WP_CORE_DIR/wp-includes/version.php")"
echo "WordPress: ${WP_INSTALLED:-unknown} ($ABS_CORE_DIR)"
if [ "$WC_VERSION" != "none" ]; then
	WC_INSTALLED="$(sed -n 's/^ \* Version: *//p' "$WP_CORE_DIR/wp-content/plugins/woocommerce/woocommerce.php" | head -n 1)"
	echo "WooCommerce: ${WC_INSTALLED:-unknown}"
else
	if [ -f "$WP_CORE_DIR/wp-content/plugins/woocommerce/woocommerce.php" ]; then
		die "WooCommerce is still present in $WP_CORE_DIR/wp-content/plugins/woocommerce."
	fi
	echo "WooCommerce: not installed"
fi
