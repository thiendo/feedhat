#!/usr/bin/env bash
#
# Builds the wordpress.org listing zip: core + free targeting only.
# Never copies includes/pro/ or vendor/freemius/. Never copies .po/.mo
# translation files (translate.wordpress.org provides those).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
SLUG="feedhat"
STAGE_DIR="${DIST_DIR}/${SLUG}"
ZIP_PATH="${DIST_DIR}/${SLUG}.zip"

require_path() {
	if [ ! -e "$1" ]; then
		echo "ERROR: expected path not found: $1" >&2
		exit 1
	fi
}

require_path "${ROOT_DIR}/feedhat.php"
require_path "${ROOT_DIR}/uninstall.php"
require_path "${ROOT_DIR}/readme.txt"
require_path "${ROOT_DIR}/includes/core"
require_path "${ROOT_DIR}/includes/free"
require_path "${ROOT_DIR}/assets"

rm -rf "${STAGE_DIR}" "${ZIP_PATH}"
mkdir -p "${STAGE_DIR}/includes"

cp "${ROOT_DIR}/feedhat.php" "${STAGE_DIR}/"
cp "${ROOT_DIR}/uninstall.php" "${STAGE_DIR}/"
cp "${ROOT_DIR}/readme.txt" "${STAGE_DIR}/"

cp -R "${ROOT_DIR}/includes/core" "${STAGE_DIR}/includes/"
cp -R "${ROOT_DIR}/includes/free" "${STAGE_DIR}/includes/"
cp -R "${ROOT_DIR}/assets" "${STAGE_DIR}/"

# Pro-only front-end/admin assets — live in the Freemius-sold package only.
rm -f "${STAGE_DIR}/assets/css/kanban.css"
rm -f "${STAGE_DIR}/assets/js/kanban.js"
rm -f "${STAGE_DIR}/assets/js/note-annotator.js"

if [ -f "${ROOT_DIR}/languages/feedhat.pot" ]; then
	mkdir -p "${STAGE_DIR}/languages"
	cp "${ROOT_DIR}/languages/feedhat.pot" "${STAGE_DIR}/languages/"
fi

find "${STAGE_DIR}" \( -name '.DS_Store' -o -name '.gitkeep' \) -delete

if find "${STAGE_DIR}" -path '*includes/pro*' 2>/dev/null | grep -q .; then
	echo "ERROR: Pro-only files detected in the WordPress.org package. Aborting." >&2
	exit 1
fi

if find "${STAGE_DIR}" -path '*vendor/freemius*' 2>/dev/null | grep -q .; then
	echo "ERROR: Freemius SDK detected in the WordPress.org package. Aborting." >&2
	exit 1
fi

if grep -R --include='*.php' -E 'fs_dynamic_init|load_plugin_textdomain' "${STAGE_DIR}" | grep -q .; then
	echo "ERROR: Freemius init or load_plugin_textdomain found in the WordPress.org package. Aborting." >&2
	grep -R --include='*.php' -E 'fs_dynamic_init|load_plugin_textdomain' "${STAGE_DIR}" >&2 || true
	exit 1
fi

if find "${STAGE_DIR}" \( -name '*.po' -o -name '*.mo' \) | grep -q .; then
	echo "ERROR: Bundled translation files found in the WordPress.org package. Aborting." >&2
	exit 1
fi

if find "${STAGE_DIR}" \( -name 'kanban.js' -o -name 'kanban.css' -o -name 'note-annotator.js' \) | grep -q .; then
	echo "ERROR: Pro-only assets detected in the WordPress.org package. Aborting." >&2
	exit 1
fi

mkdir -p "${DIST_DIR}"
( cd "${DIST_DIR}" && zip -rq "${SLUG}.zip" "${SLUG}" )

echo ""
echo "Built ${ZIP_PATH}"
echo ""
echo "Packaged files:"
( cd "${STAGE_DIR}" && find . -type f | sort )
